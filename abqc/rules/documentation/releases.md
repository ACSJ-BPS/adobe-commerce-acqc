# Documentation — Releases

> Part of [Documentation](./README.md).

## Maintain CHANGELOG.md — every release entry, semver [warn] [changelog, releases]

A `CHANGELOG.md` at project root, written manually (or via `conventional-changelog`), is the single place to read "what changed between v1.4.2 and v1.5.0." Keep [Keep a Changelog](https://keepachangelog.com/) format: grouped by Added / Changed / Deprecated / Removed / Fixed / Security. Bump versions per [SemVer](https://semver.org/).

### Bad
```text
# no changelog
# release notes only in random Slack messages
# "what changed in this version?" → "uhh, git log?"
```

### Good
```markdown
# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.0] - 2026-04-29

### Added
- `process-order` action now supports bulk cancellation (#231)
- Optional `idempotencyKey` param on all order actions

### Fixed
- Tax calculation for EU orders missing `region` (#234)

### Security
- Bumped `axios` to 1.7.4 (CVE-2024-XXXX)

## [1.4.2] - 2026-04-15
...
```

---

## Document action contracts — schema for inputs, outputs, errors [info] [contracts, schema]

Beyond the README narrative, machine-readable schemas (JSON Schema, Zod, OpenAPI) for action inputs and outputs let you generate validation, types, and API docs from one source. Especially valuable when a SPA on the Unified Shell calls many actions — the schema becomes the API surface.

### Bad
```js
// validation hardcoded in main, types scattered across JSDoc, OpenAPI written by hand and stale
async function main (params) {
  if (!params.orderId) return errorResponse(400, 'missing orderId')
  if (typeof params.orderId !== 'string') return errorResponse(400, 'orderId must be string')
  // ...
}
```

### Good
```js
const { z } = require('zod')

const InputSchema = z.object({
  orderId: z.string().uuid(),
  region: z.enum(['US', 'EU', 'APAC']),
  idempotencyKey: z.string().optional()
})

const OutputSchema = z.object({
  statusCode: z.literal(200),
  body: z.object({ orderId: z.string(), total: z.number(), items: z.array(z.any()) })
})

async function main (params) {
  const parsed = InputSchema.safeParse(params)
  if (!parsed.success) {
    return { error: { statusCode: 400, body: parsed.error.format() } }
  }
  // ...
}

// the schemas can also export to JSON Schema / OpenAPI for docs and TS types
module.exports = { main, InputSchema, OutputSchema }
```

---

## Sample request / response in every API doc — copy-pasteable [info] [api-docs, examples]

Action documentation that says "POST `/orders/process` with an order body" is useless without a real example. Every documented action surface gets a literal `curl` (or `aio runtime action invoke`) snippet plus the actual JSON response, so a new integrator can be productive in 60 seconds.

### Bad
```markdown
## POST /v1/orders/process

Processes an order. Request body must contain order details.
Returns the processed order.
```

### Good
````markdown
## POST /v1/orders/process

Validates the order, charges payment, reserves inventory.

### Request

```bash
curl -X POST https://<namespace>.adobeioruntime.net/api/v1/web/orders/process \
  -H "Authorization: Bearer $IMS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "orderId": "abc-123", "region": "US" }'
```

### Response (200)

```json
{
  "orderId": "abc-123",
  "total": 99.95,
  "items": [{ "sku": "X1", "qty": 2 }],
  "processedAt": "2026-04-29T14:30:00Z"
}
```

### Errors

```json
// 400 — invalid input
{ "error": "validation failed", "issues": { "orderId": "must be uuid" } }

// 409 — inventory shortfall
{ "error": "inventory unavailable", "items": ["X1"] }
```
````
