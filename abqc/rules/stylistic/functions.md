# Stylistic — Functions

> Part of [Stylistic](./README.md).

## Don't reassign function parameters [warn] [bugs, style]

`no-param-reassign`. Mutating `params.x = newValue` inside a function changes the caller's object — surprising side-effect that's easy to miss in code review. Copy first if you need to mutate.

### Bad
```js
function normalize (order) {
  order.region = order.region.toUpperCase()   // mutates caller's order
  return order
}
```

### Good
```js
function normalize (order) {
  return { ...order, region: order.region.toUpperCase() }
}
```

---

## Prefer arrow callbacks for short anonymous functions [info] [style]

`prefer-arrow-callback`. Arrow functions for callbacks are shorter, don't bind their own `this`, and are the idiomatic ES6+ form.

### Bad
```js
items.filter(function (item) { return item.active })
```

### Good
```js
items.filter(item => item.active)
```

---

## No return await — return the promise directly [warn] [perf, async]

`no-return-await`. `return await x` adds an unnecessary microtask tick and a stack frame. The exception is inside `try/catch`, where you genuinely need to catch the rejection — there, `return await` is correct.

### Bad
```js
async function getOrder (id) {
  return await db.query('SELECT * FROM orders WHERE id = $1', [id])
}
```

### Good
```js
async function getOrder (id) {
  return db.query('SELECT * FROM orders WHERE id = $1', [id])
}

// exception — try/catch genuinely needs the await:
async function getOrderWithFallback (id) {
  try {
    return await db.query('SELECT * FROM orders WHERE id = $1', [id])
  } catch {
    return null
  }
}
```

---

## No useless catch — that just rethrows [warn] [errors]

`no-useless-catch`. `try { ... } catch (e) { throw e }` adds noise without changing behavior. Either handle the error (log, transform, suppress) or remove the try/catch. Worse: `catch (e) { throw new Error(e.message) }` discards the original stack trace.

### Bad
```js
try {
  return await fetchData(id)
} catch (e) {
  throw e
}
```

### Good
```js
return await fetchData(id)

// or, if context matters:
try {
  return await fetchData(id)
} catch (cause) {
  throw new Error(`fetchData failed for id=${id}`, { cause })
}
```

---

## No restricted syntax — codify project conventions [info] [conventions]

`no-restricted-syntax` lets you ban specific AST patterns project-wide (`for-in`, `with`, `delete`, etc.). Use it to encode team-specific conventions that don't have a standalone rule. Keep the list short; each entry needs a written rationale in the config.

### Bad
```js
for (const key in obj) { /* ... */ }   // for-in iterates prototype chain too
with (config) { /* ... */ }            // 'with' breaks scope and is deprecated
```

### Good
```js
for (const key of Object.keys(obj)) { /* ... */ }
const c = config; /* use c.foo, c.bar */
```

```json
// eslint config
"no-restricted-syntax": ["error",
  { "selector": "ForInStatement", "message": "Use Object.keys/values/entries with for-of" },
  { "selector": "WithStatement", "message": "'with' is deprecated and breaks scope" }
]
```
