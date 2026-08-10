# Documentation — Code

> Part of [Documentation](./README.md).

## JSDoc on every exported function — params, returns, throws [warn] [jsdoc, contracts]

Every function exposed across module boundaries (action `main`, utility helpers in `utils/`, exported React components/hooks) gets a JSDoc block with `@param`, `@returns`, and `@throws` (when meaningful). This drives editor tooltips, TypeScript inference for plain JS projects via `// @ts-check`, and keeps the contract in the same file as the implementation.

### Check
```yaml
type: builtin
id: jsdoc-export
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  // 80 lines of logic, no doc — caller has to read every line to know what params are required
  return result
}
exports.main = main
```

### Good
```js
/**
 * Process an order through validation, tax calculation, and inventory reservation.
 *
 * @param {object} params
 * @param {string} params.orderId - The order ID (UUID)
 * @param {string} params.region - ISO 3166-1 alpha-2 region code, e.g. 'US', 'EU', 'JP'
 * @param {string} params.LOG_LEVEL - Defaults to 'info'
 * @returns {Promise<{statusCode: number, body: object} | {error: {statusCode: number, body: object}}>}
 *   200 with `{ orderId, total, items[] }` on success,
 *   400 if input invalid, 409 if inventory unavailable, 500 on internal error
 * @throws Never — all errors are returned as wrapped error responses per Runtime contract
 */
async function main (params) {
  // ...
}
exports.main = main
```
