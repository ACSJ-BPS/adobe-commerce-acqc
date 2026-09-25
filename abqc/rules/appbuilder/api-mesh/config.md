# API Mesh — Configuration

> Part of [API Mesh](./README.md).

## Reference secrets via env vars — never literal API keys in meshConfig [error] [secrets, config]
meshConfig.json is checked into git. Inline `x-api-key: "abc123"` exposes credentials to anyone with repo access. Adobe's API Mesh expands `{{env.VAR_NAME}}` placeholders at deploy time from Dev Console secrets, which is the only sanctioned way to inject credentials.

### Check
```yaml
type: builtin
id: mesh-literal-secret
files: ["**/meshConfig.json", "**/mesh.json"]
```

### Bad
```json
{ "operationHeaders": { "x-api-key": "abc123-real-key" } }
```

### Good
```json
{ "operationHeaders": { "x-api-key": "{{env.SEARCH_API_KEY}}" } }
```

## Keep meshConfig.json under 375 KB — pre-filter large OpenAPI schemas [warn] [config, limits]
Adobe API Mesh imposes a ~375 KB hard limit on the deployed mesh config. Dumping a full enterprise OpenAPI swagger (often 1–5 MB) blocks deploy with cryptic build errors. Filter the schema or split by domain before referencing it.

### Check
```yaml
type: builtin
id: mesh-config-size
scope: repo
```

### Bad
```json
{ "handler": { "openapi": { "source": "./full-platform-openapi-3mb.json" } } }
```

### Good
```json
{
  "handler": { "openapi": { "source": "./catalog-openapi.json" } },
  "transforms": [{ "filterSchema": { "filters": ["Query.!debug*", "Mutation.!internal*"] } }]
}
```

## Source names are public schema — choose stable names, never rename casually [warn] [schema, contracts]
API Mesh source names appear as type prefixes in the generated GraphQL schema (e.g., `CommerceGraphQL_product`). Renaming a source breaks every consumer query. Pick clear domain names up front (`CommerceGraphQL`, `AEMContent`, `RuntimeActions`) and treat them as a public contract.

### Check
```yaml
type: builtin
id: mesh-source-rename-history
scope: repo
```

### Bad
```json
// PR title: "rename Commerce -> Magento"
{ "sources": [{ "name": "Magento", "handler": { "graphql": { "endpoint": "..." } } }] }
```

### Good
```json
// stable name from day one
{ "sources": [{ "name": "CommerceGraphQL", "handler": { "graphql": { "endpoint": "..." } } }] }
```

## Separate public "core" mesh from internal "backoffice" mesh [warn] [architecture, security]
A single mesh that serves both the public storefront and internal admin operations risks exposing privileged mutations to the public schema. Keep them in separate meshes (separate Dev Console projects/workspaces) so blast radius and auth surface are isolated.

### Check
```yaml
type: builtin
id: mesh-admin-unfiltered
files: ["**/meshConfig.json", "**/mesh.json"]
```

### Bad
```text
storefront-mesh: exposes Query.products AND Mutation.adminUpdateInventory, gated only by header auth
```

### Good
```text
storefront-mesh (workspace: prod-public)  -> Query.products only
admin-mesh    (workspace: prod-internal) -> Mutation.adminUpdateInventory, IMS-scoped, network-restricted
```

## Treat meshConfig.json as code — git-versioned, PR-reviewed, CI-deployed [error] [governance, deploy]
Mesh config drives the schema, auth, and routing of a production GraphQL gateway. Editing it via `aio api-mesh:update` from a developer laptop bypasses review and creates drift between repo and deployed state. Treat it like infrastructure-as-code.

### Check
```yaml
type: builtin
id: mesh-config-git-ci
scope: repo
```

### Bad
```bash
# developer edits mesh in console UI, no PR, no audit trail
```

### Good
```yaml
# .github/workflows/deploy-mesh.yml
- run: aio api-mesh:update meshConfig.json
  env:
    AIO_PROJECT_WORKSPACE: ${{ env.WORKSPACE_NAME }}
```
