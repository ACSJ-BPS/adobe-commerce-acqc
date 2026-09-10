# Imports — Discipline

> Part of [Imports](./README.md).

## No circular imports — break the cycle [error] [imports, architecture]

`import/no-cycle`. A module that imports another which (transitively) imports back creates a runtime initialization-order bug: at least one side sees `undefined` for the imports during module load. The fix is almost always to extract the shared part into a third module that both depend on.

### Check
```yaml
type: builtin
id: circular-import
scope: repo
```

### Bad
```js
// orders.js
const { formatPrice } = require('./pricing')
exports.processOrder = (o) => formatPrice(o.total)

// pricing.js
const { processOrder } = require('./orders')   // cycle
exports.formatPrice = (n) => `$${n}`
```

### Good
```js
// formatters.js — shared, no further imports
exports.formatPrice = (n) => `$${n}`

// orders.js
const { formatPrice } = require('./formatters')

// pricing.js
const { formatPrice } = require('./formatters')
```

---

## File can't import itself — no-self-import [error] [imports, bugs]

`import/no-self-import`. Almost always a copy-paste accident. Self-import causes infinite recursion at module load.

### Bad
```js
// utils/logger.js
const { logger } = require('./logger')   // imports itself
```

### Good
```js
// utils/logger.js
function createLogger () { /* ... */ }
module.exports = { createLogger }
```

---

## No duplicate imports — merge them [warn] [imports, hygiene]

`import/no-duplicates`. Two `import` statements from the same module split which symbols come from where, confuse tooling, and cost extra parse time.

### Bad
```js
import { Core } from '@adobe/aio-sdk'
import { Files } from '@adobe/aio-sdk'
import { State } from '@adobe/aio-sdk'
```

### Good
```js
import { Core, Files, State } from '@adobe/aio-sdk'
```
