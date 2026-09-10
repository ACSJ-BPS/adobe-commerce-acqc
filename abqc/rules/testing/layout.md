# Testing — Layout

> Part of [Testing](./README.md).

## Test files mirror source structure under test/actions/ [error] [layout, conventions]

`aio app test` discovers tests via Jest's default config plus path conventions: action tests live at `test/actions/<action-name>.test.js` mirroring `actions/<action-name>/index.js`. Putting tests next to source (`actions/<name>/index.test.js`) means they get bundled and shipped to Runtime — wasted bytes and accidental exposure of test fixtures.

### Check
```yaml
type: builtin
id: test-layout-mirror
scope: repo
```

### Bad
```text
actions/
  process-order/
    index.js
    index.test.js   // ships to Runtime
```

### Good
```text
actions/
  process-order/
    index.js
test/
  actions/
    process-order.test.js
```

---

## Use aio app test, not raw jest [warn] [tooling]

`aio app test` reads `unitTest` and `e2eTest` paths from `app.config.yaml`, applies the project's coverage thresholds, and respects extension scoping in multi-extension apps. Calling raw `jest` may pick up the wrong files or skip the coverage gate.

### Check
```yaml
type: builtin
id: raw-jest-ci
scope: repo
```

### Bad
```bash
npx jest test/actions/
```

### Good
```bash
aio app test
aio app test --e2e
aio app test --extension dx/excshell/1
```

---

## Test descriptions describe behavior, not implementation [info] [style, readability]

`it('returns 400 when orderId missing')` tells the next maintainer what should happen. `it('checks the if statement on line 23')` tells them nothing they can't read in the code. The describe/it text becomes the test report — write it as a spec.

### Check
```yaml
type: builtin
id: vague-test-title
files: ["**/*.{test,spec}.{js,jsx,ts,tsx}"]
```

### Bad
```js
describe('main function', () => {
  it('test 1', async () => { /* ... */ })
  it('null check', async () => { /* ... */ })
  it('handles the case', async () => { /* ... */ })
})
```

### Good
```js
describe('process-order action', () => {
  it('returns 400 when orderId is missing', async () => { /* ... */ })
  it('returns 401 when bearer token is invalid', async () => { /* ... */ })
  it('charges the customer and returns 200 on success', async () => { /* ... */ })
})
```
