# Imports — Dependencies

> Part of [Imports](./README.md).

## Imported modules must be in package.json — no extraneous deps [error] [imports, deps]

`import/no-extraneous-dependencies`. An `import` of a package that isn't declared in `package.json` works locally (because npm hoisted it from a transitive dep) and fails after a clean install. Every direct import gets a direct dep.

### Check
```yaml
type: builtin
id: extraneous-dependency
scope: repo
```

### Bad
```json
// package.json — no "lodash" listed
{ "dependencies": { "@adobe/aio-sdk": "^4.0.0" } }
```
```js
// works locally because aio-sdk hoisted lodash, fails on CI clean install
const _ = require('lodash')
```

### Good
```json
{ "dependencies": { "@adobe/aio-sdk": "^4.0.0", "lodash": "^4.17.21" } }
```

---

## Imported modules must resolve — no-unresolved [error] [imports, bugs]

`import/no-unresolved`. An import path that doesn't resolve to a real file or package fails at runtime with a confusing `Cannot find module` error. The lint rule catches it at edit time.

### Check
```yaml
type: builtin
id: unresolved-import
scope: repo
```

### Bad
```js
const { logger } = require('./utlis/logger')   // typo: utlis vs utils
```

### Good
```js
const { logger } = require('./utils/logger')
```
