# Imports — Exports

> Part of [Imports](./README.md).

## Default exports must be named at the export site — no-anonymous-default-export [warn] [imports, debug]

`import/no-anonymous-default-export`. An anonymous `export default { ... }` shows up as `default` in stack traces and React DevTools. Assigning to a named const first preserves the symbol name everywhere.

### Bad
```js
export default {
  format: (n) => `$${n}`,
  parse: (s) => Number(s)
}
```

### Good
```js
const formatters = {
  format: (n) => `$${n}`,
  parse: (s) => Number(s)
}
export default formatters
```

---

## Prefer named exports for utilities — default exports for "the one thing" [info] [imports, api-design]

`import/prefer-default-export` is **the opposite of this rule** and should be disabled. Named exports give consumers the canonical name (refactor-safe), let multiple things ship from one file, and play well with TypeScript imports. Reserve `export default` for files where there is genuinely one entity (a React page, an action's `main`).

### Bad
```js
// utils/format.js — everything as default
export default function format (n) { return n.toFixed(2) }
// caller renames silently:
import formatPrice from './utils/format'
```

### Good
```js
// utils/format.js
export function format (n) { return n.toFixed(2) }
export function parse (s) { return Number(s) }

// caller can't rename without noticing:
import { format } from './utils/format'
```

---

## Avoid namespace imports — import * as [info] [imports, bundle]

`import/no-namespace`. `import * as Foo from 'foo'` pulls every export into a namespace object, defeats tree-shaking in most bundlers, and obscures which symbols are actually used. Prefer named imports.

### Bad
```js
import * as utils from './utils'
utils.format(price)
utils.parse(input)
// bundle includes ALL of ./utils even if only 2 symbols used
```

### Good
```js
import { format, parse } from './utils'
format(price)
parse(input)
```
