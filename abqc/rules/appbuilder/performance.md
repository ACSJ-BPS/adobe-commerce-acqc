# App Builder — Performance

> Part of [App Builder](./README.md).

## Fan out large workloads — split into parallel sub-actions [warn] [perf]

I/O Runtime is built for high-fan-out parallelism. A 1000-item batch processed serially in one action will time out; the same 1000 items dispatched as 1000 individual triggers process in seconds. Use `Promise.all` for IO-bound parallelism and OpenWhisk triggers for cross-action fan-out.

### Check
```yaml
type: builtin
id: serial-await-in-loop
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
for (const id of params.orderIds) await syncOrder(id)
```

### Good
```js
await Promise.all(params.orderIds.map(id =>
  openwhisk().actions.invoke({ name: 'sync-single-order', params: { id }, blocking: false })
))
```

---

## Defer all I/O and heavy imports out of module scope to avoid cold-start latency [warn] [performance, cold-start]

I/O Runtime creates a new container (cold start) whenever no warm container is available. Synchronous I/O, large `require` chains, or blocking setup at module load time adds latency directly before `main()` is called. Keep the module-load path lean — only configure constants and set up the logger. Defer all network calls, file reads, and optional SDK initializations into `main()` or into a lazy-singleton pattern. Warm containers reuse the already-initialized singleton on subsequent invocations, so this pattern is both a correctness and performance win.

### Check
```yaml
type: builtin
id: no-module-scope-io
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
const fs = require('node:fs')

// runs at module load on every cold start — not on first invocation
const config = JSON.parse(fs.readFileSync('./config.json', 'utf8'))
const client = new HeavySDKClient(config.apiKey)  // connects on import

async function main (params) {
  return client.query(params.id)
}
```

### Good
```js
let _client = null

function getClient (apiKey) {
  if (!_client) _client = new HeavySDKClient(apiKey)  // lazy — reused on warm containers
  return _client
}

async function main (params) {
  const client = getClient(params.API_KEY)
  return client.query(params.id)
}
```

---

## Cap concurrency on aio-lib-state writes and plan IOEXT quota increases for burst workloads [warn] [state, performance, quotas]

`aio-lib-state` defaults to a **1 GB storage cap** and **1 MB per value**, but burst read/write bandwidth is also rate-limited at the namespace level. Applications that fan out parallel writes — bulk event ingestion, concurrent action invocations — can hit bandwidth throttling even when well under storage quota. Identify heavy-state workflows during design, estimate peak throughput, and file an IOEXT quota-increase request before going to production. For high-throughput workloads, consider whether `aio-lib-files` (object storage, 1 TB cap, no per-request rate limits) is a better fit than State.

### Check
```yaml
type: builtin
id: state-write-concurrency
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
// 200 concurrent actions each writing to aio-lib-state with no throttle
await Promise.all(events.map(ev => stateLib.put(ev.id, ev.payload)))
// burst bandwidth limit silently drops writes under load
```

### Good
```js
import pMap from 'p-map'

// cap concurrency; file IOEXT request before enabling at scale
await pMap(events, ev => stateLib.put(ev.id, ev.payload), { concurrency: 10 })
// for bulk-write workloads, benchmark aio-lib-files as primary store
```

---

## Pagination loops need an explicit max-pages safety limit [error] [performance, reliability]

A naive `while (hasNext)` paging loop will run until either the upstream returns no more data or the action hits the 60s cap and dies mid-page. Always cap the loop with an explicit max iteration count, and persist a checkpoint so a follow-up invocation can resume.

### Check
```yaml
type: builtin
id: pagination-max-pages
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
while (response.has_next) {
  response = await fetch(`/orders?cursor=${response.cursor}`);
  results.push(...response.items);
}
```

### Good
```js
const MAX_PAGES = 50;
let pages = 0;
while (response.has_next && pages++ < MAX_PAGES) {
  response = await fetch(`/orders?cursor=${response.cursor}`);
  results.push(...response.items);
}
if (response.has_next) {
  await state.put('orders:cursor', response.cursor); // resume on next invocation
}
```

---

## Chunk large jobs across invocations with State checkpoints [warn] [performance, batch]

Bulk jobs (full Commerce export, AEP backfill) routinely exceed the 60s cap when run as a single invocation. Split the work: each invocation processes one chunk, writes a "last processed ID" checkpoint to State, and either re-invokes itself or returns "more pending" to the caller.

### Check
```yaml
type: builtin
id: chunk-checkpoints
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
// one-shot full export — dies at 60s mark
exports.main = async () => {
  const all = await fetchAllOrders();
  return { body: all };
};
```

### Good
```js
exports.main = async ({ chunkSize = 500 }) => {
  const lastId = (await state.get('export:lastId')) ?? 0;
  const batch = await fetchOrdersAfter(lastId, chunkSize);
  if (batch.length === 0) return { body: { done: true } };
  await processChunk(batch);
  await state.put('export:lastId', batch[batch.length - 1].id);
  await runtime.invoke('export-orders'); // re-invoke for next chunk
  return { body: { done: false, processed: batch.length } };
};
```

---

## For bulk exports, write to Files SDK and return a URL — don't return large JSON [warn] [performance, files]

Returning a 50 MB JSON response from an action burns memory, blows past the action response size limit, and forces clients to hold the whole payload in memory. Stream the result into Files SDK and return a presigned URL.

### Check
```yaml
type: builtin
id: bulk-export-files-sdk
```

### Bad
```js
exports.main = async () => {
  const allOrders = await fetchAll();
  return { body: { orders: allOrders } }; // 50MB response
};
```

### Good
```js
exports.main = async () => {
  const files = await Files.init();
  const stream = await fetchAllAsStream();
  await files.write('exports/orders.jsonl', stream);
  const url = await files.generatePresignURL('exports/orders.jsonl', { expiryInSeconds: 3600 });
  return { body: { downloadUrl: url } };
};
```

---

## Right-size action memory and concurrency — don't ship everything at default 256MB [warn] [cost, performance]

Per-action GB-seconds is the dominant cost driver. Actions that process 10KB events at 1024MB cost 4× what they should. Set memory and `container concurrency` per action based on actual workload — small handlers at 256MB, heavy transforms at 1024MB, fan-out coordinators at 512MB with high concurrency.

### Check
```yaml
type: builtin
id: uniform-memory-concurrency
scope: repo
```

### Bad
```yaml
# every action at default
actions:
  small-handler:    { memory: 1024, concurrency: 1 }   # wasteful
  heavy-transform:  { memory: 1024, concurrency: 1 }   # underprovisioned concurrency
```

### Good
```yaml
actions:
  small-handler:    { memory: 256,  concurrency: 50 }
  heavy-transform:  { memory: 1024, concurrency: 5 }
  fanout-coord:     { memory: 512,  concurrency: 100 }
```

---

## Prefer batch processing over per-event sync HTTP for high-volume streams [warn] [cost, performance]

A per-event sync handler at 50 events/sec runs 50 concurrent invocations, each paying cold-start, container, and 256MB minimum. The same workload as a batch consumer running once per minute and processing 3000 events is 50–100× cheaper. Use journaling for high-volume streams.

### Check
```yaml
type: builtin
id: events-batch-vs-sync
```

### Bad
```yaml
events:
  - source: commerce.order.created
    action: per-event-handler  # 50 invocations/sec
```

### Good
```js
// scheduled batch consumer, runs every minute
exports.main = async () => {
  const events = await journal.poll({ limit: 3000 });
  await processBatch(events);
};
```
