# Security — Supply Chain & Governance

> Part of [Security](./README.md).

## npm audit must be clean of high/critical before deploy [warn] [deps, supply-chain]

Adobe's hosted Runtime won't block a deploy with vulnerable dependencies, but a critical CVE in a transitive dep is your namespace's risk. Run `npm audit --audit-level=high` in CI; document expirable exceptions for unfixable transitive issues.

### Bad
```bash
npm install   # 12 high, 3 critical advisories
aio app deploy
```

### Good
```bash
npm audit --audit-level=high
npm audit fix
aio app deploy
```

---

## No hard-coded API keys in source / params / git history [error] [secrets]

A key committed to git is compromised the moment the commit lands — assume it's already harvested. Use `.env` (gitignored) for local dev, default action params injected from a vault for production, and rotate any key that ever appeared in source.

### Check
```yaml
type: builtin
id: inline-secret
files: ["**/*.{js,cjs,mjs,ts,tsx}", "**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```js
const stripe = require('stripe')('<hardcoded-secret-key>')
```

### Good
```js
const stripe = require('stripe')(process.env.STRIPE_KEY)
```

---

## App Builder apps require admin approval before wide release [info] [governance]

Custom App Builder apps in an enterprise IMS organization require an Adobe org admin to approve them before they're available to end users. This is governance built into the platform. Plan for it: surface a clear description of the app's purpose, scopes, and data access at submission time, and don't promise stakeholders an availability date that doesn't account for the approval window.

### Bad
```text
Project plan: dev complete Friday, GA Monday.
(Admin approval pipeline not factored into the timeline; goes live two weeks late.)
```

### Good
```text
Project plan:
  - dev complete Friday
  - submit for admin approval (3-5 business days)
  - GA target two weeks after submission
  - prepare app description, scope rationale, data access doc for review
```

---

## Document what data your app accesses or stores — review-ready data sheet [info] [governance, compliance]

When stakeholders or admins review the app, they need a single document that explains: which Adobe APIs are called, what user data flows through actions, what gets persisted (Files / State / external DB), retention/TTL, and which third-party services are involved. Keep this in `docs/data-access.md` (or similar) and update it on every PR that changes the data shape.

### Bad
```text
README.md says "this app does AEM stuff" — admin asks "what data?" — nobody knows
without reading every action.
```

### Good
```text
docs/data-access.md:

| Source | Data | Stored where | Retention | Third party |
| --- | --- | --- | --- | --- |
| AEM Assets API | asset metadata, binary | Files SDK, /assets/ prefix | 30d TTL | none |
| IMS profile | email, org id | not stored (request-scope) | n/a | none |
| Slack webhook | notification text | not stored | n/a | Slack |
| Postgres | order audit log | external RDS | 7 years | AWS RDS, eu-central-1 |
```

---

## Log security-relevant events — authentication outcomes and authorization denials [warn] [security, observability]

The existing logging rules say what *not* to log (secrets, raw params). This rule covers what you *must* log for security observability: authentication failures, authorization denials, high-privilege operations, and unexpected input patterns. Without this audit trail, you can't detect brute-force attempts, credential stuffing, or privilege escalation in post-incident review.

**Why it was missing:** "Security logging failures" is OWASP Top 10 2021 #9. The collection covered operational logging (Core.Logger pattern) and redaction (secrets), but had no positive requirement for what to log. The two concerns look similar but are orthogonal.

### Bad
```js
async function main (params) {
  const token = getBearerToken(params)
  if (!token) return { error: { statusCode: 401, body: { error: 'unauthorized' } } }
  // no log entry — silent auth failure, invisible in activation log
  return processRequest(params)
}
```

### Good
```js
async function main (params) {
  const logger = Core.Logger('main', { level: params.LOG_LEVEL || 'info' })
  const token = getBearerToken(params)

  if (!token) {
    logger.warn('auth_failure', {
      reason: 'missing_bearer',
      activationId: params.__ow_activation_id,
      org: params.__ow_headers?.['x-gw-ims-org-id']
    })
    return { error: { statusCode: 401, body: { error: 'unauthorized' } } }
  }

  logger.info('auth_success', { org: params.__ow_headers?.['x-gw-ims-org-id'] })
  return processRequest(params)
}
```

---

## Never put API keys or tokens in URL query parameters [error] [secrets, logging]

Query parameters appear in: server access logs, CDN edge logs, browser history, `Referer` headers on outbound links, and Analytics/RUM tools. A token in `?api_key=sk_live_xxx` is a credential that logs itself. Always transmit secrets in request headers (`Authorization`, `X-Api-Key`). This is separate from the "no hardcoded keys" rule — here the key may be loaded from a vault but still leaked by being placed in the URL.

**Why it was missing:** The "no hardcoded keys" rule covers keys in source code. URL-based key leakage is a runtime data-flow problem, invisible to static analysis. It required explicit OWASP treatment to surface as a distinct rule.

### Bad
```js
// key loaded from vault correctly, but leaked via URL
const res = await fetch(`https://api.partner.com/v1/orders?api_key=${params.PARTNER_KEY}`)
```

### Good
```js
const res = await fetch('https://api.partner.com/v1/orders', {
  headers: {
    'X-Api-Key': params.PARTNER_KEY,
    'Content-Type': 'application/json'
  }
})
```

---

## Pin exact versions for security-sensitive packages — no semver ranges [warn] [supply-chain, deps]

Floating semver ranges (`^1.2.3`, `~1.2.3`) in `package.json` mean a supply-chain attacker who compromises a patch release of `axios`, `jsonwebtoken`, or `@adobe/aio-sdk` can push malicious code into your action's next install. Use exact pinning (`"axios": "1.7.2"`) for packages with network, crypto, or filesystem access, and rely on `package-lock.json` integrity checks in CI. `npm ci` (not `npm install`) is mandatory in the deploy pipeline for this to be effective.

**Why it was missing:** The `npm audit` rule covers known CVEs in existing installs. Supply-chain via future patch releases is a different attack surface — it requires version pinning as a separate, proactive control. OWASP SCA methodology highlights this as a distinct category from vulnerability scanning.

### Check
```yaml
type: builtin
id: pin-versions
scope: repo
```

### Bad
```json
{
  "dependencies": {
    "axios": "^1.6.0",
    "@adobe/aio-sdk": "^7.0.0",
    "jsonwebtoken": "^9.0.0"
  }
}
```

### Good
```json
{
  "dependencies": {
    "axios": "1.7.2",
    "@adobe/aio-sdk": "7.1.0",
    "jsonwebtoken": "9.0.2"
  }
}
```

```yaml
# CI must use npm ci, not npm install
- run: npm ci                  # uses lock file, rejects version drift
- run: npm audit --audit-level=high
```
