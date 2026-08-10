# API Mesh — Caching

> Part of [API Mesh](./README.md).

## Cache only static reference data — never personalized responses [error] [caching, security]
Mesh field-level cache and CDN cache will gladly serve a personalized response to the wrong user if you let them. Cache `storeConfig`, country lists, taxonomy. Never cache cart, prices that depend on customer group, or anything carrying auth context.

### Bad
```json
{ "cache": [{ "field": "Query.cart", "invalidate": { "ttl": 300 } }] }
```

### Good
```json
{ "cache": [
  { "field": "Query.storeConfig", "invalidate": { "ttl": 600 } },
  { "field": "Query.countries", "invalidate": { "ttl": 3600 } }
] }
```

## Set explicit Cache-Control headers matching response semantics [warn] [caching, http]
Default headers vary by source. Without explicit `Cache-Control`, CDNs may cache responses they shouldn't (or fail to cache responses they should). Set `public, max-age=N` for cacheable; `private, no-store` for personalized.

### Bad
```text
// rely on upstream default
Cache-Control: <whatever upstream sent>
```

### Good
```text
// public, cacheable query
Cache-Control: public, max-age=600

// personalized
Cache-Control: private, no-store
```

## Align field-level cache TTLs with data volatility (30s–3600s typical) [info] [caching, perf]
TTL too short → cache miss rate dominates and the cache adds latency. Too long → stale data after upstream updates. 30s for moderately dynamic data, 5–15min for slow-moving config, 1h+ for static reference.

### Bad
```json
// blanket 60s for everything
{ "cache": [
  { "field": "Query.storeConfig", "invalidate": { "ttl": 60 } },
  { "field": "Query.countries",   "invalidate": { "ttl": 60 } },
  { "field": "Query.categoryNav", "invalidate": { "ttl": 60 } }
] }
```

### Good
```json
// per-field TTL aligned with volatility
{ "cache": [
  { "field": "Query.countries",   "invalidate": { "ttl": 3600 } },
  { "field": "Query.storeConfig", "invalidate": { "ttl": 600 } },
  { "field": "Query.categoryNav", "invalidate": { "ttl": 60 } }
] }
```

## Understand the three caching layers — mesh field, HTTP/CDN, upstream [warn] [caching, architecture]
A request can be served from (1) mesh field cache, (2) Adobe edge CDN / customer CDN, or (3) upstream's own cache (Fastly for Commerce). Conflicting TTLs across these layers create confusing staleness bugs. Document the cache topology and align TTLs.

### Bad
```text
// mesh cache 60s, Fastly 1h - Commerce update appears stale for an hour
mesh:    Cache-Control: max-age=60
fastly:  Cache-Control: max-age=3600
```

### Good
```text
// documented topology; mesh TTL <= CDN TTL <= upstream TTL
// with explicit purge strategy on upstream changes
mesh:    Cache-Control: max-age=60
fastly:  Cache-Control: max-age=300
upstream: invalidates Fastly via webhook on data change
```
