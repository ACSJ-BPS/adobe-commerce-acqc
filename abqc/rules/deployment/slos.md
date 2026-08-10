# Deployment — SLOs

> Part of [Deployment](./README.md).

## Action response time SLO: p95 < 500ms [warn] [perf, slo]

Web actions invoked synchronously by a SPA need to feel instant — humans perceive >500ms as a hitch. Set an SLO of p95 < 500ms across all sync actions and track it in your monitoring. Anything that can't meet the budget should be moved to async (fan-out, trigger, polling) instead of letting the user wait.

### Bad
```js
async function main (params) {
  const data = await slowAggregation(params)   // p95 = 4.2s
  return { statusCode: 200, body: data }       // user-visible delay every request
}
```

### Good
```js
async function main (params) {
  const cached = await state.get(`agg:${params.id}`)
  if (cached) return { statusCode: 200, body: JSON.parse(cached) }   // p95 < 100ms

  // miss → kick off async, return 202 with poll URL
  await openwhisk().actions.invoke({ name: 'compute-aggregation', params, blocking: false })
  return { statusCode: 202, body: { status: 'processing', pollUrl: `/v1/agg/${params.id}` } }
}
```

---

## Event processing time SLO: p95 < 1s [warn] [perf, slo]

I/O Events handlers don't have a user blocking on them, but they share the namespace's concurrency budget. An event handler that takes 30s blocks 30 seconds' worth of other invocations. Keep handler time under 1s p95 by doing the minimum acknowledge-and-enqueue work synchronously and offloading heavy processing to a follow-up trigger.

### Bad
```js
async function main (params) {
  if (params.challenge) return { body: { challenge: params.challenge } }
  await downloadAsset(params.data)              // 8s
  await processWithML(params.data)              // 22s
  await uploadResult(params.data)               // 4s
  return { statusCode: 200 }
}
```

### Good
```js
async function main (params) {
  if (params.challenge) return { body: { challenge: params.challenge } }
  await openwhisk().actions.invoke({
    name: 'process-asset-async',
    params: { eventId: params.id, data: params.data },
    blocking: false
  })
  return { statusCode: 200 }   // ack the event in <100ms
}
```

---

## Reuse module-scope HTTP clients and SDK instances across warm invocations [warn] [perf, cold-start]

Every `new SomeClient()` inside `main` re-initializes connections, parses configs, fetches credentials. Move client construction to module scope so it persists for the lifetime of the warm container — typically minutes of reuse before recycle. Pair with lazy initialization (memoized promise) so the first cold start doesn't pay the full cost twice.

### Bad
```js
async function main (params) {
  const sdk = await initAemSdk(params.AEM_HOST, params.AEM_TOKEN)   // cold every call
  return { body: await sdk.getAsset(params.path) }
}
```

### Good
```js
let sdkPromise = null
async function getSdk (params) {
  sdkPromise ??= initAemSdk(params.AEM_HOST, params.AEM_TOKEN)
  return sdkPromise
}

async function main (params) {
  const sdk = await getSdk(params)
  return { body: await sdk.getAsset(params.path) }
}
```
