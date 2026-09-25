# App Builder — SDK

> Part of [App Builder](./README.md).

## Logger pattern: Core.Logger with params.LOG_LEVEL [info] [sdk, observability]

Use `@adobe/aio-sdk` Core's logger so the log level is controllable per-deployment via the `LOG_LEVEL` default param. `console.log` works but cannot be filtered, level-tagged, or shipped to the Adobe Developer Console log viewer with structure intact.

### Check
```yaml
type: builtin
id: structured-logging
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
async function main (params) { console.log('processing', params.orderId) }
```

### Good
```js
const { Core } = require('@adobe/aio-sdk')
async function main (params) {
  const logger = Core.Logger('main', { level: params.LOG_LEVEL || 'info' })
  logger.info('processing', { orderId: params.orderId })
}
```

---

## Use Files SDK for blobs > 100KB, not State SDK [warn] [sdk, scale]

`@adobe/aio-lib-state` is a key-value cache with size and TTL constraints — appropriate for small idempotency keys, request dedup tokens, transient state. Use `@adobe/aio-lib-files` (CDN-backed object storage) for anything that looks like a file or large JSON blob.

### Check
```yaml
type: builtin
id: state-large-blob
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
const { State } = require('@adobe/aio-sdk')
const state = await State.init()
await state.put('report-2026-04', JSON.stringify(massiveReport))   // wrong tier
```

### Good
```js
const { Files } = require('@adobe/aio-sdk')
const files = await Files.init()
await files.write('reports/2026-04.json', JSON.stringify(massiveReport))
```

---

## State SDK is TTL-bounded — never the source of truth [warn] [sdk, persistence]

State entries expire (default 24 hours, max 30 days). Using State as a primary database loses data silently when a key TTLs out. Use it for caches, idempotency tokens, and transient coordination — back persistent data with a real database.

### Check
```yaml
type: builtin
id: state-only-persistence
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
await state.put(`order:${orderId}`, JSON.stringify(order))
// 30 days later: order is gone, no audit trail
```

### Good
```js
await db.insert('orders', order)
await state.put(`order-cache:${orderId}`, JSON.stringify(order), { ttl: 3600 })
```

---

## Define default params at package level when shared across actions [info] [config, dry]

Default params declared at the package level apply to every action in that package and can be overridden per-action without redeployment via `aio runtime package update`. Repeating the same input on every action is harder to maintain.

### Check
```yaml
type: builtin
id: duplicate-package-inputs
scope: repo
```

### Bad
```yaml
packages:
  commerce:
    actions:
      list-orders:
        inputs: { COMMERCE_URL: $COMMERCE_URL, COMMERCE_KEY: $COMMERCE_KEY }
      get-order:
        inputs: { COMMERCE_URL: $COMMERCE_URL, COMMERCE_KEY: $COMMERCE_KEY }
      cancel-order:
        inputs: { COMMERCE_URL: $COMMERCE_URL, COMMERCE_KEY: $COMMERCE_KEY }
```

### Good
```yaml
packages:
  commerce:
    inputs:
      COMMERCE_URL: $COMMERCE_URL
      COMMERCE_KEY: $COMMERCE_KEY
    actions:
      list-orders: { function: actions/list-orders/index.js }
      get-order: { function: actions/get-order/index.js }
      cancel-order: { function: actions/cancel-order/index.js }
```

---

## No built-in relational database — use Files SDK or external DB [warn] [persistence, sdk]

App Builder provides State (KV cache, TTL-bounded), Files (CDN-backed object storage), and event history. There is no managed relational database. Apps that need joins, transactions, or query languages must integrate an external service (RDS, MongoDB Atlas, Supabase) or model the data flat in Files. Don't try to recreate SQL semantics on top of State.

### Check
```yaml
type: builtin
id: state-simulated-join
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
// simulate a join across "tables" stored in State
const order = JSON.parse(await state.get(`order:${id}`))
const customer = JSON.parse(await state.get(`customer:${order.customerId}`))
const items = JSON.parse(await state.get(`order:${id}:items`))
// 3 round-trips, no transaction guarantees, TTL expires data unpredictably
```

### Good
```js
// query an external Postgres for relational data
const { rows } = await pg.query(`
  SELECT o.*, c.name AS customer_name, json_agg(i.*) AS items
  FROM orders o
  JOIN customers c ON c.id = o.customer_id
  JOIN order_items i ON i.order_id = o.id
  WHERE o.id = $1
  GROUP BY o.id, c.name
`, [params.id])
```
