# Testing — Mocking

> Part of [Testing](./README.md).

## Mock @adobe/aio-sdk Core.Logger as a jest.fn() [error] [mocking, sdk]

Every action calls `Core.Logger('main', { ... })`. Without mocking, tests either crash on missing IMS context or pollute test output with real logs. Mock once in a `__mocks__/@adobe/aio-sdk.js` or per-test with `jest.mock`.

### Check
```yaml
type: builtin
id: unmocked-core-logger
files: ["**/*.{test,spec}.{js,jsx,ts,tsx}"]
```

### Bad
```js
const action = require('../../actions/process-order')
test('processes order', async () => {
  const result = await action.main({ orderId: '123' })
  // crashes: Logger tries to write to a real log file
})
```

### Good
```js
const mockLogger = { info: jest.fn(), debug: jest.fn(), error: jest.fn(), warn: jest.fn() }
jest.mock('@adobe/aio-sdk', () => ({
  Core: { Logger: jest.fn(() => mockLogger) }
}))
const action = require('../../actions/process-order')

test('processes order', async () => {
  const result = await action.main({ orderId: '123' })
  expect(result.statusCode).toBe(200)
})
```

---

## Reset mocks in beforeEach — never let state leak between tests [error] [hygiene, bugs]

A mock that records calls in test 1 will show those calls in test 2 unless reset. This causes baffling green-then-red cascades when test order changes. `jest.clearAllMocks()` in `beforeEach` is the safe default.

### Check
```yaml
type: builtin
id: missing-clear-mocks
files: ["**/*.{test,spec}.{js,jsx,ts,tsx}"]
```

### Bad
```js
test('logs once', async () => {
  await action.main({ id: '1' })
  expect(mockLogger.info).toHaveBeenCalledTimes(1)
})
test('logs twice', async () => {
  await action.main({ id: '2' })
  await action.main({ id: '3' })
  expect(mockLogger.info).toHaveBeenCalledTimes(2)   // fails — state leak
})
```

### Good
```js
beforeEach(() => { jest.clearAllMocks() })

test('logs once', async () => {
  await action.main({ id: '1' })
  expect(mockLogger.info).toHaveBeenCalledTimes(1)
})
test('logs twice', async () => {
  await action.main({ id: '2' })
  await action.main({ id: '3' })
  expect(mockLogger.info).toHaveBeenCalledTimes(2)
})
```

---

## Mock external HTTP — never hit real APIs in unit tests [error] [hermetic, speed]

A unit test that calls `fetch` against a real upstream is flaky (network), slow (latency), and dangerous (writes to prod). Use `nock`, `msw`, or `jest.mock('node:fetch')` to stub HTTP. Reserve real-network calls for the `e2eTest/` folder.

### Check
```yaml
type: builtin
id: unmocked-http-in-unit-test
files: ["**/test/actions/**", "**/__tests__/**"]
```

### Bad
```js
test('fetches profile', async () => {
  const result = await action.main({ userId: '123' })
  // hits https://prod-api.example.com — flaky, slow, may charge real money
})
```

### Good
```js
const nock = require('nock')

beforeEach(() => {
  nock('https://prod-api.example.com')
    .get('/profile/123')
    .reply(200, { id: '123', name: 'Test' })
})

test('fetches profile', async () => {
  const result = await action.main({ userId: '123' })
  expect(result.body.name).toBe('Test')
})
```
