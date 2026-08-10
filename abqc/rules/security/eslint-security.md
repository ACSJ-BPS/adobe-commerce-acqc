# Security — eslint-plugin-security

> Part of [Security](./README.md).

## No bracket access on user-controlled keys — object injection [error] [injection]

`obj[params.key] = params.value` lets an attacker write to any property — including `__proto__`, `constructor`, `prototype`. This is the prototype-pollution attack class. `eslint-plugin-security`'s `detect-object-injection` flags it.

### Bad
```js
function setField (obj, params) {
  obj[params.key] = params.value   // attacker writes __proto__.isAdmin = true
}
```

### Good
```js
const ALLOWED_KEYS = new Set(['name', 'email', 'tier'])
function setField (obj, params) {
  if (!ALLOWED_KEYS.has(params.key)) return
  obj[params.key] = params.value
}
```

---

## No dynamic require() — non-literal-require [error] [injection]

`require(params.module)` lets an attacker load any installed module — including ones with side effects on import (`child_process`, `fs`). Always pass a string literal or a key from a vetted lookup.

### Bad
```js
const handler = require(params.handlerName)
```

### Good
```js
const HANDLERS = {
  email: require('./handlers/email'),
  sms: require('./handlers/sms'),
  push: require('./handlers/push')
}
const handler = HANDLERS[params.handlerName]
if (!handler) return { error: { statusCode: 400, body: 'unknown handler' } }
```

---

## No dynamic regex construction without anchoring [warn] [injection, redos]

`new RegExp(params.pattern)` lets the attacker inject a ReDoS pattern. `eslint-plugin-security`'s `detect-non-literal-regexp`. Either accept a fixed allow-list of patterns or escape the input and embed it as a literal substring.

### Bad
```js
const pattern = new RegExp(params.pattern)   // user supplies "(a+)+"
```

### Good
```js
function escapeRegex (s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') }
const pattern = new RegExp(escapeRegex(params.pattern))
```

---

## No dynamic fs paths from user input — non-literal-fs-filename [error] [injection, traversal]

`fs.readFile(params.path)` is path traversal: `params.path = '../../../etc/passwd'` reads outside the intended directory. `eslint-plugin-security`'s `detect-non-literal-fs-filename`. Resolve and validate every path against an allow-list base.

### Bad
```js
const fs = require('node:fs/promises')
const data = await fs.readFile(`./uploads/${params.filename}`, 'utf8')
```

### Good
```js
const path = require('node:path')
const UPLOADS = path.resolve('./uploads')
const target = path.resolve(UPLOADS, params.filename)
if (!target.startsWith(UPLOADS + path.sep)) {
  return { error: { statusCode: 400, body: 'invalid path' } }
}
const data = await fs.readFile(target, 'utf8')
```

---

## No pseudoRandomBytes for security-sensitive values [error] [crypto, randomness]

`crypto.pseudoRandomBytes` is non-cryptographic — predictable from a known seed. `eslint-plugin-security`'s `detect-pseudoRandomBytes`. Always use `crypto.randomBytes` or `crypto.randomUUID` for tokens, salts, IVs.

### Bad
```js
const { pseudoRandomBytes } = require('node:crypto')
const sessionId = pseudoRandomBytes(16).toString('hex')
```

### Good
```js
const { randomBytes } = require('node:crypto')
const sessionId = randomBytes(16).toString('hex')
```

---

## Use crypto.timingSafeEqual for token / HMAC comparison [warn] [crypto, timing]

Comparing tokens with `===` or `Buffer.compare` short-circuits on the first mismatched byte. `eslint-plugin-security`'s `detect-possible-timing-attacks`. The timing leak lets an attacker brute-force one byte at a time. Use `crypto.timingSafeEqual` for any secret-equality check.

### Bad
```js
if (params.token === expectedToken) return grant()
```

### Good
```js
const { timingSafeEqual } = require('node:crypto')
const a = Buffer.from(params.token, 'utf8')
const b = Buffer.from(expectedToken, 'utf8')
if (a.length === b.length && timingSafeEqual(a, b)) return grant()
return reject()
```

---

## Reject Unicode bidi characters in source — trojan source attack [error] [supply-chain]

Bidi control characters (U+202A–U+202E, U+2066–U+2069) reorder how source code is rendered without changing the bytes — letting attackers hide malicious code in plain sight. `eslint-plugin-security`'s `detect-bidi-characters`. CI should reject any commit containing them.

### Bad
```js
// access_level = "user\u202E \u2066// Check if admin\u2069 \u2066"; // bidi-hidden malice
```

### Good
```js
// no bidi characters anywhere in source
const accessLevel = 'user'   // straightforward, render-stable
```
