# App Builder — Auth

> Part of [App Builder](./README.md).

## Token-generating actions must be non-web actions [error] [auth]

If a web action mints IMS or service-to-service tokens, anyone on the public internet can call it and harvest tokens. Adobe's Security Overview explicitly says token generation should "not be a web action to prevent unauthorized access." Mark non-web and invoke from another action via OpenWhisk's internal client.

### Check
```yaml
type: builtin
id: token-action-not-web
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad

```yaml
actions:
  mint-token:
    function: actions/mint-token/index.js
    web: 'yes'
```

### Good

```yaml
actions:
  mint-token:
    function: actions/mint-token/index.js
    web: 'no'
    annotations:
      require-adobe-auth: false
```

---

## Use IMS SDK auto-renewal — JWT lifetime is 24h [warn] [auth, tokens]

JWT access tokens expire in 24 hours. Manual refresh logic is the #1 source of "it worked yesterday" bugs. The `@adobe/aio-lib-ims` library handles renewal transparently when you call `getToken()` per request.

### Check
```yaml
type: builtin
id: ims-auto-renewal
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad

```js
let cachedToken = null
async function main (params) {
  if (!cachedToken) cachedToken = await mintToken()
  return callApi(cachedToken)
}
```

### Good

```js
const { getToken } = require('@adobe/aio-lib-ims')
async function main (params) {
  return callApi(await getToken('jwt'))
}
```

---

## Bearer extraction via getBearerToken(params) utility [warn] [auth]

The auto-generated `actions/utils.js` provides `getBearerToken(params)` which strips the `Bearer ` prefix from `params.__ow_headers.authorization`. Hand-rolling this is a slicing-bug magnet (case sensitivity, missing space, missing prefix).

### Check
```yaml
type: builtin
id: bearer-extraction
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad

```js
async function main (params) {
  const token = params.__ow_headers.authorization.split(' ')[1]
  return callApi(token)
}
```

### Good

```js
const { getBearerToken } = require('./utils')
async function main (params) {
  return callApi(getBearerToken(params))
}
```

---

## Migrate from JWT service accounts to OAuth Server-to-Server [warn] [auth, deprecation]

Adobe deprecated the JWT (Service Account) credential type in January 2024 — it will reach end-of-life in January 2025. New projects must use the **OAuth Server-to-Server** credential. Existing actions using `@adobe/aio-lib-ims` with a `jwt` flow will begin failing at EOL. Migrate by updating the IMS credential type in Developer Console and switching `getToken('jwt')` → `getToken('oauth-server-to-server')`.

**Why it was missing:** The rules were authored against the pre-deprecation JWT model and didn't reflect the platform EOL announcement from early 2024.

### Check
```yaml
type: regex
pattern: "getToken\\(\\s*['\"]jwt['\"]"
files: ["**/*.{js,cjs,mjs,ts}"]
```

### Bad

```js
const { getToken } = require('@adobe/aio-lib-ims')

async function main (params) {
  const token = await getToken('jwt')   // deprecated — EOL Jan 2025
  return callAdobeApi(token)
}
```

### Good

```js
const { getToken } = require('@adobe/aio-lib-ims')

async function main (params) {
  const token = await getToken('oauth-server-to-server')   // current standard
  return callAdobeApi(token)
}
```
