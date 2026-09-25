# React — JSX-Specific Rules

> Part of [React](./README.md).

## Sanitize before dangerouslySetInnerHTML or skip it entirely [error] [xss, security]

`dangerouslySetInnerHTML` bypasses React's automatic escaping — the only legitimate XSS vector in a React app. Sanitize with DOMPurify first, or render plain text and skip the API.

### Check
```yaml
type: builtin
id: sanitize-dangerously-set-inner-html
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<div dangerouslySetInnerHTML={{ __html: userHtml }} />
```

### Good
```jsx
import DOMPurify from 'dompurify'
const safe = useMemo(() => DOMPurify.sanitize(userHtml), [userHtml])
return <div dangerouslySetInnerHTML={{ __html: safe }} />
```

---

## Prefer Fragment shorthand over wrapping div when no styling needed [info] [style, perf]

Wrapping children in a `<div>` when the parent doesn't need a DOM node adds a useless element to the tree (CSS layout impact, accessibility tree noise). Use `<>...</>` or `<Fragment>`.

### Check
```yaml
type: builtin
id: unnecessary-wrapping-div
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function Group ({ items }) {
  return (
    <div>
      {items.map(i => <Item key={i.id} {...i} />)}
    </div>
  )
}
```

### Good
```jsx
function Group ({ items }) {
  return (
    <>
      {items.map(i => <Item key={i.id} {...i} />)}
    </>
  )
}
```

---

## target="_blank" must include rel="noopener noreferrer" [error] [security, links]

`react/jsx-no-target-blank`. Opening a link in a new tab without `rel="noopener"` gives the new page partial access to `window.opener` — a tabnabbing attack vector. Modern browsers default to `noopener`, but explicit is safer and ESLint enforces it.

### Check
```yaml
type: builtin
id: target-blank-rel
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<a href={user.website} target="_blank">{user.name}</a>
```

### Good
```jsx
<a href={user.website} target="_blank" rel="noopener noreferrer">{user.name}</a>
```

---

## No useless fragments — <></> with one child [info] [style]

`react/jsx-no-useless-fragment`. Wrapping a single element in `<>...</>` adds no semantic meaning and clutters the JSX. Either drop the fragment or use it only when actually rendering multiple siblings.

### Check
```yaml
type: builtin
id: single-child-fragment
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function Card ({ title }) {
  return <><h2>{title}</h2></>
}
```

### Good
```jsx
function Card ({ title }) {
  return <h2>{title}</h2>
}

// Fragments are correct when you genuinely have multiple siblings:
function CardWithSubtitle ({ title, subtitle }) {
  return <>
    <h2>{title}</h2>
    <p>{subtitle}</p>
  </>
}
```

---

## Self-close tags with no children [info] [style]

`react/self-closing-comp`. `<div></div>` and `<Spectrum.Divider></Spectrum.Divider>` are noisy when they have no children. Self-close them.

### Check
```yaml
type: builtin
id: non-self-closing-empty-tag
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<Divider></Divider>
<img src="/x.png"></img>
<Spinner></Spinner>
```

### Good
```jsx
<Divider />
<img src="/x.png" />
<Spinner />
```

---

## Every <button> needs an explicit type attribute [warn] [bugs, forms]

`react/button-has-type`. The default for `<button>` inside a form is `type="submit"` — clicking it submits the form, often by accident. Make `type="button"` (or `type="reset"`) explicit on every button that isn't the form's submit action.

### Check
```yaml
type: builtin
id: button-missing-type
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<form onSubmit={handleSubmit}>
  <input name="email" />
  <button onClick={openTooltip}>?</button>   // submits the form on click
  <button>Save</button>
</form>
```

### Good
```jsx
<form onSubmit={handleSubmit}>
  <input name="email" />
  <button type="button" onClick={openTooltip}>?</button>
  <button type="submit">Save</button>
</form>
```

---

## Never mutate state directly — use the setter [error] [bugs, state]

`react/no-direct-mutation-state`. Mutating `state.items.push(x)` (or class `this.state.x = y`) doesn't trigger a re-render, leaves React's internal state out of sync, and breaks time-travel debugging. Always use the setter with a new reference.

### Check
```yaml
type: builtin
id: direct-state-mutation
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
const [items, setItems] = useState([])
function addItem (item) {
  items.push(item)   // no re-render; React doesn't know the array changed
  setItems(items)    // same reference; setter bails out
}
```

### Good
```jsx
const [items, setItems] = useState([])
function addItem (item) {
  setItems(prev => [...prev, item])
}
```

---

## Void DOM elements (img, br, input) cannot have children [error] [bugs, dom]

`react/void-dom-elements-no-children`. The HTML spec forbids children on `<img>`, `<br>`, `<hr>`, `<input>`, `<meta>`, `<link>`, etc. React allows the JSX, but the rendered DOM is invalid and the children are silently dropped.

### Check
```yaml
type: builtin
id: void-element-children
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
<img src="/x.png">
  <span>caption</span>
</img>
<input type="text">{value}</input>
```

### Good
```jsx
<figure>
  <img src="/x.png" alt="diagram" />
  <figcaption>caption</figcaption>
</figure>
<input type="text" value={value} onChange={onChange} />
```
