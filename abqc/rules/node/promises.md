# Node.js — Promises (eslint-plugin-promise)

> Part of [Node.js](./README.md).

## .then() callbacks must always return a value [error] [promise, bugs]

`promise/always-return`. A `.then()` callback that doesn't return is almost always a chain-break: the next `.then()` receives `undefined` instead of the value the chain was building up. The rule catches it.

### Check
```yaml
type: builtin
id: then-must-return
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
fetchOrder(id)
  .then(order => {
    enrich(order)              // returns Promise<EnrichedOrder> — but never returned
  })
  .then(enriched => {
    return persist(enriched)   // enriched is undefined here
  })
```

### Good
```js
fetchOrder(id)
  .then(order => enrich(order))
  .then(enriched => persist(enriched))
```

---

## Promises must be returned, awaited, or .catch'd [error] [promise, bugs]

`promise/catch-or-return`. Top-level promise chains that aren't returned, awaited, or terminated with `.catch()` produce unhandled rejections — fatal in App Builder per `node.md` rule #7. Either consume the result or chain a catch.

### Check
```yaml
type: builtin
id: floating-promise
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  notifyUpstream(params.event)   // floating promise
  return { statusCode: 200 }
}
```

### Good
```js
async function main (params) {
  notifyUpstream(params.event).catch(err => logger.warn('notify failed', err))
  return { statusCode: 200 }
}
```

---

## No nested .then() — flatten with chaining or async/await [warn] [promise, readability]

`promise/no-nesting`. Nested `.then()` callbacks recreate the callback pyramid promises were supposed to fix. Flatten the chain by returning the inner promise, or rewrite as `async`/`await`.

### Check
```yaml
type: builtin
id: nested-then
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
fetchOrder(id).then(order => {
  return enrich(order).then(enriched => {
    return persist(enriched).then(saved => {
      return notify(saved)
    })
  })
})
```

### Good
```js
async function processOrder (id) {
  const order = await fetchOrder(id)
  const enriched = await enrich(order)
  const saved = await persist(enriched)
  return notify(saved)
}
```

---

## No return-wrapping — don't wrap a promise in Promise.resolve [warn] [promise, perf]

`promise/no-return-wrap`. `return Promise.resolve(value)` inside a `.then()` callback (or async function) is redundant — the surrounding context already wraps the value in a promise. Same for `Promise.reject()` — use `throw` inside async.

### Check
```yaml
type: builtin
id: promise-resolve-wrap
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function fetchUser (id) {
  return Promise.resolve(cache[id])   // already wrapped by async
}

fetchOrder(id).then(o => {
  if (!o) return Promise.reject(new Error('not found'))   // throw is shorter
  return o
})
```

### Good
```js
async function fetchUser (id) {
  return cache[id]
}

fetchOrder(id).then(o => {
  if (!o) throw new Error('not found')
  return o
})
```

---

## Don't return from a finally block [error] [promise, bugs]

`promise/no-return-in-finally`. A `return` inside `.finally()` overrides whatever the resolved/rejected handler returned, including swallowing thrown errors. Reserve `finally` for cleanup; never return from it.

### Check
```yaml
type: builtin
id: return-in-finally
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
fetchOrder(id)
  .then(o => o.id)
  .catch(err => { throw err })
  .finally(() => 'done')   // overrides the chain — caller gets 'done', not the id
```

### Good
```js
fetchOrder(id)
  .then(o => o.id)
  .catch(err => { logger.error(err); throw err })
  .finally(() => { closeConnection() })   // cleanup only, no return
```

---

## Prefer await over .then() in async functions [info] [promise, readability]

`promise/prefer-await-to-then`. Inside an `async` function, `await` reads top-to-bottom; `.then()` chains are harder to follow, especially with branching logic. Mixing both is the worst.

### Check
```yaml
type: builtin
id: then-in-async-fn
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  return fetchOrder(params.id).then(order => {
    if (!order) return errorResponse(404, 'not found')
    return processOrder(order).then(processed => {
      return { statusCode: 200, body: processed }
    })
  })
}
```

### Good
```js
async function main (params) {
  const order = await fetchOrder(params.id)
  if (!order) return errorResponse(404, 'not found')
  const processed = await processOrder(order)
  return { statusCode: 200, body: processed }
}
```

---

## Promise constructor: param names must be (resolve, reject) [warn] [promise, conventions]

`promise/param-names`. Every Promise reader expects `(resolve, reject)`; reversing or renaming them creates confusion in the constructor body and undermines lint tooling.

### Bad
```js
new Promise((reject, resolve) => {
  if (err) reject(err)
  else resolve(value)
})

new Promise((res, rej) => { /* ... */ })
```

### Good
```js
new Promise((resolve, reject) => {
  if (err) reject(err)
  else resolve(value)
})
```
