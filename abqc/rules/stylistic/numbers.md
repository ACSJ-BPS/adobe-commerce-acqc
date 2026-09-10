# Stylistic — Numbers

> Part of [Stylistic](./README.md).

## No magic numbers — extract named constants [warn] [readability, maintenance]

`no-magic-numbers`. A literal `86400` in code tells the reader nothing; `SECONDS_PER_DAY = 86400` does. Make the meaning explicit so the reviewer doesn't have to guess and the next maintainer doesn't have to grep.

### Check
```yaml
type: builtin
id: magic-number
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
if (Date.now() - lastSeen > 86400 * 7 * 1000) markInactive()
const tier = total > 1000 ? 'gold' : total > 500 ? 'silver' : 'bronze'
```

### Good
```js
const SECONDS_PER_DAY = 86_400
const INACTIVE_THRESHOLD_DAYS = 7
const GOLD_THRESHOLD = 1000
const SILVER_THRESHOLD = 500

if (Date.now() - lastSeen > SECONDS_PER_DAY * INACTIVE_THRESHOLD_DAYS * 1000) markInactive()
const tier = total > GOLD_THRESHOLD ? 'gold' : total > SILVER_THRESHOLD ? 'silver' : 'bronze'
```

---

## parseInt requires a radix [warn] [bugs]

`radix`. `parseInt('08')` returns `0` in some legacy engines (octal interpretation) and `8` in modern ones. Always pass an explicit radix. Better: use `Number()` for decimal-only conversions.

### Bad
```js
const count = parseInt(params.count)
```

### Good
```js
const count = parseInt(params.count, 10)
// or, when input is a valid decimal string:
const count = Number(params.count)
```

---

## Prefer numeric separators on long literals [info] [readability]

`prefer-numeric-literals` plus modern numeric separator. `1000000` is hard to scan; `1_000_000` is unambiguous. Supported in Node 12.5+ and all modern browsers.

### Check
```yaml
type: builtin
id: numeric-separator
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const TIMEOUT_MS = 1800000
const MAX_FILE_SIZE = 524288000
```

### Good
```js
const TIMEOUT_MS = 1_800_000
const MAX_FILE_SIZE = 524_288_000
```
