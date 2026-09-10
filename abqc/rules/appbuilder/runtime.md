# App Builder — Runtime

> Part of [App Builder](./README.md).

## Action handler signature: main(params) + exports.main = main [error] [sdk, runtime]

I/O Runtime resolves an action's entry point by looking for a function named `main` or an export named `main` on the module. Anything else (default exports, `handler(req, res)`, named exports like `run` or `execute`) deploys but never receives traffic — invocations silently fail or return 404.

### Check
```yaml
type: builtin
id: main-signature
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
export default async function handler (req, res) { res.json({ ok: true }) }
```

### Good
```js
async function main (params) {
  return { statusCode: 200, body: { ok: true } }
}
exports.main = main
```

---

## Web action timeout: 60s blocking, 30s through CDN [error] [runtime, perf]

Web actions invoked in a blocking manner hard-cap at 60 seconds. Web actions served via the API Gateway / CDN cap at 30 seconds. Non-web (async) actions can run up to 1 hour (3,600,000 ms) per `app.config.yaml`. Design any work that may exceed these to fan out via triggers or async chaining.

### Check
```yaml
type: yaml-path
condition: web-timeout
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
actions:
  generate-report:
    function: actions/generate-report/index.js
    web: 'yes'
    limits:
      timeout: 600000   # 10 minutes — gateway will 504 at 30s
```

### Good
```yaml
actions:
  generate-report:
    function: actions/generate-report/index.js
    web: 'yes'
    limits:
      timeout: 30000
  generate-report-async:
    function: actions/generate-report-async/index.js
    web: 'no'
    limits:
      timeout: 3600000
```

---

## Memory: only 256/512/1024 MB hit prewarmed containers [warn] [perf, cold-start]

The `memory` setting accepts 128–4096 MB, but Adobe pre-warms containers only at 256, 512, and 1024 MB. Any other value forces a cold start every invocation, adding 1–3 seconds of latency. Use the closest standard tier above your measured peak.

### Check
```yaml
type: yaml-path
condition: memory-tier
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
limits:
  memory: 384   # not prewarmed — cold start every time
```

### Good
```yaml
limits:
  memory: 512
```

---

## Concurrency cap is 500 per container — design for it [warn] [runtime, scale]

The `concurrency` field accepts 1–500 (default 200). It controls how many requests a single warm container handles in parallel. Setting it too low forces more container spawns (cost + cold starts); setting it at 500 with a sync-bottlenecked action queues all 500 behind one slow request.

### Check
```yaml
type: yaml-path
condition: concurrency
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
limits:
  concurrency: 1   # forces a new container per request — cold start every time
```

### Good
```yaml
limits:
  concurrency: 200
```

---

## Use a supported Node runtime — nodejs:18 and older are EOL [warn] [runtime, deployment]

Adobe I/O Runtime's supported Node.js versions move forward over time; `nodejs:18` and older are end-of-life, lose security patches, and are eventually removed — an action pinned to a removed runtime fails to deploy. Pin each action's `runtime` to a currently supported version (`nodejs:20` or `nodejs:22` at the time of writing). This check flags per-action `runtime` values of `nodejs:18` and below; a version set only as a global default (not per action) is not caught.

### Check
```yaml
type: yaml-path
condition: runtime-version
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
actions:
  process-order:
    function: actions/process-order/index.js
    runtime: nodejs:18   # end-of-life — no security patches, may be removed
```

### Good
```yaml
actions:
  process-order:
    function: actions/process-order/index.js
    runtime: nodejs:22
```

---

## Error response shape: { error: { statusCode, body } } [error] [runtime, errors]

Returning an unwrapped error or throwing causes Runtime to record a system error and return 500 to the caller. Wrapping the response in `{ error: ... }` lets Runtime classify it as an `applicationError` with the right status code, which the activation log and the calling app both interpret correctly.

### Check
```yaml
type: builtin
id: error-response-shape
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
if (!params.userId) return { statusCode: 400, body: 'missing userId' }
```

### Good
```js
if (!params.userId) {
  return { error: { statusCode: 400, body: { error: 'missing userId' } } }
}
```

---

## Wrap entire main body in try/catch — unhandled async kills the container [error] [runtime, errors]

Adobe's Creating Actions guide is blunt: "it is literally impossible to recover from unhandled asynchronous errors in Node.js." An uncaught rejection terminates the action container and adds cold-start overhead to *subsequent* invocations across the namespace.

### Check
```yaml
type: builtin
id: try-catch-main
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
async function main (params) {
  const data = await fetchData(params.id)
  return { statusCode: 200, body: data }
}
```

### Good
```js
async function main (params) {
  try {
    return { statusCode: 200, body: await fetchData(params.id) }
  } catch (err) {
    logger.error('main failed', err)
    return { error: { statusCode: 500, body: { error: 'internal error', requestId: params.__ow_activation_id } } }
  }
}
```

---

## Respond to 429 by backing off — Runtime throttles aggressively [warn] [runtime, retry]

When Runtime returns 429 you are at the namespace's concurrent-invocation limit. Retrying immediately compounds the problem. Use exponential backoff with jitter, or stop and surface the 429 to the upstream caller.

### Check
```yaml
type: builtin
id: 429-backoff
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
```

### Bad
```js
while (true) {
  const res = await invokeAction(params)
  if (res.status !== 429) return res
}
```

### Good
```js
for (let attempt = 0; attempt < 5; attempt++) {
  const res = await invokeAction(params)
  if (res.status !== 429) return res
  const backoff = Math.min(2 ** attempt * 100, 5000) + Math.random() * 200
  await new Promise(r => setTimeout(r, backoff))
}
return { error: { statusCode: 429, body: { error: 'throttled' } } }
```

---

## Mark every action as final: true — prevent sequence injection [error] [runtime, injection]

When an action omits the `final` annotation, Adobe I/O Runtime allows any sequence to invoke it as an intermediate step with sequence-controlled `params`. An attacker who can create or modify a sequence in your namespace can inject arbitrary parameters into your action — bypassing your validation logic entirely. Setting `final: true` locks the action: it can only be invoked directly, never as a sequence component with overridden params.

**Why it was missing:** The collection focused on JS code-level injection (eval, child_process, SQL); this is a YAML-level injection vector unique to I/O Runtime's sequence model that has no ESLint analogue.

### Check
```yaml
type: yaml-path
condition: final
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
actions:
  process-payment:
    function: actions/process-payment/index.js
    web: 'yes'
    # no 'final' annotation — any sequence in the namespace can invoke this
    # with attacker-controlled params, bypassing checkMissingRequestInputs
```

### Good
```yaml
actions:
  process-payment:
    function: actions/process-payment/index.js
    web: 'yes'
    annotations:
      final: true           # direct invocation only — sequence injection blocked
      require-adobe-auth: true
```

---

## Restrict web action invocation with allowedHosts [warn] [runtime, auth]

Web actions default to accepting invocations from any host. Adobe's Securing Web Actions guide documents an `allowedHosts` annotation that restricts which hosts may call the action URL. Without it, an action with `require-adobe-auth: false` (e.g., a public webhook receiver) can be called by any origin on the internet — not just your SPA or your event provider.

**Why it was missing:** The `require-adobe-auth` rule covered identity-level auth but not network-level access control. `allowedHosts` is a manifest setting, invisible to code-pattern analysis — but the config YAML itself is checkable.

### Check
```yaml
type: yaml-path
condition: allowed-hosts
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
actions:
  webhook-receiver:
    web: 'yes'
    annotations:
      require-adobe-auth: false   # public — but no host restriction
```

### Good
```yaml
actions:
  webhook-receiver:
    web: 'yes'
    annotations:
      require-adobe-auth: false
      allowedHosts:
        - 'hooks.slack.com'
        - 'api.github.com'
```

---

## Action sequences must respect the 60s edge cap — design steps to complete well under that [warn] [sequences, timeout]

A sequence of 5 actions, each at p95 12s, will tail-latency-blow past the 60s cap on a bad day. The cap applies to the whole sequence, not per step. Either keep p95 << 60s/N or break the workflow into async steps with State-backed continuation.

### Check
```yaml
type: builtin
id: sequence-budget
files: ["**/*.config.yaml", "**/*.config.yml", "**/app.config.yaml"]
```

### Bad
```yaml
sequences:
  process-order:
    actions: [validate, charge, ship, notify, update-erp]
    # each step p95 ~12s -> sequence p95 ~60s
```

### Good
```yaml
sequences:
  process-order-fast-path:
    actions: [validate, charge, notify]    # synchronous customer-visible work
# ship + update-erp run async, triggered by the order-created event
```

---

## Wrap external HTTP calls in sequences with timeouts, retries with backoff, and circuit breakers [warn] [sequences, reliability]

A sequence step that hangs on an upstream call holds the entire sequence open against the 60s cap. Bind every external call to an explicit timeout (well under remaining budget), retry with exponential backoff for transient errors, and trip a circuit breaker for sustained failures.

### Check
```yaml
type: builtin
id: http-timeout-retry
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const r = await fetch(EXTERNAL_API); // no timeout, default ~120s on Node 18
```

### Good
```js
const breaker = new CircuitBreaker(callExternal, { timeout: 5000, errorThresholdPercentage: 50 });
const r = await retry(() => breaker.fire(), { retries: 3, factor: 2, minTimeout: 200 });
```
