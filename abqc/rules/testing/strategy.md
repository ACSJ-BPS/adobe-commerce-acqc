# Testing — Strategy

> Part of [Testing](./README.md).

## Every action test must verify main is exported [error] [smoke, sdk]

Runtime invokes only `module.exports.main`. The default Jest scaffold from `aio app init` includes a "main is a function" smoke test for a reason — actions that compile but don't export `main` deploy and silently 404. Keep this test on every action.

### Check
```yaml
type: builtin
id: missing-main-export-test
files: ["**/*.{test,spec}.{js,jsx,ts,tsx}"]
```

### Bad
```js
// no smoke test — refactor renames main → handler, deploy passes, prod fails
```

### Good
```js
const action = require('../../actions/process-order')

test('main is a function', () => {
  expect(typeof action.main).toBe('function')
})
```

---

## Test the missing-input → 400 path [error] [validation]

An action without input validation tests is one refactor away from leaking 500s on any malformed request. Every required input deserves a test that omits it and asserts the 400 response shape.

### Check
```yaml
type: builtin
id: missing-400-path-test
files: ["**/test/actions/**"]
```

### Bad
```js
// only tests the happy path
test('processes order', async () => {
  const result = await action.main({ orderId: '123', __ow_headers: { authorization: 'Bearer ...' } })
  expect(result.statusCode).toBe(200)
})
```

### Good
```js
test('returns 400 when orderId missing', async () => {
  const result = await action.main({ __ow_headers: { authorization: 'Bearer ...' } })
  expect(result.error.statusCode).toBe(400)
  expect(result.error.body.error).toContain('orderId')
})
```

---

## Test the error response shape: { error: { statusCode, body } } [error] [errors, contracts]

The wrapped error shape (appbuilder.md rule on error responses) is part of the action's contract with Runtime and with callers. Asserting it in tests catches regressions where someone refactors `return { statusCode: 500 }` and breaks the activation log classification.

### Check
```yaml
type: builtin
id: missing-error-shape-test
files: ["**/test/actions/**"]
```

### Bad
```js
test('handles failure', async () => {
  const result = await action.main(brokenParams)
  expect(result.statusCode).toBe(500)   // wrong shape — passes anyway
})
```

### Good
```js
test('handles failure', async () => {
  const result = await action.main(brokenParams)
  expect(result.error).toBeDefined()
  expect(result.error.statusCode).toBe(500)
  expect(result.error.body).toHaveProperty('error')
})
```

---

## e2eTest folder for cross-action and frontend-to-action flows [info] [e2e, layout]

The `e2eTest` path in `app.config.yaml` is for tests that exercise multiple actions together, the SPA mounted in the Unified Shell, or the deployed action endpoint. These tests are slow and need real credentials — keep them out of `test/` (which runs on every commit).

### Check
```yaml
type: builtin
id: e2etest-folder
scope: repo
```

### Bad
```text
test/
  actions/
    process-order.test.js   // unit
  full-checkout-flow.test.js   // 30s integration mixed in
```

### Good
```text
test/actions/process-order.test.js   // fast unit
e2eTest/full-checkout-flow.test.js   // slow, run pre-release
```

---

## Separate smoke tests from e2e — smoke verifies deploy, e2e verifies flow [info] [smoke, e2e]

Smoke and e2e are different tools for different questions. **Smoke** answers "did the deploy succeed?" — fast, narrow, runs after every deploy, hits one endpoint per action with a known-good fixture, expects HTTP 200. **E2E** answers "does the user journey work end-to-end?" — slower, broader, runs pre-release, drives the SPA, validates business outcomes. Mixing them means smoke tests take 10 minutes and you stop running them after deploys.

### Check
```yaml
type: builtin
id: smoke-e2e-separation
scope: repo
```

### Bad
```text
test/
  e2e/
    full-checkout-with-payment-and-email.test.js   # 12 minutes
    process-order-action-returns-200.test.js        # 2 seconds, but lives in e2e
```

### Good
```text
test/
  actions/                                          # unit, milliseconds, every commit
  smoke/
    process-order-action-200.test.js                # post-deploy, seconds, every deploy
e2eTest/
  full-checkout-with-payment-and-email.test.js      # pre-release, minutes, weekly
```

```bash
aio app deploy --workspace Stage
aio app test --smoke      # run after deploy
aio app test --e2e        # run pre-release
```
