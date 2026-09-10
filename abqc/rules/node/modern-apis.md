# Node.js — Modern APIs

> Part of [Node.js](./README.md).

## Prefer node:fs/promises over callback fs [info] [style, api]

The promise-based API is the modern default — readable async/await, no nested callbacks, identical performance to the callback API since Node 16. The `node:` prefix makes import resolution explicit (no risk of shadowing by a userland `fs` package).

### Check
```yaml
type: builtin
id: callback-fs
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const fs = require('fs')
fs.readFile('./data.json', 'utf8', (err, contents) => { /* ... */ })
```

### Good
```js
const fs = require('node:fs/promises')
const contents = await fs.readFile('./data.json', 'utf8')
```

---

## Use the node: prefix for all built-in modules [warn] [style, api, security]

`require('node:fs')` is unambiguously the built-in; `require('fs')` could be shadowed by a userland package named `fs` (a known supply-chain vector). The prefix is supported in Node 16+ and recommended by `eslint-plugin-n`'s `prefer-node-protocol` rule.

### Check
```yaml
type: builtin
id: node-prefix
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const path = require('path')
const crypto = require('crypto')
const { Buffer } = require('buffer')
```

### Good
```js
const path = require('node:path')
const crypto = require('node:crypto')
const { Buffer } = require('node:buffer')
```

---

## No deprecated Node.js APIs [error] [deprecated, runtime]

`n/no-deprecated-api`. Node deprecates APIs across major versions; `domain`, `crypto.createCredentials`, `Buffer()` constructor, `url.parse` (since 11.x). Using them produces runtime warnings, sometimes silent breakage in newer runtimes.

### Check
```yaml
type: builtin
id: deprecated-node-api
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const { parse } = require('node:url')
const parsed = parse(rawUrl)              // legacy parser, deprecated
const buf = new Buffer(payload)            // deprecated since Node 6
```

### Good
```js
const parsed = new URL(rawUrl)             // WHATWG URL — modern, supported
const buf = Buffer.from(payload)
```

---

## Prefer global URL / Buffer / process — don't import from node: [info] [style, perf]

`n/prefer-global/url`, `prefer-global/buffer`, `prefer-global/process`. These globals have been built into Node since 10.x. Importing them adds an unnecessary `require` and is slower at module-load time. Exception: when you want strict `node:` provenance for clarity, the explicit import is acceptable.

### Check
```yaml
type: builtin
id: redundant-global-import
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const { URL } = require('node:url')
const { Buffer } = require('node:buffer')
const process = require('node:process')

const u = new URL('https://example.com')
```

### Good
```js
const u = new URL('https://example.com')   // URL is already global
const buf = Buffer.alloc(1024)
process.env.LOG_LEVEL
```
