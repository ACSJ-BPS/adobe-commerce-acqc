# Testing — Coverage

> Part of [Testing](./README.md).

## Cover at least 80% of branches in actions [warn] [coverage]

Branch coverage (every `if`/`else`/ternary/`&&`/`||` path) is a stronger signal than line coverage. 80% is a defensible default for App Builder actions — high enough to catch missed error paths, low enough to allow pragmatic gaps for truly-untestable lines (e.g., catch blocks for "should never happen" cases).

### Check
```yaml
type: builtin
id: coverage-threshold-config
scope: repo
```

### Bad
```json
{ "jest": { "coverageThreshold": { "global": { "lines": 50 } } } }
```

### Good
```json
{
  "jest": {
    "coverageThreshold": {
      "global": { "branches": 80, "functions": 80, "lines": 80, "statements": 80 }
    }
  }
}
```

---

## Snapshot tests for stable JSON shapes only [warn] [snapshots, hygiene]

Snapshots are great for "this response shape shouldn't change" assertions. They become noise when applied to (a) HTML/JSX with churn-y attributes, (b) shapes containing timestamps/UUIDs/auth tokens, (c) anything where you can't read the snapshot and tell whether it's correct.

### Check
```yaml
type: builtin
id: snapshot-of-render
files: ["**/*.{test,spec}.{js,jsx,ts,tsx}"]
```

### Bad
```js
test('renders order', () => {
  const { container } = render(<OrderRow order={fixture} />)
  expect(container).toMatchSnapshot()   // 200 lines of HTML; no one reads this
})
```

### Good
```js
test('renders order id and total', () => {
  const { getByText } = render(<OrderRow order={fixture} />)
  expect(getByText(fixture.id)).toBeInTheDocument()
  expect(getByText(`$${fixture.total}`)).toBeInTheDocument()
})
```
