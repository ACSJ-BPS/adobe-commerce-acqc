# React — Hooks Correctness & Render Performance

> Part of [React](./README.md).

## Hooks rules: only at top level, only from React functions [error] [hooks]

React's reconciler relies on hooks running in the same order on every render. Calling `useState` inside an `if` or a loop breaks that contract — typically blowing up in production after a conditional finally flips.

### Bad
```jsx
function Profile ({ userId }) {
  if (userId) {
    const [data] = useState(null)   // conditional hook
  }
}
```

### Good
```jsx
function Profile ({ userId }) {
  const [data] = useState(null)
  if (!userId) return null
  return <div>{data?.name}</div>
}
```

---

## exhaustive-deps: every used value goes in the dep array [warn] [hooks, bugs]

Stale-closure bugs in `useEffect` / `useCallback` / `useMemo` are usually traceable to a missing dep. The rule has rare valid exceptions, but in 2026 ESLint-driven discipline still catches more bugs than it causes.

### Bad
```jsx
useEffect(() => {
  fetch(`/api/sync?id=${orderId}&region=${region}`)
}, [orderId])   // region missing
```

### Good
```jsx
useEffect(() => {
  fetch(`/api/sync?id=${orderId}&region=${region}`)
}, [orderId, region])
```

---

## No inline object/array literals as props on memoed children [warn] [perf, render]

`React.memo` shallow-compares props by reference. An inline `{}`, `[]`, or arrow function creates a new reference every render, defeating memoization entirely.

### Check
```yaml
type: builtin
id: inline-prop-on-memoed
scope: repo
```

### Bad
```jsx
const Memoed = React.memo(ExpensiveList)
function Page () { return <Memoed items={[1, 2, 3]} style={{ margin: 8 }} /> }
```

### Good
```jsx
const items = [1, 2, 3]
const listStyle = { margin: 8 }
function Page () { return <Memoed items={items} style={listStyle} /> }
```

---

## Stable handlers via useCallback when passed to memoed children [warn] [perf, render]

Same root cause as inline objects: a fresh function literal each render breaks `React.memo`. Wrap in `useCallback` with proper deps when passing handlers to memoed components.

### Check
```yaml
type: builtin
id: inline-prop-on-memoed
scope: repo
```

### Bad
```jsx
return items.map(item => <MemoedRow key={item.id} item={item} onSelect={(id) => doThing(id)} />)
```

### Good
```jsx
const handleSelect = useCallback((id) => doThing(id), [])
return items.map(item => <MemoedRow key={item.id} item={item} onSelect={handleSelect} />)
```

---

## useMemo only for measurably expensive computation [info] [perf]

`useMemo` adds overhead: closure cost, dep comparison every render, GC pressure on the cached value. Wrapping a `.filter()` over 5 items is net-negative. Reserve it for actual hot paths.

### Check
```yaml
type: builtin
id: trivial-usememo
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
const label = useMemo(() => status.toUpperCase(), [status])
```

### Good
```jsx
const label = status.toUpperCase()
```

---

## useId for SSR-safe and a11y IDs — never Math.random() [warn] [a11y, ssr]

Manually generated IDs cause hydration mismatches when SSR'd and produce duplicate IDs across instances when randomized. `useId` (React 18+) gives you a stable, deterministic, unique ID without either problem.

### Check
```yaml
type: builtin
id: math-random-ids
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
const id = `field-${Math.random().toString(36).slice(2)}`
```

### Good
```jsx
import { useId } from 'react'
const id = useId()
```

---

## No fetching in render — use useEffect, Suspense, or a query lib [error] [bugs, perf]

Fetching directly in the render body fires on every render — infinite-loop risk, server thrashing, impossible to cancel cleanly.

### Check
```yaml
type: builtin
id: fetch-in-render
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function UserCard ({ id }) {
  const data = fetch(`/api/user/${id}`).then(r => r.json())
  return <div>{data?.name}</div>
}
```

### Good
```jsx
function UserCard ({ id }) {
  const [data, setData] = useState(null)
  useEffect(() => {
    const ctrl = new AbortController()
    fetch(`/api/user/${id}`, { signal: ctrl.signal }).then(r => r.json()).then(setData).catch(() => {})
    return () => ctrl.abort()
  }, [id])
  return <div>{data?.name}</div>
}
```

---

## Code-split with lazy + Suspense for routes and heavy components [warn] [perf, bundle]

Loading every route's code on initial paint balloons the bundle. `React.lazy` + `Suspense` defers per-route bundles until the route is visited, often halving first-paint cost for large App Builder SPAs.

### Check
```yaml
type: builtin
id: no-lazy-route
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
import Reports from './pages/Reports'
import Admin from './pages/Admin'
import Settings from './pages/Settings'
```

### Good
```jsx
const Reports = lazy(() => import('./pages/Reports'))
const Admin = lazy(() => import('./pages/Admin'))
const Settings = lazy(() => import('./pages/Settings'))

<Suspense fallback={<Spinner />}>
  <Routes>
    <Route path="/reports" element={<Reports />} />
    <Route path="/admin" element={<Admin />} />
    <Route path="/settings" element={<Settings />} />
  </Routes>
</Suspense>
```

---

## Virtualize long lists (>100 items) [warn] [perf, render]

Rendering 1000 DOM nodes for a list the user can only see 20 of at a time is wasteful. Use Spectrum's `<TableView>` (which virtualizes by default) or a library like `react-window` / `@tanstack/react-virtual`.

### Check
```yaml
type: builtin
id: unvirtualized-list
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<ul>
  {tenThousandRows.map(r => <li key={r.id}>{r.name}</li>)}
</ul>
```

### Good
```jsx
import { TableView, TableHeader, Column, TableBody, Row, Cell } from '@adobe/react-spectrum'

<TableView aria-label="Orders">
  <TableHeader><Column>Name</Column></TableHeader>
  <TableBody items={tenThousandRows}>
    {row => <Row><Cell>{row.name}</Cell></Row>}
  </TableBody>
</TableView>
```

---

## No .bind() in JSX — use useCallback or class methods [warn] [perf, render]

`react/jsx-no-bind`. `.bind()` in JSX produces a fresh function reference on every render, defeating `React.memo` shallow-prop comparison and forcing memoed children to re-render unnecessarily.

### Check
```yaml
type: builtin
id: bind-in-jsx
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<MemoedRow onSelect={this.handleSelect.bind(this, item.id)} />
```

### Good
```jsx
const handleSelect = useCallback((id) => doThing(id), [])
<MemoedRow onSelect={handleSelect} item={item} />
```
