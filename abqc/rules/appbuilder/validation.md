# App Builder — Validation

> Part of [App Builder](./README.md).

## Validate inputs with checkMissingRequestInputs() before SDK calls [error] [sdk, validation]

The auto-generated `actions/utils.js` ships with `checkMissingRequestInputs(params, requiredParams, requiredHeaders)`. Calling it first means SDK clients never see missing credentials and you return a clean 400 instead of a stack trace from deep inside an Adobe SDK call.

### Bad
```js
async function main (params) {
  const client = await sdk.init(params.IMS_ORG_ID, params.apiKey, getBearerToken(params))
  return { body: await client.getProfile() }
}
```

### Good
```js
const { checkMissingRequestInputs, errorResponse } = require('./utils')

async function main (params) {
  const missing = checkMissingRequestInputs(params, ['IMS_ORG_ID', 'apiKey'], ['authorization'])
  if (missing) return errorResponse(400, missing, logger)
  const client = await sdk.init(params.IMS_ORG_ID, params.apiKey, getBearerToken(params))
  return { body: await client.getProfile() }
}
```

---

## Treat params as untrusted — never pass to SQL, eval, or shell [error] [injection, security]

Adobe's Security Overview is explicit: "Parameters sent to actions are not sanitized by the Runtime system." Any value reachable from `params` — including `params.__ow_headers`, `params.__ow_query`, and your own action inputs — must be treated as adversarial input.

### Bad
```js
async function main (params) {
  return db.query(`SELECT * FROM users WHERE id = ${params.userId}`)
}
```

### Good
```js
async function main (params) {
  return db.query('SELECT * FROM users WHERE id = ?', [params.userId])
}
```

---

## Do not read params.body for input — validator overwrites it [error] [runtime, gotcha]

The Runtime validator always passes `"body": { "message": "validation success" }` during validation invocations. An action that reads `params.body` for real input data will silently behave differently between validation and production — a bug class that's nearly impossible to reproduce locally.

### Bad
```js
async function main (params) {
  const payload = params.body
  return processOrder(payload)
}
```

### Good
```js
async function main (params) {
  const payload = params.order ?? params.__ow_body
  return processOrder(payload)
}
```

---

## Never construct action or sequence names from user input [error] [injection, runtime]

`openwhisk().actions.invoke({ name: params.actionName })` is the I/O Runtime equivalent of `eval`: an attacker who controls `params.actionName` can invoke any action in your namespace, including admin-only or token-minting actions. Always use a hard-coded name or a lookup table with a fixed allow-set.

**Why it was missing:** Command injection in Node.js was covered (child_process), but the equivalent pattern at the OpenWhisk API level was not — it requires knowing the App Builder invocation model specifically.

### Bad
```js
async function main (params) {
  const result = await openwhisk().actions.invoke({
    name: params.targetAction,   // attacker invokes 'mint-token' or any other action
    params: { data: params.data },
    blocking: true
  })
  return result
}
```

### Good
```js
const ALLOWED_ACTIONS = new Set(['format-order', 'validate-address', 'calculate-tax'])

async function main (params) {
  if (!ALLOWED_ACTIONS.has(params.targetAction)) {
    return { error: { statusCode: 400, body: { error: 'unsupported action' } } }
  }
  return openwhisk().actions.invoke({
    name: params.targetAction,
    params: { data: params.data },
    blocking: true
  })
}
```
