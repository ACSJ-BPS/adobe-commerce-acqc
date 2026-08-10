# App Builder — Observability

> Part of [App Builder](./README.md).

## Use structured JSON logging and forward activation logs to an external sink [warn] [logging, observability]

I/O Runtime truncates activation logs at **10 MB** and keeps them for a fixed, non-configurable retention window. For any production or customer-facing App Builder app, relying solely on in-platform logs is insufficient. Use structured (JSON) log output so logs are queryable once forwarded, and configure log forwarding to Splunk Cloud, New Relic, or Azure Log Analytics from day one — retrofitting observability after incidents is expensive. Every log line must include a `correlationId` (activation ID or upstream request ID) so individual requests can be traced end-to-end across actions.

### Bad
```js
async function main (params) {
  console.log('Processing event...')         // unstructured, no correlation id
  const result = await processEvent(params)
  console.log('Done')                        // lost after retention window
  return { statusCode: 200, body: result }
}
```

### Good
```js
const { Core } = require('@adobe/aio-sdk')

async function main (params) {
  const logger = Core.Logger('my-action', { level: params.LOG_LEVEL ?? 'info' })
  const correlationId = params.__ow_headers?.['x-request-id'] ?? params.__ow_activation_id

  logger.info({ correlationId, event: 'start' })
  try {
    const result = await processEvent(params)
    logger.info({ correlationId, event: 'done' })
    return { statusCode: 200, body: result }
  } catch (err) {
    logger.error({ correlationId, err })
    throw err
  }
}
// Ship logs to Splunk/New Relic via the App Builder log-forwarding config in app.config.yaml
```
