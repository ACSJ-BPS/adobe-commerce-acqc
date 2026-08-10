# Imports — Ordering

> Part of [Imports](./README.md).

## Imports go at the top of the file — first [warn] [imports, style]

`import/first`. Imports between code blocks confuse readers and prevent some bundlers from tree-shaking. The exception is conditional dynamic `import()` for code-splitting (still allowed at any position).

### Check
```yaml
type: builtin
id: imports-top
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const fs = require('node:fs/promises')

async function main (params) {
  const { Core } = require('@adobe/aio-sdk')   // not at top
  // ...
}
```

### Good
```js
const fs = require('node:fs/promises')
const { Core } = require('@adobe/aio-sdk')

async function main (params) { /* ... */ }
```

---

## Blank line after the import block — newline-after-import [info] [imports, style]

`import/newline-after-import`. A visual separator between the import block and the rest of the file. Subtle but readable diffs.

### Bad
```js
const fs = require('node:fs/promises')
const { Core } = require('@adobe/aio-sdk')
async function main (params) {
```

### Good
```js
const fs = require('node:fs/promises')
const { Core } = require('@adobe/aio-sdk')

async function main (params) {
```

---

## Group imports: builtin → external → internal → parent → sibling [info] [imports, style]

`import/order` with `'newlines-between': 'always'`. A consistent group order makes import blocks scannable: Node built-ins, npm packages, project absolute paths, parent dirs, sibling files.

### Bad
```js
import { OrderRow } from './OrderRow'
import { Core } from '@adobe/aio-sdk'
import fs from 'node:fs/promises'
import { logger } from '../utils/logger'
import { Provider } from '@adobe/react-spectrum'
```

### Good
```js
import fs from 'node:fs/promises'

import { Core } from '@adobe/aio-sdk'
import { Provider } from '@adobe/react-spectrum'

import { logger } from '../utils/logger'

import { OrderRow } from './OrderRow'
```
