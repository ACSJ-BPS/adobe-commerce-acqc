# API Mesh — Security

> Part of [API Mesh](./README.md).

## Disable GraphQL introspection and field suggestions in production [error] [security, schema]
Introspection (`__schema`, `__type`) lets attackers enumerate every type and operation, including ones intended only for internal admin tools. Field suggestions on typos do the same in error messages. API Mesh supports disabling both via config; do so once the schema stabilizes.

### Check
```yaml
type: builtin
id: mesh-introspection-prod
files: ["**/prod/**/meshConfig.json"]
```

### Bad
```bash
# prod: anonymous query of __schema returns the entire type system
curl https://prod.example.com/graphql -d '{"query":"{__schema{types{name}}}"}'
```

### Good
```json
{ "responseConfig": { "includeHTTPDetails": false }, "plugins": [{ "disableIntrospection": {} }] }
```

## No user-controlled hostnames or path segments in source endpoints — SSRF risk [error] [security, ssrf]
If `baseUrl` or operation path is concatenated from user input (e.g., header value, query arg), a malicious caller can pivot the mesh into your internal network. Endpoints must be static or come from env vars; dynamic routing belongs in a Runtime action that enforces allowlists.

### Check
```yaml
type: builtin
id: mesh-ssrf-endpoint
files: ["**/meshConfig.json"]
```

### Bad
```json
{ "baseUrl": "https://{{request.headers.x-tenant}}.example.com" }
```

### Good
```json
{ "baseUrl": "{{env.TENANT_API_BASE_URL}}" }
```

## Mesh sources must be public HTTPS with valid SSL — no local or self-signed endpoints [error] [security, network]
API Mesh runs in Adobe's edge plane, not your VPC. It cannot reach `localhost`, internal DNS, or private IPs. Self-signed certs are also rejected. Enforcing this prevents accidental dependence on dev infrastructure that breaks in prod.

### Check
```yaml
type: builtin
id: mesh-endpoint-https
files: ["**/meshConfig.json"]
```

### Bad
```json
{ "endpoint": "http://localhost:3000/graphql" }
```

### Good
```json
{ "endpoint": "https://api.example.com/graphql" }
```

## Normalize error messages in production — don't leak schema internals [warn] [security, errors]
Default GraphQL errors include stack traces, upstream URLs, and field names that aid attackers. Wrap responses to return generic error codes in prod (`UPSTREAM_UNAVAILABLE`, `VALIDATION_FAILED`); keep verbose errors in dev only.

### Check
```yaml
type: builtin
id: mesh-error-leak
files: ["**/resolvers/**", "**/resolvers.js", "**/resolvers.ts"]
```

### Bad
```json
{ "errors": [{ "message": "Error fetching https://internal.commerce.example.com/v1/admin/users: 500 (stack: at ...)" }] }
```

### Good
```json
{ "errors": [{ "extensions": { "code": "UPSTREAM_UNAVAILABLE", "requestId": "abc-123" } }] }
```
