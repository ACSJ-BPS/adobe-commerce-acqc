# API Mesh — Performance

> Part of [API Mesh](./README.md).

## Avoid N+1 in additionalResolvers — batch upstream calls or add bulk endpoints [error] [performance, n+1]
A naive resolver that calls a REST endpoint per item turns a 50-item list query into 50 upstream HTTP calls. Either batch with `dataloader`-style aggregation, add a bulk endpoint upstream, or denormalize the relationship at ingest.

### Bad
```js
resolve: async (root) => Promise.all(root.products.map(p => fetch(`/pricing/${p.id}`)))
```

### Good
```js
resolve: async (root) => fetch('/pricing/bulk', { method: 'POST', body: JSON.stringify({ ids: root.products.map(p => p.id) }) })
```

## Validate args in resolvers — reject extreme pageSize/limit [warn] [performance, validation]
A query like `products(pageSize: 100000)` can OOM the mesh worker or hammer upstream. Resolvers must validate args (e.g., `pageSize <= 100`) and reject early. Mesh does not enforce this for you.

### Bad
```js
resolve: (_, args) => fetch(`/products?size=${args.pageSize}`) // unbounded
```

### Good
```js
resolve: (_, args) => {
  if (args.pageSize > 100) throw new GraphQLError('pageSize must be <= 100');
  return fetch(`/products?size=${args.pageSize}`);
}
```

## Design queries to complete well under the 60s edge timeout [warn] [performance, timeout]
API Mesh enforces a hard ~60s timeout at the edge. Sequence steps and slow upstreams that consume even half of that leave no headroom for retries or spikes. Target a p95 well under 5s for storefront queries.

### Bad
```js
// resolver does three sequential 10s upstream calls
const a = await fetchA(); const b = await fetchB(a); const c = await fetchC(b);
```

### Good
```js
const [a, b] = await Promise.all([fetchA(), fetchB()]);
const c = await fetchC(a, b); // only chain when there's a real dependency
```

## Don't rely on mesh for query depth or cost limits — they aren't enforced today [warn] [security, limits]
Adobe's API Mesh roadmap lists query depth limits, cost analysis, and persisted queries as not-yet-implemented. Don't assume they'll catch a malicious deep nested query. Implement at the resolver layer or with operation allowlisting in your client.

### Bad
```graphql
# trusting mesh to reject:
query { user { friends { friends { friends { friends { name } } } } } }
```

### Good
```js
// client uses persisted-query allowlist; resolver checks depth on suspicious paths
if (depth(info) > 5) throw new GraphQLError('Query too deep');
```

## Don't rely on mesh for per-tenant rate limiting — enforce upstream [warn] [reliability, rate-limit]
Cloudflare WAF protects mesh against DDoS, but per-tenant or per-customer rate limits are not exposed to mesh customers today. Enforce at the upstream service or via a Runtime action with State-backed counters.

### Bad
```text
// assume mesh will throttle a noisy tenant — it won't
```

### Good
```js
// Runtime action checks tenant rate before mesh queries proceed
const count = await state.get(`rate:${tenantId}`);
if (count > 1000) throw new Error('Rate limit exceeded');
await state.put(`rate:${tenantId}`, count + 1, { ttl: 60 });
```

## Hooks run per-request — keep fast and deterministic; no slow external calls [warn] [performance, hooks]
`beforeAll` and similar hooks fire on the hot path of every GraphQL request. Doing token-mint, DB lookups, or feature-flag fetches there adds latency to every query. Pre-cache; defer to async paths where possible.

### Bad
```js
beforeAll: async (ctx) => {
  ctx.token = await ims.mint();              // ~200ms
  ctx.flags = await launchDarkly.getFlags(); // ~150ms
  await remoteLog(ctx);                       // ~100ms
}
```

### Good
```js
beforeAll: async (ctx) => {
  ctx.token = await getCachedToken(); // State-backed, ~5ms
  ctx.requestId = crypto.randomUUID();
  // logs go to stdout, forwarded async
}
```
