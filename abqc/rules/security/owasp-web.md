# Security — OWASP Web Top 10

> Part of [Security](./README.md).

## Force HTTPS for all third-party integrations [error] [transport]

Adobe's Security Overview requires HTTPS for all integrating third-party systems. A plain `http://` call from inside an action sends bearer tokens, customer data, and IMS credentials over the open network.

### Check
```yaml
type: builtin
id: http-literal-url
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
await fetch('http://legacy.partner.com/api/sync', { headers: { Authorization: `Bearer ${token}` } })
```

### Good
```js
await fetch('https://legacy.partner.com/api/sync', { headers: { Authorization: `Bearer ${token}` } })
```

---

## Sanitize all user-controlled HTML before render [error] [xss]

Cross-site scripting is the highest-frequency front-end CVE class. React escapes by default, but `dangerouslySetInnerHTML`, `<a href={...}>` with `javascript:` URLs, and any direct DOM manipulation reopens the door.

### Check
```yaml
type: builtin
id: sanitize-dangerously-set-inner-html
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<a href={user.website}>{user.name}</a>
// user.website = "javascript:alert(document.cookie)"
```

### Good
```jsx
const safeHref = (url) => {
  try { return ['http:', 'https:'].includes(new URL(url).protocol) ? url : '#' }
  catch { return '#' }
}
<a href={safeHref(user.website)} rel="noopener noreferrer">{user.name}</a>
```

---

## Use parameterized queries / prepared statements only [error] [sqli]

String-concatenated SQL is the textbook injection vector. Every database client supported by App Builder takes parameterized queries — there is no performance reason to concatenate.

### Check
```yaml
type: builtin
id: sql-string-concat
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
await db.query(`SELECT * FROM orders WHERE customer_id = '${params.customerId}'`)
```

### Good
```js
await db.query('SELECT * FROM orders WHERE customer_id = $1', [params.customerId])
```

---

## Hash passwords with bcrypt or argon2, never SHA / MD5 [error] [crypto, passwords]

SHA-256 and MD5 are fast hash functions — exactly what you don't want for passwords. A modern GPU computes billions per second. Use `bcrypt`, `argon2`, or `scrypt` with appropriate cost parameters; they're slow by design.

### Check
```yaml
type: builtin
id: weak-password-hash
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const { createHash } = require('node:crypto')
const hash = createHash('sha256').update(password).digest('hex')
```

### Good
```js
const bcrypt = require('bcrypt')
const hash = await bcrypt.hash(password, 12)
```

---

## Set Content-Security-Policy on web responses [warn] [headers, xss]

A web action returning HTML or driving a SPA should ship a CSP header to constrain script/style sources. Without one, a single XSS escape becomes full account takeover. Start with a strict baseline and loosen only as needed.

### Check
```yaml
type: builtin
id: missing-csp-header
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
return {
  statusCode: 200,
  headers: { 'content-type': 'text/html' },
  body: html
}
```

### Good
```js
return {
  statusCode: 200,
  headers: {
    'content-type': 'text/html',
    'content-security-policy': "default-src 'self'; script-src 'self' 'nonce-${nonce}'; object-src 'none'",
    'x-frame-options': 'DENY',
    'strict-transport-security': 'max-age=63072000; includeSubDomains'
  },
  body: html
}
```

---

## TLS certificate verification must never be disabled [error] [transport]

Setting `rejectUnauthorized: false` on an `https.Agent` (or defaulting a `VERIFY_SSL` constant to `false`) disables TLS certificate verification for all outbound HTTPS calls. This allows any attacker who can intercept the network path to perform a man-in-the-middle attack — silently reading SAP payloads, IMS tokens, or customer data. This is especially dangerous in cloud-to-cloud traffic between App Builder I/O Runtime and enterprise backends (SAP, Salesforce, etc.). The default for any SSL verification flag must be `true`; only disable for local developer environments via an explicit opt-in env var, never as a code default.

### Check
```yaml
type: builtin
id: tls-verification-disabled
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const SAP_VERIFY_SSL_DEFAULT = false;  // disables TLS verification by default

const agent = new https.Agent({ rejectUnauthorized: verifySSL });  // when verifySSL defaults to false
```

### Good
```js
const SAP_VERIFY_SSL_DEFAULT = true;  // always verify in production

// Only allow override via explicit env var — never default to false
const verifySSL = process.env.SAP_VERIFY_SSL === 'false' ? false : true;
const agent = new https.Agent({ rejectUnauthorized: verifySSL });
```
