# API Mesh — Observability

> Part of [API Mesh](./README.md).

## Log correlation ID, source name, operation name, latency, status per request [warn] [observability, logging]
When a query fails, you need to trace which mesh source returned what error and how long it took. Emit structured JSON logs from a `beforeAll` hook with a correlation ID (propagate `x-request-id` if present), operation name, per-source latency, and HTTP status.

### Bad
```js
console.log('error');
```

### Good
```js
console.log(JSON.stringify({
  level: 'error',
  requestId: ctx.requestId,
  source: 'CommerceGraphQL',
  op: ctx.operationName,
  latencyMs: Date.now() - ctx.startedAt,
  status: 502
}));
```

## Set up log forwarding to New Relic or your SIEM before go-live [warn] [observability, ops]
Adobe's default log retention in the mesh data plane is short. Production diagnostics, alerting, and golden-signals dashboards all require log forwarding to a real SIEM (New Relic, Splunk, etc.). Configure it as part of the launch checklist, not after the first incident.

### Bad
```bash
# launch with logs visible only via aio api-mesh:log-list (~24h retention)
aio api-mesh:log-list
```

### Good
```json
// meshConfig.json — log forwarding to New Relic
{ "logForwarding": { "destination": "newrelic", "licenseKey": "{{env.NEW_RELIC_LICENSE_KEY}}" } }
```

## Use httpDetails only in non-prod — exposes payloads with potential PII [error] [observability, privacy]
Enabling `httpDetails` in mesh logs captures full request and response bodies, including any PII or auth tokens that flow through. Useful in development; a privacy and compliance liability in production.

### Bad
```json
// production meshConfig
{ "responseConfig": { "includeHTTPDetails": true } }
```

### Good
```json
// dev workspace only
{ "responseConfig": { "includeHTTPDetails": false } }
```
