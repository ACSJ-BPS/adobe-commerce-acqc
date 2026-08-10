# React — Safety: Iframes & Error Boundaries

> Part of [React](./README.md).

## Wrap routes in Error Boundaries — uncaught errors blank the page [error] [errors, ux]

A single uncaught render-time error in any descendant unmounts the entire React tree by default (React 19 behavior). Wrap each route in an Error Boundary with a fallback UI to localize blast radius.

### Bad
```jsx
<Routes>
  <Route path="/orders" element={<Orders />} />
</Routes>
```

### Good
```jsx
<Routes>
  <Route path="/orders" element={
    <ErrorBoundary fallback={<RouteError />}>
      <Orders />
    </ErrorBoundary>
  } />
</Routes>
```

---

## Iframes must specify a sandbox attribute [error] [security, iframe]

`react/iframe-missing-sandbox`. An unsandboxed iframe can run arbitrary JS, navigate the parent, and access cookies. Always specify `sandbox` with the minimum permissions the embedded content needs (`allow-scripts`, `allow-same-origin`, etc.).

### Bad
```jsx
<iframe src="https://untrusted.example.com/embed" />
```

### Good
```jsx
<iframe
  src="https://untrusted.example.com/embed"
  sandbox="allow-scripts allow-forms"
  title="Embedded content"
/>
```
