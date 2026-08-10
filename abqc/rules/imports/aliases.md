# Imports — Aliases

> Part of [Imports](./README.md).

## Prefer absolute / aliased imports over deep relative paths [info] [imports, refactor]

`import/no-relative-parent-imports` plus a project alias config. `../../../utils/logger` breaks every time a file moves. An alias (`@/utils/logger` or `~/utils/logger`) is stable across refactors and reads better in code review.

### Bad
```js
// actions/orders/process/index.js
const { logger } = require('../../../utils/logger')
const { format } = require('../../../utils/format')
```

### Good
```js
// jsconfig.json or webpack alias: '@/utils' → './utils'
const { logger } = require('@/utils/logger')
const { format } = require('@/utils/format')
```
