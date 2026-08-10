# Node.js — Reliability

> Part of [Node.js](./README.md).

## Cap concurrency on batched external calls using p-map or equivalent [warn] [performance, reliability]

`Promise.all` runs every item in parallel — fine for 3–5 items, but with 50+ concurrent calls to a database, external API, or `aio-lib-state`, it exhausts connection pools, triggers rate limits, and creates head-of-line blocking under backpressure. Use `p-map` (or equivalent: `p-limit`, Bottleneck, or a manual semaphore) to set an explicit concurrency ceiling. The right ceiling depends on the downstream service's limits; start at 5–10 and tune based on error rates and latency metrics.

### Bad
```js
// fires all 200 requests at once — overwhelms downstream, triggers rate limits
const results = await Promise.all(items.map(item => api.fetch(item.id)))
```

### Good
```js
import pMap from 'p-map'

const results = await pMap(items, item => api.fetch(item.id), { concurrency: 8 })
// tune concurrency based on the downstream SLO and observed error rates
```

---

## Apply circuit breakers on calls to external dependencies [warn] [reliability, resilience]

When an upstream service (Adobe API, database, queue) starts failing, unbounded retries amplify load and cascade the failure. A circuit breaker trips to "open" after a threshold of failures, returns fast errors immediately, and periodically tests recovery — protecting both the caller and the downstream from pile-on load. Adobe's `microservice-lib-node` ships a circuit-breaker implementation. For App Builder actions, implement a simple in-module state tracker or use `opossum`. This is especially important for actions that call real-time APIs under the 60 s/30 s timeout budget.

### Bad
```js
// retries indefinitely, amplifying load on an already-failing service
async function fetchWithRetry (url, n = 5) {
  for (let i = 0; i < n; i++) {
    try { return await fetch(url) } catch { /* keep trying */ }
  }
  throw new Error('All retries failed')
}
```

### Good
```js
import CircuitBreaker from 'opossum'

const breaker = new CircuitBreaker(fetch, {
  timeout: 3000,         // fail if call takes > 3s
  errorThresholdPercentage: 50,
  resetTimeout: 10000,   // try again after 10s
})

breaker.fallback(() => ({ status: 503, body: 'service unavailable' }))

// breaker.fire() replaces direct calls; trips open on sustained failures
export const safeFetch = (url) => breaker.fire(url)
```
