# App Builder — Events

> Part of [App Builder](./README.md).

## Implement challenge/response for I/O Events handler actions [error] [events, registration]

Webhook subscriptions to Adobe I/O Events require the action to echo back the `challenge` query parameter during registration. An action that doesn't do this fails the verification step and never receives events — silently.

### Bad
```js
async function main (params) {
  await processEvent(params)
  return { statusCode: 200 }
}
```

### Good
```js
async function main (params) {
  if (params.challenge) {
    return { body: { challenge: params.challenge } }
  }
  await processEvent(params)
  return { statusCode: 200 }
}
```

---

## Verify I/O Events payload HMAC signature before processing [error] [events, auth]

Adobe I/O Events signs every webhook payload with a digital signature delivered in the `x-adobe-signature` header (HMAC-SHA256, keyed with your client secret). An action that skips this check processes unauthenticated payloads — anyone who discovers the action URL can forge events. The challenge/response rule handles *registration*; this rule handles *every subsequent event delivery*.

**Why it was missing:** The challenge/response rule (webhook registration) was present, but the per-payload integrity check required for ongoing event processing was omitted. They look similar but serve different purposes.

### Bad
```js
async function main (params) {
  if (params.challenge) return { body: { challenge: params.challenge } }
  await processEvent(params)   // no signature verification — forged events accepted
  return { statusCode: 200 }
}
```

### Good
```js
const { createHmac, timingSafeEqual } = require('node:crypto')

function verifySignature (rawBody, signature, clientSecret) {
  const expected = createHmac('sha256', clientSecret)
    .update(rawBody, 'utf8')
    .digest('base64')
  const a = Buffer.from(signature)
  const b = Buffer.from(expected)
  return a.length === b.length && timingSafeEqual(a, b)
}

async function main (params) {
  if (params.challenge) return { body: { challenge: params.challenge } }

  const sig = params.__ow_headers?.['x-adobe-signature']
  if (!sig || !verifySignature(params.__ow_body, sig, params.CLIENT_SECRET)) {
    return { error: { statusCode: 401, body: { error: 'invalid signature' } } }
  }
  await processEvent(JSON.parse(params.__ow_body))
  return { statusCode: 200 }
}
```

---

## Prefer I/O Events over polling actions on cron schedules [warn] [events, design]

Polling actions on `every-1-minute` triggers cost money for every empty poll, add latency, and fall behind on bursts. If the upstream system supports I/O Events (Adobe Experience Platform, Commerce, AEM, Workfront), subscribe instead. Events are push-based, billed per delivered event, and reach your handler within seconds.

### Bad
```js
// scheduled trigger fires every minute, fetches new orders
exports.main = async () => {
  const orders = await fetch('/orders?since=' + lastChecked).then(r => r.json());
  // ... process
};
```

### Good
```js
// I/O Events handler — invoked when an order is created
exports.main = async (params) => {
  const event = params; // CloudEvents payload
  // ... process the single event
};
```

---

## Proxy external 3rd-party webhooks through an action that handles Adobe's challenge/HMAC flow [error] [events, security]

Adobe I/O Events posts a challenge request when registering an event provider, expects a specific echo response, and signs subsequent payloads with HMAC. Pointing a 3rd-party webhook URL (Slack, GitHub, Stripe) directly at the I/O Events endpoint fails the challenge and bypasses HMAC verification. Always proxy through a runtime action that performs both.

### Bad
```text
3rd-party-system → webhook → Slack incoming-webhook URL
// no challenge handling, no HMAC verify
```

### Good
```js
// runtime action handles challenge, then posts to Slack
exports.main = async (params) => {
  if (params.challenge) return { body: { challenge: params.challenge } };
  if (!verifyHmac(params)) return { statusCode: 401, body: 'invalid signature' };
  await fetch(SLACK_WEBHOOK, { method: 'POST', body: JSON.stringify(formatForSlack(params)) });
  return { statusCode: 200 };
};
```

---

## I/O Events handlers must be idempotent — keyed by event ID [error] [events, reliability]

Adobe I/O Events guarantees at-least-once delivery, not exactly-once. The same event may arrive twice on retries. Handlers that mutate state (create order, send email, charge card) must dedupe by event ID — typically using State SDK with a TTL.

### Bad
```js
exports.main = async (event) => {
  await chargeCard(event.amount, event.customer);
  await sendReceipt(event.customer);
};
// duplicate delivery → double charge, double email
```

### Good
```js
exports.main = async (event) => {
  const seen = await state.get(`event:${event.id}`);
  if (seen) return { statusCode: 200, body: 'duplicate, ignored' };
  await state.put(`event:${event.id}`, true, { ttl: 86400 });
  await chargeCard(event.amount, event.customer);
  await sendReceipt(event.customer);
};
```

---

## Batch high-volume event streams via journaling, not per-event sync handlers [warn] [events, performance]

Per-event synchronous actions on high-volume streams (Commerce order events at peak, AEP segmentation results) drive up GB-seconds cost and risk hitting the 60s cap. Use the I/O Events journaling API to consume events in batches, process N at a time in a single invocation.

### Bad
```yaml
# manifest — fires action per event
ext.config.yaml:
  events:
    - source: commerce.order.created
      action: process-order  # fires once per order, even at 1000/sec
```

### Good
```js
// scheduled action consumes batch from journal
exports.main = async () => {
  const events = await journal.poll({ limit: 100 });
  await Promise.all(events.map(processOne));
  await journal.commit(events);
};
```
