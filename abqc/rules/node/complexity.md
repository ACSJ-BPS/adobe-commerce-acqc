# Node.js — Complexity (PHPMD-style)

> Part of [Node.js](./README.md).

## Cyclomatic complexity ≤ 10 per function [warn] [complexity, maintainability]

A function with more than 10 branches (if, switch case, ternary, &&, ||) is hard to test exhaustively and hard to read. Split into helper functions with single responsibilities. ESLint's `complexity` rule enforces it.

### Check
```yaml
type: builtin
id: cyclomatic-complexity
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
function classifyOrder (o) {
  if (o.region === 'EU') {
    if (o.total > 1000) {
      if (o.customer.tier === 'gold') return 'eu-gold-large'
      if (o.customer.tier === 'silver') return 'eu-silver-large'
      return 'eu-large'
    }
    if (o.total > 500) return 'eu-medium'
    return 'eu-small'
  }
  if (o.region === 'US') { /* same nesting again */ }
  if (o.region === 'APAC') { /* same nesting again */ }
}
```

### Good
```js
const TIER_RULES = {
  EU: classifyEU,
  US: classifyUS,
  APAC: classifyAPAC
}
function classifyOrder (o) {
  return (TIER_RULES[o.region] ?? classifyDefault)(o)
}
```

---

## Function length ≤ 50 lines [warn] [complexity, maintainability]

Functions longer than ~50 lines almost always have multiple responsibilities. Extract logical chunks into named helpers — the function body becomes a readable narrative of what happens, helpers carry the detail. ESLint's `max-lines-per-function`.

### Check
```yaml
type: builtin
id: function-length
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function processOrder (params) {
  // 80 lines: validate + tax calc + inventory check + payment + email + audit log
}
```

### Good
```js
async function processOrder (params) {
  const validated = validate(params)
  const taxed = applyTax(validated)
  await reserveInventory(taxed)
  await chargePayment(taxed)
  await sendConfirmation(taxed)
  await logAudit(taxed)
  return taxed
}
```

---

## Max 4 parameters per function — use an options object [warn] [complexity, api-design]

Five+ positional parameters mean the call site is unreadable (`fn(true, false, 5, null, 'gold')` — what does each mean?) and adding a new param is a breaking change. Bundle into an options object once you exceed 4. ESLint's `max-params`.

### Check
```yaml
type: builtin
id: max-params
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
function syncOrder (orderId, region, retries, timeout, force, audit, dryRun) { }
syncOrder('123', 'EU', 3, 5000, false, true, false)   // mystery args
```

### Good
```js
function syncOrder ({ orderId, region, retries = 3, timeout = 5000, force = false, audit = true, dryRun = false }) { }
syncOrder({ orderId: '123', region: 'EU', force: true })
```

---

## Max nesting depth ≤ 4 [warn] [complexity, readability]

Deeply nested code (`if` inside `for` inside `try` inside `if`) is the hardest pattern to read. Use early returns, guard clauses, and extracted helpers to flatten. ESLint's `max-depth`.

### Check
```yaml
type: builtin
id: max-nesting
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
function main (params) {
  if (params.user) {
    if (params.user.active) {
      if (params.user.tier === 'gold') {
        if (params.user.balance > 0) {
          return charge(params.user)
        }
      }
    }
  }
  return reject()
}
```

### Good
```js
function main (params) {
  const u = params.user
  if (!u || !u.active) return reject()
  if (u.tier !== 'gold') return reject()
  if (u.balance <= 0) return reject()
  return charge(u)
}
```

---

## Max file length ≤ 300 lines (excluding comments and blanks) [warn] [complexity, maintainability]

Files longer than ~300 lines tend to mix concerns and resist navigation. Split by responsibility — one action per file in `actions/`, one component per file in `web-src/`. ESLint's `max-lines`.

### Check
```yaml
type: builtin
id: max-file-length
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```text
actions/orders/index.js   // 800 lines: list + get + create + cancel + sync + audit
```

### Good
```text
actions/orders/list/index.js
actions/orders/get/index.js
actions/orders/create/index.js
actions/orders/cancel/index.js
actions/orders/sync/index.js
actions/orders/_shared/audit.js
```
