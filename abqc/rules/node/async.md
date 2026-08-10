# Node.js — Async/Await

> Part of [Node.js](./README.md).

## No top-level await of network calls inside actions [warn] [cold-start, perf, runtime]

Top-level `await` runs at module-load time — i.e. on every cold start. A network call there adds its full latency to every cold invocation in the namespace. Move network setup inside `main` and cache the result in a module-scope variable for warm reuse.

### Bad
```js
const config = await fetch('https://config.example.com').then(r => r.json())
async function main (params) { return useConfig(config) }
```

### Good
```js
let configPromise = null
async function main (params) {
  configPromise ??= fetch('https://config.example.com').then(r => r.json())
  return useConfig(await configPromise)
}
```

---

## Async functions must contain await — require-await [warn] [style, bugs]

An `async` function with no `await` is a code smell: either the function shouldn't be async (forces callers into unnecessary `await`/`.then`), or someone removed an await during a refactor and forgot to remove `async`.

### Bad
```js
async function formatLabel (status) {
  return status.toUpperCase()
}
```

### Good
```js
function formatLabel (status) {
  return status.toUpperCase()
}
```
