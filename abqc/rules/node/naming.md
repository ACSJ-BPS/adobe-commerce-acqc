# Node.js — Naming Conventions

> Part of [Node.js](./README.md).

## Naming: camelCase locals, PascalCase classes, UPPER_SNAKE constants [info] [style, naming]

Standard JS naming. Constants only get `UPPER_SNAKE` if they are module-level immutable primitives — not arbitrary `const`s. Mixing styles within a file makes diffs harder to scan.

### Check
```yaml
type: builtin
id: naming-convention
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const Max_Retries = 3
const order_id_pattern = /^\d+$/
class order_processor { }
```

### Good
```js
const MAX_RETRIES = 3
const orderIdPattern = /^\d+$/
class OrderProcessor { }
```

---

## Prefer named exports for utilities — default exports for pages/components [info] [style, api-design]

Named exports give consumers the canonical name (refactor-safe; renaming the export breaks all imports) and let multiple things ship from one file. Reserve `export default` for "the one thing this file is" — a React page, an action's `main`. ESLint plugin `import/prefer-default-export` is *the opposite of this rule* — disable it.

### Check
```yaml
type: builtin
id: export-style-by-path
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
// utils/format.js
module.exports = function format (n) { return n.toFixed(2) }
// caller renames silently
const formatPrice = require('./utils/format')
```

### Good
```js
// utils/format.js
function format (n) { return n.toFixed(2) }
function parse (s) { return Number(s) }
module.exports = { format, parse }
// caller can't rename without noticing
const { format } = require('./utils/format')
```
