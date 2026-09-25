# React — Components, Naming & List Keys

> Part of [React](./README.md).

## Don't use array index as key when items can reorder/insert [warn] [bugs, render]

Array-index keys are stable only if the list is append-only and items never reorder. Insert at front, sort, filter, or remove and React's reconciler will reuse the wrong DOM nodes — preserving local state on the wrong item, animations on the wrong row, etc.

### Bad
```jsx
{orders.sort(sortFn).map((o, i) => <OrderRow key={i} order={o} />)}
```

### Good
```jsx
{orders.sort(sortFn).map(o => <OrderRow key={o.id} order={o} />)}
```

---

## Components are PascalCase, files match component name [info] [style, conventions]

A `Button.jsx` exporting `Button` is grep-able, import-friendly, and the React DevTools display name matches the file. Mixing `button.jsx` exporting `Button` or `MyButton.jsx` exporting `Foo` confuses readers and tooling.

### Check
```yaml
type: builtin
id: component-pascalcase
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```text
src/components/order_row.jsx   // exports OrderRow
src/components/UserMenu.jsx    // exports default function () { ... }
```

### Good
```text
src/components/OrderRow.jsx    // exports OrderRow
src/components/UserMenu.jsx    // exports UserMenu
```

---

## Boolean props are isX, hasX, shouldX — never just X [info] [style, conventions]

A prop named `disabled` or `visible` reads naturally; one named `selectedItem` is ambiguous (boolean? object?). Prefix booleans so the type is obvious at the call site.

### Check
```yaml
type: builtin
id: boolean-prop-naming
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<Modal opened={true} backdrop={true} closeOnEscape={true} />
```

### Good
```jsx
<Modal isOpen={true} hasBackdrop={true} shouldCloseOnEscape={true} />
```

---

## No "leaked render" — short-circuit booleans/numbers in JSX [warn] [bugs, render]

`react/jsx-no-leaked-render`. `{count && <X />}` renders the number `0` literally when `count === 0` because `&&` returns the falsy operand. Same trap with empty string. Use a ternary or coerce to boolean.

### Check
```yaml
type: builtin
id: leaked-render
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
{messages.length && <UnreadBadge count={messages.length} />}
// when messages.length === 0, "0" appears in the DOM
```

### Good
```jsx
{messages.length > 0 && <UnreadBadge count={messages.length} />}
// or:
{messages.length ? <UnreadBadge count={messages.length} /> : null}
```

---

## No components defined inside other components [error] [perf, bugs]

`react/no-unstable-nested-components`. A component declared inside another is a new component identity on every parent render — its state and DOM are remounted, animations restart, focus is lost. Define components at module scope.

### Check
```yaml
type: builtin
id: nested-component-def
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function ParentList ({ items }) {
  function Row ({ item }) {                  // new component on every render
    return <li>{item.name}</li>
  }
  return <ul>{items.map(i => <Row key={i.id} item={i} />)}</ul>
}
```

### Good
```jsx
function Row ({ item }) {
  return <li>{item.name}</li>
}

function ParentList ({ items }) {
  return <ul>{items.map(i => <Row key={i.id} item={i} />)}</ul>
}
```

---

## Pass children as JSX, not as a prop named "children" [warn] [style, api]

`react/no-children-prop`. JSX has dedicated syntax for children — `<Wrapper>...</Wrapper>` reads more naturally than `<Wrapper children={...} />`. Only use the prop form when programmatically constructing the tree.

### Check
```yaml
type: builtin
id: children-prop
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<Wrapper children={<Inner />} />
```

### Good
```jsx
<Wrapper><Inner /></Wrapper>
```

---

## No findDOMNode — it's deprecated, use refs [error] [deprecated, bugs]

`react/no-find-dom-node`. `ReactDOM.findDOMNode()` is deprecated, breaks under StrictMode, and is removed in modern React versions. Use a ref (`useRef`, `forwardRef`, or callback ref) to hold the DOM reference instead.

### Check
```yaml
type: regex
pattern: "findDOMNode\\("
files: ["**/*.{jsx,tsx,js,ts}"]
```

### Bad
```jsx
class MyComponent extends React.Component {
  componentDidMount () {
    const node = ReactDOM.findDOMNode(this)
    node.focus()
  }
}
```

### Good
```jsx
function MyComponent () {
  const ref = useRef(null)
  useEffect(() => { ref.current?.focus() }, [])
  return <input ref={ref} />
}
```

---

## Write new components as function components; avoid class components [warn] [style, react]

React Hooks, Concurrent Mode, Suspense, and `use()` do not work correctly with class components. Adobe internal guidelines (DXTS, Unified Shell SPA standards) explicitly require function components for all new code. Class components are permitted only when wrapping a legacy class-based component that cannot yet be migrated. Error boundaries are the one remaining case where a class component is required — wrap them in a thin functional boundary so the surrounding code stays hook-friendly.

### Check
```yaml
type: builtin
id: class-component
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
class UserCard extends React.Component {
  state = { loading: true }

  async componentDidMount () {
    const data = await fetchUser(this.props.id)
    this.setState({ data, loading: false })
  }

  render () {
    return this.state.loading ? <ProgressCircle /> : <View>{this.state.data.name}</View>
  }
}
```

### Good
```jsx
function UserCard ({ id }) {
  const [data, setData] = useState(null)
  useEffect(() => { fetchUser(id).then(setData) }, [id])
  if (!data) return <ProgressCircle aria-label="Loading" />
  return <View>{data.name}</View>
}
```
