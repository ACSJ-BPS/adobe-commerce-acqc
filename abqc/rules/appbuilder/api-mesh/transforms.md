# API Mesh — Transforms & Resolvers

> Part of [API Mesh](./README.md).

## Use filterSchema to remove admin or debug operations from the public schema [error] [security, schema]
Upstream sources often expose mutations and queries intended for internal use (`adminDeleteUser`, `debugDumpCache`). Without explicit filtering, mesh re-exposes them. The `filterSchema` transform removes them from the generated schema entirely.

### Bad
```json
{ "transforms": [] }
// storefront mesh exposes Mutation.adminDeleteUser because the upstream schema has it
```

### Good
```json
{ "transforms": [{ "filterSchema": { "filters": ["Mutation.!admin*", "Query.!debug*"] } }] }
```

## Use prefix and rename for a coherent, frontend-friendly schema [info] [schema, ux]
Upstream type names (`commerce_v2_product_t`) leak vendor naming into your client code. The `prefix` and `rename` transforms let you present `Product` cleanly. Apply them once, version them, don't churn.

### Bad
```graphql
# client code references upstream naming directly
query { commerce_v2_product_t(id: $id) { SKU } }
```

### Good
```json
{ "transforms": [{ "rename": { "renames": [{ "from": { "type": "commerce_v2_product_t" }, "to": { "type": "Product" } }] } }] }
```

## Keep additionalResolvers thin — orchestrate, don't implement business logic [warn] [architecture, separation]
It's tempting to put business rules in resolvers ("apply 10% discount if customer.tier === 'gold'"). That logic belongs in upstream services or Runtime actions. Resolvers should validate, call 1–2 upstreams, and shape the result.

### Bad
```js
resolve: async (root, args, ctx) => {
  const base = await fetchBase(args);
  const tax = base * (await getTaxRate(ctx.region));
  const discount = ctx.user.tier === 'gold' ? base * 0.1 : 0;
  return { total: base + tax - discount };
}
```

### Good
```js
resolve: (root, args, ctx) => fetch('/pricing-service/quote', {
  method: 'POST',
  body: JSON.stringify({ ...args, userId: ctx.user.id })
}); // pricing-service owns the logic
```

## Use federation or type-merging only with clear data relationships [warn] [performance, schema]
Type-merging joins types across sources by a key. Done well it's powerful; done carelessly it generates fanout (one upstream call per type instance). Only merge types with strong key alignment and bounded list sizes.

### Bad
```json
// merge Product (Commerce) with Review (reviews-svc) for unbounded product list
// 1 query -> N review fetches
{ "merge": { "Product": { "selectionSet": "{ id }", "key": "id" } } }
```

### Good
```json
// merge only when parent list is bounded AND key is indexed
// otherwise denormalize at ingest time
{ "merge": { "Product": { "selectionSet": "{ id }", "key": "id", "fieldName": "productById" } } }
```
