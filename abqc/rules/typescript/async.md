# TypeScript — Async Correctness

> Part of [TypeScript](./README.md).

## No floating promises — every Promise must be handled [error] [async, bugs]

`@typescript-eslint/no-floating-promises` catches promises that are neither `await`ed nor `.then`/`.catch`-ed. In the App Builder runtime this is fatal: an unhandled rejection kills the container (see node.md). The lint rule catches the issue at compile time.

### Bad
```ts
async function main (params: Params) {
  doBackgroundWork(params.id)   // floating
  return { statusCode: 200 }
}
```

### Good
```ts
async function main (params: Params) {
  void doBackgroundWork(params.id).catch(err => logger.error('bg failed', err))
  return { statusCode: 200 }
}
```

---

## No misused promises in conditionals or loops [error] [async, bugs]

`if (asyncFn())` checks the truthiness of the Promise object (always true) — never the resolved value. Same for `while`, `forEach`, etc. `@typescript-eslint/no-misused-promises` catches these cases.

### Bad
```ts
if (await isAuthorized(user)) { /* unrelated bug — but: */ }
if (isAuthorized(user)) { /* always true */ }
[1,2,3].forEach(async i => await save(i))   // forEach ignores promises
```

### Good
```ts
if (await isAuthorized(user)) { /* correct */ }
for (const i of [1,2,3]) await save(i)      // serial
await Promise.all([1,2,3].map(save))         // parallel
```

---

## Await thenable values, not non-promises [error] [async, bugs]

`await x` where `x` is not a Promise wraps it and returns it unchanged — but signals to readers (incorrectly) that `x` is async. `@typescript-eslint/await-thenable` flags it. Almost always indicates a refactor that left an `await` behind.

### Bad
```ts
const total = await sumLines(order)   // sumLines returns number, not Promise<number>
```

### Good
```ts
const total = sumLines(order)
```

---

## Functions that return promises should be marked async [warn] [async, style]

`promise-function-async` enforces this. A non-async function that returns a Promise works, but readers don't know to await it without checking the implementation. Marking it `async` makes the asynchrony visible at the signature level.

### Bad
```ts
function fetchOrder (id: string): Promise<Order> {
  return fetch(`/orders/${id}`).then(r => r.json())
}
```

### Good
```ts
async function fetchOrder (id: string): Promise<Order> {
  const res = await fetch(`/orders/${id}`)
  return res.json()
}
```

---

## Type async function return as Promise<T>, not just T [warn] [style, async]

An `async` function returning `T` is implicitly `Promise<T>`, but typing it explicitly as `Promise<T>` documents asynchrony at the signature level — useful for interface declarations and JSDoc.

### Bad
```ts
async function fetchOrder (id: string): Order {  // type error in strict mode anyway
  return await db.query(id)
}
```

### Good
```ts
async function fetchOrder (id: string): Promise<Order> {
  return db.query(id)
}
```
