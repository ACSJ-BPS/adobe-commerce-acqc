# App Builder — Deploy

> Part of [App Builder](./README.md).

## API Gateway provisioning takes 5–10 minutes — plan deploys around it [warn] [deploy, gateway]

When you add or change an `apis:` block in `app.config.yaml`, the gateway takes 5–10 minutes to provision. Calls during that window 404. Don't deploy gateway changes immediately before a release, demo, or customer-facing event.

### Bad

```yaml
# committed at 14:55, deploy at 14:58, demo at 15:00
apis:
  orders:
    /v1:
      orders:
        process-order:
          method: post
          response: json
```

### Good

```yaml
# deploy gateway changes the day before, smoke-test, then ship code
apis:
  orders:
    /v1:
      orders:
        process-order:
          method: post
          response: json
```

---

## disable-download is irreversible — set deliberately [warn] [deploy, security]

Once `disable-download: true` is set on an action, it cannot be reverted. The action's source can no longer be retrieved via `wsk action get --code`. This is good for protecting proprietary code but bad if you set it by accident. Treat it as a one-way door.

### Bad

```yaml
# copy-pasted from another action, didn't realize the implication
actions:
  utility:
    function: actions/utility/index.js
    annotations:
      disable-download: true
```

### Good

```yaml
actions:
  proprietary-pricing:
    function: actions/proprietary-pricing/index.js
    annotations:
      disable-download: true   # intentional — pricing logic must stay private
```

---

## Know your license-pack quotas — design within them [warn] [quota, scale]

Each App Builder license pack ships with hard ceilings: **6 million GB-seconds of compute per year**, **24 million custom events per year**, **10 TB of CDN egress per year**, **1 TB of file storage**, and **~900 read/write ops per second on the State key-value store**. Limits aggregate across packs. An action burning a 1 GB container for 60 s consumes ~60 GB-s — a 100k-invocation/day workload at that profile exhausts a single pack's compute in ~2.7 years before any other action runs. Monitor usage in the Adobe Developer Console; design fan-out and memory tier with the budget in mind.

### Bad

```js
async function main (params) {
  // 1024 MB action that runs for 50s on every invocation
  // 100k/day → 5.1 GB-s × 100k = 510k GB-s/day → 1 pack burned in ~12 days
  await heavyWork(params)
}
```

### Good

```js
async function main (params) {
  // Right-size: 256 MB tier + sub-second handler + fan-out for big jobs
  if (params.batchSize > 100) {
    return openwhisk().triggers.invoke({ name: 'heavy-work-trigger', params })
  }
  return await lightWork(params)
}
```

---

## Don't ship raw <namespace></namespace>.adobeioruntime.net URLs as customer-facing endpoints [warn] [deploy, urls]

Raw runtime URLs leak the App Builder namespace structure, are tied to a single workspace, and cannot be migrated without a customer-visible breaking change. For any productized API surface (webhook receivers, public callbacks, partner integrations), front the runtime with a vanity domain + CDN.

### Bad

```text
Customer integration doc:
"POST your webhook to https://12345-acme-prod.adobeioruntime.net/api/v1/web/receive"
```

### Good

```text
Customer integration doc:
"POST your webhook to https://api.acme.example.com/v1/webhooks/receive"
// CDN routes to the runtime URL; runtime URL can change without breaking customers
```

---

## Source runtime base URLs from config — never hard-code them in front-end code [warn] [config, urls]

Front-end code with `https://12345-acme-prod.adobeioruntime.net/...` baked in cannot be deployed to a different workspace, and cannot switch to a vanity domain without a code change. Inject the base URL via `app.config.yaml` inputs or env vars at build time.

### Bad

```jsx
const API = 'https://12345-acme-prod.adobeioruntime.net/api/v1/web';
const r = await fetch(`${API}/orders`);
```

### Good

```jsx
const API = process.env.RUNTIME_BASE_URL; // injected at build time
const r = await fetch(`${API}/orders`);
```
