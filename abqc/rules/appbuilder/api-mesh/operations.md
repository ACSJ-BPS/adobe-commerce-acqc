# API Mesh — Operations

> Part of [API Mesh](./README.md).

## Map Dev Console workspaces to environments (dev/stage/prod) [error] [governance, env]
A mesh is created per workspace. Without a clean dev/stage/prod workspace mapping, schema changes leak across environments and rollback is ambiguous. Each environment gets its own workspace, its own meshConfig path-or-overlay, and its own CI deploy job.

### Bad
```text
one workspace ("default") serving both stage and prod traffic
```

### Good
```text
workspace: dev    -> CI on every PR
workspace: stage  -> CI on merge to main
workspace: prod   -> CI on tagged release
each with its own meshConfig overlay and Dev Console secrets
```

## Never point a dev mesh at production Commerce or AEM endpoints [error] [security, env]
A developer running `aio api-mesh:run` locally — or a dev workspace mesh — pointed at production data risks accidental writes (test mutations) and PII exposure to a less-secured environment. Dev mesh sources must be dev or sandbox upstreams.

### Bad
```json
// dev meshConfig
{ "endpoint": "https://production.example.com/graphql" }
```

### Good
```json
// env-keyed source URLs per workspace
{ "endpoint": "{{env.COMMERCE_GRAPHQL_URL}}" }
// dev workspace: COMMERCE_GRAPHQL_URL=https://sandbox.example.com/graphql
// prod workspace: COMMERCE_GRAPHQL_URL=https://production.example.com/graphql
```

## Use aio api-mesh:get to diff deployed config vs local before update [info] [ops, drift]
If someone edited the mesh in console out-of-band, `aio api-mesh:update` overwrites that change silently. Run `aio api-mesh:get` first and diff against local to catch drift before deploying.

### Bad
```bash
# blind update from CI
aio api-mesh:update meshConfig.json
```

### Good
```bash
aio api-mesh:get > deployed.json
diff deployed.json meshConfig.json || (echo "drift detected"; exit 1)
aio api-mesh:update meshConfig.json
```

## Use aio api-mesh:run for local dev with GraphiQL and httpDetails [info] [tooling, local-dev]
Local mesh runner spins up a dev GraphQL endpoint with full GraphiQL and `httpDetails` for debugging. Faster feedback than deploying to a dev workspace for every change.

### Bad
```bash
# every meshConfig tweak goes through update -> remote workspace
aio api-mesh:update meshConfig.json
```

### Good
```bash
aio api-mesh:run meshConfig.json
# GraphiQL at http://localhost:5000/graphql with httpDetails enabled
```
