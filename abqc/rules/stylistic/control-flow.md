# Stylistic — Control Flow

> Part of [Stylistic](./README.md).

## No nested ternaries — use if/else or a lookup [warn] [readability]

`no-nested-ternary`. Three or more conditions in a ternary chain are unreadable. A lookup table or early-return chain is clearer.

### Check
```yaml
type: builtin
id: nested-ternary
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const tier = total > 1000 ? 'gold' : total > 500 ? 'silver' : total > 100 ? 'bronze' : 'none'
```

### Good
```js
function classifyTier (total) {
  if (total > 1000) return 'gold'
  if (total > 500) return 'silver'
  if (total > 100) return 'bronze'
  return 'none'
}
```

---

## No unneeded ternaries [info] [readability]

`no-unneeded-ternary`. `x ? true : false` simplifies to `Boolean(x)`; `x ? x : y` simplifies to `x ?? y` (or `||` if you want the falsy semantics).

### Check
```yaml
type: builtin
id: unneeded-ternary
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const isActive = user.status === 'active' ? true : false
const label = customLabel ? customLabel : defaultLabel
```

### Good
```js
const isActive = user.status === 'active'
const label = customLabel ?? defaultLabel
```

---

## No empty blocks — at least a comment explaining why [warn] [bugs]

`no-empty`. An empty `if`, `else`, `try`, or `catch` block is almost always a half-finished refactor or a swallowed error. If empty is genuinely intentional, add a comment so the next reader knows.

### Check
```yaml
type: builtin
id: empty-block-no-comment
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
try {
  await syncOrder(id)
} catch (e) {}   // silent failure
```

### Good
```js
try {
  await syncOrder(id)
} catch (e) {
  logger.warn('sync failed, will retry next cycle', { id, err: e.message })
}
```

---

## No useless return statements [info] [style, dead-code]

`no-useless-return`. `return` at the end of a void function does nothing. Removing the noise makes the actual control flow visible.

### Check
```yaml
type: builtin
id: useless-return
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
function logEvent (event) {
  if (!event.userId) return
  logger.info('event', event)
  return
}
```

### Good
```js
function logEvent (event) {
  if (!event.userId) return
  logger.info('event', event)
}
```

---

## Don't use else after return — flat is better than nested [info] [readability]

`no-else-return`. After an early `return`, the `else` branch becomes unreachable as the body of the function. Drop it; the code is flatter and reads top-to-bottom.

### Check
```yaml
type: builtin
id: else-after-return
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
function classify (n) {
  if (n > 0) {
    return 'positive'
  } else if (n < 0) {
    return 'negative'
  } else {
    return 'zero'
  }
}
```

### Good
```js
function classify (n) {
  if (n > 0) return 'positive'
  if (n < 0) return 'negative'
  return 'zero'
}
```
