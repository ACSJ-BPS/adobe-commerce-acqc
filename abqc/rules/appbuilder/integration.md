# App Builder — Integration

> Part of [App Builder](./README.md).

## AEM Cloud Service / 6.5+ and Commerce have native extension points; others go via API [info] [integration]

Out of the box, App Builder ships **dx/excshell/1** SPA extensions for Experience Cloud, **dx/asset-compute/worker/1** for AEM workers, and Commerce-specific extensibility. For Adobe Analytics, Campaign Standard, Target, etc., there is no defined extension point — you call their APIs from custom actions and render in your own SPA. Recognize the boundary so estimates and architecture stay realistic.

### Check
```yaml
type: builtin
id: extension-point-enum
scope: repo
```

### Bad
```yaml
# trying to surface an Analytics report as a "Campaign extension" — no such hook
extensions:
  acom/campaign/1:    # not a real extension point
    operations:
      view: { type: web, impl: index.html }
```

### Good
```yaml
extensions:
  dx/excshell/1:
    operations:
      view: { type: web, impl: index.html }
runtimeManifest:
  packages:
    analytics:
      actions:
        run-report:
          function: actions/run-report/index.js   # calls Analytics API directly
```

---

## Default runtime is Node.js — other languages only via Docker [info] [runtime, language]

I/O Runtime's first-class tooling targets Node.js. Other languages (Python, Java, Swift, Go) are supported only by deploying a custom Docker image — significantly more setup, slower cold starts, no `aio-sdk` ergonomics. Stick to Node unless you have a compelling reason (e.g., a Python ML library you can't replace).

### Check
```yaml
type: builtin
id: non-node-runtime
files: ["**/*.config.yaml", "**/*.config.yml", "**/app.config.yaml"]
```

### Bad
```yaml
# Java action without explaining why — losing aio-sdk ecosystem benefits
actions:
  process-payment:
    function: actions/process-payment/jar/payment.jar
    runtime: java:8
```

### Good
```yaml
actions:
  process-payment:
    function: actions/process-payment/index.js
    runtime: nodejs:20
    # uses @adobe/aio-sdk, full aio CLI tooling, fast cold starts
```

---

## Call Commerce GraphQL with app-level credentials from secrets — never user secrets [error] [auth, commerce]

Commerce GraphQL queries from App Builder actions must use app-level credentials (OAuth S2S or admin integration token) stored in Dev Console secrets, not credentials harvested per-user. User credentials in shared apps risk leaking across tenants and break when a user's password changes.

### Check
```yaml
type: builtin
id: commerce-graphql-app-creds
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
// stored per-user in State, refreshed by user login
const token = await state.get(`commerce-token:${userId}`);
const r = await fetch(COMMERCE_GQL, { headers: { Authorization: `Bearer ${token}` }});
```

### Good
```js
// app-level S2S token, cached in Runtime
const token = await getCachedAppToken();
const r = await fetch(COMMERCE_GQL, { headers: { Authorization: `Bearer ${token}` }});
```

---

## Never call Commerce admin GraphQL with admin credentials from the browser [error] [auth, security]

Storefront SPAs that hit Commerce admin GraphQL with admin tokens (or that store any admin token in the browser) expose the entire store to a single XSS. Browser → Runtime action → Commerce is the only safe pattern; admin credentials live server-side.

### Check
```yaml
type: builtin
id: no-commerce-admin-from-spa
```

### Bad
```jsx
const r = await fetch('https://store.example.com/graphql', {
  headers: { Authorization: `Bearer ${ADMIN_TOKEN}` }, // in browser bundle
});
```

### Good
```jsx
const r = await fetch('https://<ns>.adobeioruntime.net/api/v1/web/products', {
  headers: { Authorization: `Bearer ${imsTokenFromUix}` },
});
// runtime action exchanges IMS token for admin token server-side
```

---

## Pass store-view/website context explicitly — never inferred from headers [warn] [commerce, multi-store]

Multi-website Commerce stores have per-website pricing, taxes, currency, and product visibility. Resolving store context from `Accept-Language` or hostname inference produces subtle wrong-store bugs. Pass the store code as an explicit argument from the SPA and propagate to Commerce via `Store` header.

### Check
```yaml
type: builtin
id: no-store-context-from-headers
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
// guessed from request headers
const store = inferStoreFromHostname(params.__ow_headers.host);
```

### Good
```js
const store = params.storeCode; // explicit from SPA
const r = await fetch(COMMERCE_GQL, { headers: { Store: store, Authorization: `Bearer ${token}` }});
```

---

## Bound page sizes when calling Commerce GraphQL — no unbounded pageSize [warn] [commerce, performance]

Commerce GraphQL accepts large `pageSize` values that can OOM both Commerce and the action. Always cap on the action side, even if the SPA promises sane values. Combine with cursor pagination for full traversals.

### Check
```yaml
type: builtin
id: bound-graphql-pagesize
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
const q = `{ products(filter: {}, pageSize: ${args.pageSize}) { items { sku } } }`;
```

### Good
```js
const pageSize = Math.min(args.pageSize ?? 24, 100);
const q = `{ products(filter: {}, pageSize: ${pageSize}, currentPage: ${page}) { items { sku } } }`;
```
