# Deployment — Releases

> Part of [Deployment](./README.md).

## Don't undeploy a production action — version it instead [error] [pipeline, safety]

`aio app undeploy` removes the action from Runtime entirely. Any caller still pointing at it gets 404. Use versioned action names (`process-order-v2`) and route traffic via the API gateway when changing behavior; deprecate the old action with a sunset header before deleting.

### Check
```yaml
type: builtin
id: undeploy-prod-action
scope: repo
```

### Bad
```bash
# breaking change to process-order — undeploy, then deploy new version
aio app undeploy --action process-order
aio app deploy   # 404s for any in-flight caller
```

### Good
```yaml
# manifest.yml — versioned actions, gateway-routed
actions:
  process-order-v2:
    function: actions/process-order-v2/index.js
  process-order:    # old version, returns Sunset header
    function: actions/process-order-v1/index.js
apis:
  orders:
    /v1/orders: { method: post, action: process-order }
    /v2/orders: { method: post, action: process-order-v2 }
```

```js
// inside process-order v1 — return Sunset header per RFC 8594
return {
  statusCode: 200,
  headers: { 'Sunset': 'Wed, 31 Dec 2026 23:59:59 GMT', 'Deprecation': 'true' },
  body: result
}
```

---

## Tag and document every prod release — single source of truth [info] [release, ops]

Every Production deploy should produce: a git tag, a changelog entry, a release notes doc (Slack / Confluence / GitHub Release), and a link to the activation dashboard for that deploy. When something breaks, the next on-call needs to know what changed without digging through commits.

### Check
```yaml
type: builtin
id: untagged-prod-deploy
scope: repo
```

### Bad
```text
"What changed in last night's prod deploy?"
"Uhh, look at git log between two unknown commits and figure it out"
```

### Good
```text
GitHub Release `prod-2026-04-29-1430`:
  - Title: "Production 2026-04-29 14:30"
  - Tag: prod-2026-04-29-1430
  - Body:
      Changes:
        - feat(orders): add bulk-cancel action (#231)
        - fix(checkout): handle missing tax region (#234)
      Risk: low (additive only)
      Verification: smoke green, e2e green at 14:32
      Rollback: ./scripts/rollback.sh prod-2026-04-29-0900
      Activation dashboard: <link>
```
