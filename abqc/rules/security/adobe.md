# Security — Adobe-specific

> Part of [Security](./README.md).

## Do not disable require-adobe-auth without a documented review [error] [auth, runtime]

Web actions ship with `require-adobe-auth: true` for a reason: the OOTB authentication layer validates the bearer token against Adobe IMS and authorization against Adobe Exchange. Setting it to `false` exposes the action to the public internet with no auth at all. If you must disable it (e.g., for a public webhook receiver), document the threat model in code review.

### Bad
```yaml
actions:
  process-order:
    web: 'yes'
    annotations:
      require-adobe-auth: false   # silently public
```

### Good
```yaml
actions:
  process-order:
    web: 'yes'
    annotations:
      require-adobe-auth: true
```

---

## Every web action must declare require-adobe-auth [warn] [auth, security]

The `require-adobe-auth` annotation controls whether Adobe I/O Runtime validates an IMS bearer token before invoking a web action. When the annotation is omitted entirely, the action is reachable anonymously over its public runtime URL — the same exposure as an explicit `false`, but silent, so it slips past review. Declare it on every web action: `true` to require an IMS token (the default posture), or `false` only for a deliberate public endpoint (which then also needs `allowedHosts`). This flags web actions with the annotation missing; the explicit-`false` case is covered by the "Do not disable require-adobe-auth without a documented review" rule above.

### Check
```yaml
type: yaml-path
condition: require-auth
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
actions:
  get-orders:
    function: actions/get-orders/index.js
    web: 'yes'
    # no require-adobe-auth annotation — silently anonymous over the public URL
```

### Good
```yaml
actions:
  get-orders:
    function: actions/get-orders/index.js
    web: 'yes'
    annotations:
      require-adobe-auth: true
```

---

## Never store secrets in State SDK — use Secret Vault [error] [secrets]

Adobe's Security Overview is explicit: "Do not store secrets using the State SDK." Use a Secret Vault (HashiCorp Vault, AWS Secrets Manager, Adobe-provisioned vault) and load values into action default params at deploy time.

### Bad
```js
const state = await State.init()
await state.put('db_password', 'hunter2')
```

### Good
```yaml
actions:
  query-db:
    inputs:
      DB_PASSWORD: $SECRET_DB_PASSWORD
```

---

## Default params are encrypted at rest — never log them [error] [secrets, logging]

Adobe encrypts default action params and decrypts them just before execution. Logging `params` directly leaks the decrypted secrets into the activation log, accessible to any user with namespace access. Always redact known secret keys before logging.

### Bad
```js
logger.debug('action invoked', { params })
```

### Good
```js
const SECRET_KEYS = new Set(['DB_PASSWORD', 'API_KEY', 'CLIENT_SECRET', 'authorization'])
const redact = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) =>
  [k, SECRET_KEYS.has(k) ? '[REDACTED]' : v]))
logger.debug('action invoked', { params: redact(params) })
```

---

## Add I/O Management API service for read_organizations scope [info] [scope, auth]

Headless integrations with Analytics, Campaign Standard, or No-API services require the `read_organizations` scope. That scope only gets minted into your IMS token if the I/O Management API service is added to your project. Without it, the integration silently 401s on the first call.

### Bad
```yaml
services:
  - code: AnalyticsAPI
```

### Good
```yaml
services:
  - code: AnalyticsAPI
  - code: IOManagementSDK
```

---

## Validate data residency for stored files — especially sensitive data [warn] [compliance, secrets]

Adobe's storage inherits Adobe's compliance posture by default, but if your app stores PII, PHI, payment data, or anything regulated by GDPR, CCPA, HIPAA, or industry-specific frameworks (PCI, SOC 2), confirm where the data physically lives and whether that satisfies your compliance requirements. For data that must stay in-region, use an external store you control.

### Bad
```js
// EU-based customer data stored without checking Files SDK region
await files.write(`customers/${customerId}/profile.json`, JSON.stringify(profile))
```

### Good
```js
// for regulated data, document the region requirement and use a region-pinned external store
const s3 = new S3Client({ region: 'eu-central-1' })   // GDPR — must stay in EU
await s3.send(new PutObjectCommand({
  Bucket: 'eu-customer-data',
  Key: `customers/${customerId}/profile.json`,
  Body: JSON.stringify(profile),
  ServerSideEncryption: 'AES256'
}))
```

---

## CORS Access-Control-Allow-Origin must not be * on authenticated actions [warn] [security, cors]

A web action that returns `Access-Control-Allow-Origin: *` on authenticated endpoints is misleading: browsers won't send cookies or `Authorization` headers to a wildcard-CORS origin, so it doesn't widen the actual attack surface — but it signals "open to all" to security scanners and auditors and creates confusion. On unauthenticated endpoints that return cross-org data, `*` is a real vulnerability. Use an explicit allowlist matched against the incoming `Origin` header.

**Why it was missing:** The collection had a CSP rule and an auth rule but no CORS policy rule. CORS is a separate HTTP-level control from both; its interaction with `require-adobe-auth` is non-obvious and the gap wasn't caught without a security-doc-specific sweep.

### Bad
```js
return {
  statusCode: 200,
  headers: {
    'access-control-allow-origin': '*',   // ok for public CDN assets; bad for API actions
    'content-type': 'application/json'
  },
  body: sensitiveData
}
```

### Good
```js
const ALLOWED_ORIGINS = new Set([
  'https://experience.adobe.com',
  'https://my-app.adobeioruntime.net'
])

async function main (params) {
  const origin = params.__ow_headers?.origin ?? ''
  const corsOrigin = ALLOWED_ORIGINS.has(origin) ? origin : ALLOWED_ORIGINS.values().next().value
  return {
    statusCode: 200,
    headers: {
      'access-control-allow-origin': corsOrigin,
      'vary': 'Origin',
      'content-type': 'application/json'
    },
    body: data
  }
}
```

---

## Validate x-gw-ims-org-id for tenant isolation in multi-org deployments [error] [auth, multitenancy]

Adobe I/O Runtime delivers the calling IMS organization's ID in `params.__ow_headers['x-gw-ims-org-id']`. For a single-org app this is informational. For a SaaS app deployed across multiple customer orgs from one namespace, failing to validate this header against the record being accessed means any authenticated user of *any* org can read or write another org's data — a broken object-level auth (IDOR) at the tenancy boundary.

**Why it was missing:** Single-org App Builder apps don't need this. The multi-tenancy pattern was implicit in Adobe's architecture but never called out as a rule. OWASP IDOR applies here but the Adobe-specific header name made the connection non-obvious without reading the security doc carefully.

### Bad
```js
async function main (params) {
  const orderId = params.orderId
  const order = await db.getOrder(orderId)   // any org can fetch any order
  return { statusCode: 200, body: order }
}
```

### Good
```js
async function main (params) {
  const callerOrg = params.__ow_headers?.['x-gw-ims-org-id']
  if (!callerOrg) return { error: { statusCode: 401, body: { error: 'missing org context' } } }

  const order = await db.getOrder(params.orderId)
  if (!order || order.imsOrgId !== callerOrg) {
    return { error: { statusCode: 403, body: { error: 'access denied' } } }
  }
  return { statusCode: 200, body: order }
}
```

---

## Request only the minimum IMS/OAuth scopes needed [warn] [auth, scope]

Each scope granted to an OAuth Server-to-Server credential expands the blast radius if the credential is compromised. Adobe's IMS system issues tokens scoped exactly to what the Developer Console project declares. Request only the scopes your action actually calls; over-scoped credentials that touch `write_organizations` or `creative_sdk` when all you need is `read_organizations` violate the principle of least privilege and widen the impact of a token leak.

**Why it was missing:** The scope rule was a narrow "remember to add IOManagementSDK" note (rule 9 in the previous set). Scope *minimization* — the discipline of not requesting more than you need — was the opposite but equally important concern, and it was absent.

### Bad
```yaml
# Developer Console project — requested all scopes "just in case"
scopes:
  - openid
  - read_organizations
  - write_organizations    # never used — just adds blast radius
  - creative_sdk           # not needed for this action
  - aem_assets_write       # not needed
```

### Good
```yaml
# Request only what the action's code actually calls
scopes:
  - openid
  - read_organizations    # only scope this action uses
```

---

## Browser SPAs use Authorization Code + PKCE — never the implicit flow [error] [auth, spa]

The OAuth 2.0 implicit flow is deprecated for SPAs because access tokens land in the URL fragment and browser history. Use Authorization Code with PKCE: the browser proves possession of a code verifier at the token exchange, no client secret needed, no token in URL.

### Bad
```js
// implicit flow — token in #fragment
window.location = `https://ims-na1.adobelogin.com/ims/authorize/v2?response_type=token&client_id=...`;
```

### Good
```js
// PKCE: generate verifier, send hashed challenge
const verifier = generateCodeVerifier();
const challenge = await sha256(verifier);
window.location = `https://ims-na1.adobelogin.com/ims/authorize/v2?response_type=code&code_challenge=${challenge}&code_challenge_method=S256&client_id=...`;
// after redirect: POST code + verifier to get token
```

---

## Never put client_secret, technical-account private keys, or S2S credentials in SPA bundles [error] [auth, secrets]

A `client_secret` or service account private key in a webpack bundle is harvested by anyone who opens DevTools. SPAs use PKCE for user auth; service-to-service credentials live in a Backend-for-Frontend (BFF) action.

### Bad
```jsx
// in front-end bundle
const CLIENT_SECRET = 'p8e-real-secret';
const PRIVATE_KEY = '-----BEGIN PRIVATE KEY-----...';
```

### Good
```jsx
// SPA only knows public client ID
const CLIENT_ID = process.env.PUBLIC_CLIENT_ID;
// secrets live in Runtime action, accessed via BFF endpoint
const r = await fetch('/api/v1/web/get-data', { headers: { Authorization: `Bearer ${userToken}` } });
```

---

## Tokens stored in memory or BFF-managed cookies — never localStorage when avoidable [warn] [auth, xss]

A single XSS that reaches `localStorage` exfiltrates every token stored there. Memory-only token storage limits exposure to the current session. For longer-lived sessions, use a BFF that issues an `HttpOnly; Secure; SameSite=Strict` session cookie — the token itself never reaches the JS layer.

### Bad
```js
localStorage.setItem('access_token', token); // any XSS reads this
```

### Good
```js
// memory-only for short-lived access tokens
let accessToken = null;
// or BFF session cookie
// Set-Cookie: session=...; HttpOnly; Secure; SameSite=Strict
```
