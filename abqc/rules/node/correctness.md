# Node.js — ESLint Core Correctness

> Part of [Node.js](./README.md).

## Strict equality only — eqeqeq [error] [bugs, style]

`==` performs type coercion with surprising rules (`'' == 0`, `null == undefined`, `[] == false`). Use `===` and `!==` always. The lone exception some teams allow is `value == null` to catch both `null` and `undefined` — be deliberate if you allow it.

### Check
```yaml
type: regex
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
pattern: "(?<![=!])==(?!=)|!=(?!=)"
ignore: ["== null", "!= null"]
strip: [strings, comments]
```

### Bad
```js
if (params.userId == 0) return { error: 'invalid user' }
```

### Good
```js
if (params.userId === 0) return { error: 'invalid user' }
```

---

## Use isNaN() and valid typeof comparisons [error] [bugs]

`x === NaN` is always false (NaN is the only value not equal to itself). Comparing `typeof x === 'undefined'` is correct; `typeof x === 'undefiend'` (typo) is silently always false. ESLint's `use-isnan` and `valid-typeof` catch both.

### Check
```yaml
type: builtin
id: isnan-typeof
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
if (price === NaN) return rejectInvalid()
if (typeof user === 'undefiend') return loginRequired()
```

### Good
```js
if (Number.isNaN(price)) return rejectInvalid()
if (typeof user === 'undefined') return loginRequired()
```

---

## No unreachable code, no duplicate object keys [error] [bugs, dead-code]

Code after `return`/`throw`/`break` is dead. Duplicate keys in an object literal silently keep only the last value — almost always a bug. ESLint's `no-unreachable` and `no-dupe-keys` catch both.

### Bad
```js
return result
logger.info('done')   // unreachable

const config = { timeout: 5000, retries: 3, timeout: 10000 }   // first ignored
```

### Good
```js
const config = { timeout: 10000, retries: 3 }
logger.info('done')
return result
```

---

## Array callbacks must return — array-callback-return [error] [bugs]

`.map`, `.filter`, `.reduce`, `.find`, `.some`, `.every` all rely on the callback's return value. A callback that forgets to return produces undefined-filled arrays, always-false predicates, or dropped reducer state.

### Check
```yaml
type: builtin
id: array-callback-return
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const ids = orders.map(o => { o.id })   // returns undefined for every item
const flagged = orders.filter(o => { o.status === 'flag' })   // always falsy
```

### Good
```js
const ids = orders.map(o => o.id)
const flagged = orders.filter(o => o.status === 'flag')
```

---

## prefer-const, no-var [error] [style, bugs]

`var` has function scope and hoisting quirks that cause subtle bugs. Use `const` by default; `let` only when reassignment is needed. ESLint's `prefer-const` and `no-var` rules enforce both.

### Bad
```js
var orderId = params.id
var lineCount = params.lines.length
for (var i = 0; i < lineCount; i++) { /* `i` leaks past the loop */ }
```

### Good
```js
const orderId = params.id
const lineCount = params.lines.length
for (let i = 0; i < lineCount; i++) { /* scoped to the loop */ }
```

---

## All requires/imports at the top of the file [warn] [style, perf]

`eslint-plugin-n`'s `global-require` enforces top-level imports. Lazy `require` inside a function works but obscures dependencies, defeats Webpack tree-shaking for TS builds, and pays the resolution cost on every call instead of once.

### Check
```yaml
type: builtin
id: imports-top
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  const { Core } = require('@adobe/aio-sdk')   // resolved per invocation
  const logger = Core.Logger('main')
}
```

### Good
```js
const { Core } = require('@adobe/aio-sdk')
async function main (params) {
  const logger = Core.Logger('main')
}
```

---

## No unused variables, imports, or parameters [warn] [dead-code, style]

`no-unused-vars` is the highest-signal lint rule. Unused imports inflate the bundle and confuse readers; unused parameters often signal a renamed param the author forgot to update. Allowed exception: function params prefixed `_` (convention for "intentionally unused").

### Bad
```js
const { Core, Files, State } = require('@adobe/aio-sdk')   // Files, State unused
async function main (params, context) {                      // context unused
  return Core.Logger('main')
}
```

### Good
```js
const { Core } = require('@adobe/aio-sdk')
async function main (params) {
  return Core.Logger('main').info(`order ${params.orderId}`)
}
exports.main = main
```

---

## No variable shadowing across scopes [warn] [bugs, style]

A variable named the same as one in an enclosing scope hides the outer binding. Refactors that move code between scopes silently change which variable is read. ESLint's `no-shadow` flags it.

### Check
```yaml
type: builtin
id: no-shadow
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
function main (params) {
  const orders = params.orders
  return orders.map(orders => orders.id)   // inner `orders` shadows outer
}
```

### Good
```js
function main (params) {
  const orders = params.orders
  return orders.map(order => order.id)
}
```
