# Security — OWASP API Top 10

> Part of [Security](./README.md).

## Verify resource ownership before returning it — IDOR defense [error] [auth, idor]

Broken Object Level Authorization (OWASP API Security #1) is the most exploited API vulnerability class: an action authenticates the caller but then fetches a record by user-supplied ID without checking whether the authenticated caller is *authorized* to access that specific record. Authentication proves *who* is calling; this check proves *what* they're allowed to see.

**Why it was missing:** The collection had authentication rules (IMS, bearer token, require-adobe-auth) but no authorization-at-the-resource-level rule. Authentication ≠ authorization. OWASP Web Top 10 addresses this as "Broken Access Control" but the API-specific framing (OWASP API Security Top 10 #1) maps more directly to App Builder actions than the web version.

### Check
```yaml
type: builtin
id: missing-tenant-isolation
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
async function main (params) {
  const { checkMissingRequestInputs } = require('./utils')
  const missing = checkMissingRequestInputs(params, ['assetId'], ['authorization'])
  if (missing) return errorResponse(400, missing, logger)

  const asset = await files.read(`assets/${params.assetId}`)   // auth'd but not authorized
  return { statusCode: 200, body: asset }
}
```

### Good
```js
async function main (params) {
  const missing = checkMissingRequestInputs(params, ['assetId'], ['authorization'])
  if (missing) return errorResponse(400, missing, logger)

  const callerOrg = params.__ow_headers?.['x-gw-ims-org-id']
  const meta = await db.getAssetMeta(params.assetId)

  if (!meta || meta.ownerOrg !== callerOrg) {
    return { error: { statusCode: 403, body: { error: 'access denied' } } }
  }
  const asset = await files.read(`assets/${params.assetId}`)
  return { statusCode: 200, body: asset }
}
```

---

## No deep-merge of user input into plain objects — prototype pollution [error] [injection, security]

The existing bracket-access rule (`obj[params.key]`) catches direct property assignment. A separate attack path is deep-merge: `lodash.merge({}, userInput)` or `Object.assign` with nested objects where `userInput` contains `{"__proto__": {"isAdmin": true}}`. After the merge, every plain object in the process has `isAdmin` set. Schema-validate and strip unknown keys before any merge.

**Why it was missing:** The `detect-object-injection` ESLint rule and the existing bracket-access rule both catch the shallow case. Deep-merge pollution is a different code pattern (a function call, not a bracket access) requiring a separate rule. The two are often conflated but need distinct guards.

### Check
```yaml
type: builtin
id: unvalidated-deep-merge
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const _ = require('lodash')

async function main (params) {
  const config = _.merge({}, defaultConfig, params.userConfig)
  // params.userConfig = { "__proto__": { "isAdmin": true } }
  // now ({}).isAdmin === true for every plain object in this process
  return applyConfig(config)
}
```

### Good
```js
const { z } = require('zod')

const UserConfigSchema = z.object({
  theme: z.enum(['light', 'dark']),
  pageSize: z.number().int().min(10).max(100)
}).strict()   // .strict() rejects unknown keys including __proto__

async function main (params) {
  const parsed = UserConfigSchema.safeParse(params.userConfig)
  if (!parsed.success) return { error: { statusCode: 400, body: parsed.error.flatten() } }
  const config = Object.assign({}, defaultConfig, parsed.data)
  return applyConfig(config)
}
```

---

## SSRF defense — never make outbound HTTP to user-supplied URLs [error] [ssrf, injection]

Server-Side Request Forgery (OWASP 2021 #10): an action that calls `fetch(params.webhookUrl)` or `axios.get(params.callbackUrl)` without validation lets an attacker probe internal services — `http://169.254.169.254/` (cloud metadata, contains credentials), other actions in the namespace, or internal Adobe infrastructure. Parse the URL and validate the hostname against an explicit allowlist before making any outbound request.

**Why it was missing:** SSRF moved from obscure to OWASP Top 10 in 2021. The collection was seeded from pre-2021 OWASP patterns and didn't include this class. It has no ESLint rule equivalent because intent (is this URL user-supplied?) can't be inferred statically.

### Check
```yaml
type: builtin
id: ssrf-user-url
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  const res = await fetch(params.webhookUrl)   // attacker sets params.webhookUrl = 'http://169.254.169.254/latest/meta-data/iam/...'
  return { statusCode: 200, body: await res.json() }
}
```

### Good
```js
const ALLOWED_WEBHOOK_HOSTS = new Set(['hooks.slack.com', 'api.github.com', 'your-partner.com'])

async function main (params) {
  let url
  try { url = new URL(params.webhookUrl) } catch {
    return { error: { statusCode: 400, body: { error: 'invalid webhook URL' } } }
  }

  if (url.protocol !== 'https:' || !ALLOWED_WEBHOOK_HOSTS.has(url.hostname)) {
    return { error: { statusCode: 400, body: { error: 'webhook host not allowed' } } }
  }

  const res = await fetch(url.toString())
  return { statusCode: 200, body: await res.json() }
}
```

---

## Never spread params directly into a database write — mass assignment [error] [injection, auth]

Mass assignment (OWASP API Security #6): spreading `params` or a user-supplied object directly into a DB `insert`/`update` call lets the caller set any field — including `role`, `isAdmin`, `price`, `status`, fields they have no business touching. Always enumerate the fields you accept explicitly.

**Why it was missing:** The collection covered injection (SQL via string concat) but not the mass-assignment variant where a safe parameterized query is used but the set of columns being written is attacker-controlled. This is an API-specific threat model that OWASP API Security Top 10 covers but OWASP Web Top 10 doesn't surface as clearly.

### Check
```yaml
type: builtin
id: mass-assignment
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  await db.update('users', params, { where: { id: params.id } })
  // caller sends { id: 1, name: 'Alice', role: 'admin' } → role elevated
}
```

### Good
```js
async function main (params) {
  const ALLOWED_FIELDS = ['name', 'email', 'preferences', 'timezone']
  const update = Object.fromEntries(
    Object.entries(params).filter(([k]) => ALLOWED_FIELDS.includes(k))
  )
  if (Object.keys(update).length === 0) {
    return { error: { statusCode: 400, body: { error: 'no valid fields provided' } } }
  }
  await db.update('users', update, { where: { id: params.id } })
}
```
