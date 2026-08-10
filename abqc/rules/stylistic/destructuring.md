# Stylistic — Destructuring

> Part of [Stylistic](./README.md).

## Prefer destructuring for object/array access [info] [readability]

`prefer-destructuring`. Destructuring at the assignment site documents what you're using and avoids repeated dotted access.

### Bad
```js
async function main (params) {
  const orderId = params.orderId
  const region = params.region
  const idempotencyKey = params.idempotencyKey
  return process(orderId, region, idempotencyKey)
}
```

### Good
```js
async function main (params) {
  const { orderId, region, idempotencyKey } = params
  return process(orderId, region, idempotencyKey)
}
```

---

## Prefer spread over Function.prototype.apply [info] [style, perf]

`prefer-spread`. Spread is shorter and engines optimize it as well as `apply()`.

### Bad
```js
const max = Math.max.apply(null, scores)
fn.apply(null, args)
```

### Good
```js
const max = Math.max(...scores)
fn(...args)
```

---

## Use object shorthand syntax [info] [style]

`object-shorthand`. `{ foo: foo }` simplifies to `{ foo }`; `{ foo: function () {} }` simplifies to `{ foo () {} }`. Shorter, less repetitive, idiomatic.

### Bad
```js
return {
  orderId: orderId,
  total: total,
  toString: function () { return `Order ${this.orderId}` }
}
```

### Good
```js
return {
  orderId,
  total,
  toString () { return `Order ${this.orderId}` }
}
```
