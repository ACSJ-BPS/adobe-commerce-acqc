# React — React 19 Features

> Part of [React](./README.md).

## Use the React 19 use() hook for promises and context [info] [hooks, suspense]

React 19's `use()` reads a promise or context inside Suspense boundaries without `useEffect` plumbing. Cleaner data fetching with built-in suspense semantics. Stay on `useEffect` only when you need cleanup or imperative timing.

### Bad
```jsx
const Resource = ({ promise }) => {
  const [data, setData] = useState(null)
  useEffect(() => { promise.then(setData) }, [promise])
  return data ? <View data={data} /> : <Spinner />
}
```

### Good
```jsx
import { use, Suspense } from 'react'

const Resource = ({ promise }) => <View data={use(promise)} />

const Page = () => (
  <Suspense fallback={<Spinner />}>
    <Resource promise={fetchData()} />
  </Suspense>
)
```
