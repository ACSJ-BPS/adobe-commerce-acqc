# Documentation — Project

> Part of [Documentation](./README.md).

## Every action has a README — purpose, inputs, outputs, examples [warn] [readme, actions]

Each `actions/<name>/` folder gets a `README.md` that describes the action's purpose, required and optional inputs, output shape, error responses, sample invocation, and any non-obvious dependencies (external APIs called, state keys read/written). Readers shouldn't have to clone the repo to understand what an action does.

### Check
```yaml
type: builtin
id: action-readme
scope: repo
```

### Bad
```text
actions/process-order/
  index.js
  package.json
  test/
  # no README — reviewer has to read 200 lines of JS to understand contract
```

### Good
````markdown
# actions/process-order

Validates and charges an order, reserves inventory, sends confirmation email.

## Inputs

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `orderId` | string (UUID) | yes | Order to process |
| `region` | string | yes | ISO 3166-1 alpha-2 |
| `idempotencyKey` | string | no | Dedup key, defaults to orderId |

## Output

```json
{ "statusCode": 200, "body": { "orderId": "...", "total": 99.95, "items": [...] } }
```

## Errors

| Status | When |
| --- | --- |
| 400 | Missing or malformed `orderId` / `region` |
| 409 | Inventory unavailable for one or more line items |
| 502 | Upstream payment provider unavailable |

## External calls

- Adobe Commerce API: `POST /rest/V1/orders/{orderId}/process`
- Stripe: `POST /v1/charges`

## Sample invocation

```bash
aio runtime action invoke commerce/process-order \
  --param orderId 12345 --param region US --result
```
````

---

## docs/data-access.md — single source of truth for what data the app touches [warn] [governance, data]

Mirror of the security.md rule, surfaced here because it's a documentation artifact: a `docs/data-access.md` table listing every data source, what data is read, where it's stored, retention/TTL, and third parties involved. Updated on every PR that changes data shape. This is what a security reviewer or admin asks to see before approving the app.

### Check
```yaml
type: builtin
id: data-access-doc
scope: repo
```

### Bad
```text
# scattered: README says one thing, runbook says another, Confluence is six months stale
```

### Good
```markdown
# Data Access

| Source | Data read | Stored where | Retention | Third party |
| --- | --- | --- | --- | --- |
| AEM Assets API | asset metadata, binary | Files SDK `/assets/` prefix | 30 days | none |
| IMS profile | email, org id | request-scope only | n/a | none |
| Stripe | card token, customer id | external Postgres `customers` | 7 years | Stripe (PCI DSS) |
| Slack | none (write-only) | n/a | n/a | Slack |

Updated 2026-04-29 with PR #234.
```
