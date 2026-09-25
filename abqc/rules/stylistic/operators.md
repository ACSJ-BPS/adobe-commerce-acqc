# Stylistic — Operators

> Part of [Stylistic](./README.md).

## Throw Error objects, not strings [error] [errors, debug]

`no-throw-literal`. Throwing `'something failed'` produces a stack trace that points at the throw site only; throwing `new Error('something failed')` carries the full call stack. Also: `instanceof Error` checks fail on string throws.

### Check
```yaml
type: builtin
id: throw-non-error
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
if (!params.orderId) throw 'orderId required'
```

### Good
```js
if (!params.orderId) throw new Error('orderId required')
```

---

## Don't use implicit coercion (!!x, +x, '' + x) [warn] [readability]

`no-implicit-coercion`. `!!x`, `+x`, and `'' + x` work but are easy to misread as typos. The explicit conversion functions document intent.

### Check
```yaml
type: builtin
id: implicit-coercion
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const isAvailable = !!product.stock
const price = +rawPrice
const label = '' + status
```

### Good
```js
const isAvailable = Boolean(product.stock)
const price = Number(rawPrice)
const label = String(status)
```

---

## Avoid mixed operators without parentheses [info] [readability, bugs]

`no-mixed-operators`. `a && b || c` reads ambiguously; the precedence is real but readers misremember it. Parens make intent obvious.

### Check
```yaml
type: builtin
id: mixed-operators
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
if (user.active && user.tier === 'gold' || user.role === 'admin') return allow()
const y = a + b * c - d / e
```

### Good
```js
if ((user.active && user.tier === 'gold') || user.role === 'admin') return allow()
const y = a + (b * c) - (d / e)
```

---

## No yoda conditions — variable on the left [info] [style]

`yoda`. `if (5 === count)` reads backwards. Modern environments don't need the yoda guard against accidental assignment (linters catch `=` vs `===` directly).

### Check
```yaml
type: builtin
id: yoda-condition
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
if (5 === count) return alert()
if (null !== user) return loadProfile(user)
```

### Good
```js
if (count === 5) return alert()
if (user !== null) return loadProfile(user)
```

---

## Operator-assignment shorthand (x += 1, not x = x + 1) [info] [style]

`operator-assignment`. The shorthand is universal and shorter; expanded form is a code smell suggesting the author copy-pasted something.

### Check
```yaml
type: builtin
id: operator-assignment
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
total = total + lineItem.price
count = count + 1
balance = balance - charge
```

### Good
```js
total += lineItem.price
count += 1
balance -= charge
```

---

## Use dot notation when the property name is a valid identifier [info] [style]

`dot-notation`. `obj.foo` reads better than `obj['foo']` and lets TypeScript narrow the type. Bracket access is for dynamic keys or non-identifier names only.

### Check
```yaml
type: builtin
id: bracket-notation-valid-ident
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const region = order['region']
const tier = customer['tier']
```

### Good
```js
const region = order.region
const tier = customer.tier
const dynamic = order[params.key]   // bracket only when key is dynamic
```

---

## No unused expressions — they're dead code [warn] [bugs, dead-code]

`no-unused-expressions`. A statement that evaluates to a value but doesn't use it (`a && b()` instead of `if (a) b()`, or a stray `result.foo`) is almost always a refactor leftover or a typo. The lint rule catches them.

### Check
```yaml
type: builtin
id: unused-expression
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  user.lastSeen   // missing assignment? missing call?
  isReady && processOrder(params)   // works but reads as a smell
}
```

### Good
```js
async function main (params) {
  const lastSeen = user.lastSeen
  if (isReady) await processOrder(params)
}
```
