# React — Provider Setup & Unified Shell Integration

> Part of [React](./README.md).

## Wrap the app in a Spectrum Provider with theme + locale [error] [spectrum, setup]

Spectrum components require a `<Provider>` ancestor for theme tokens, color scheme, scale, and locale. Components rendered outside one fall back to default styles or throw at runtime. The Provider must be the outermost ancestor of any Spectrum component.

### Check
```yaml
type: builtin
id: no-spectrum-provider
scope: repo
```

### Bad
```jsx
function App () {
  return <Routes />   // Spectrum components inside crash or render unstyled
}
```

### Good
```jsx
import { Provider, defaultTheme } from '@adobe/react-spectrum'

function App () {
  return (
    <Provider theme={defaultTheme} colorScheme="light" locale="en-US">
      <Routes />
    </Provider>
  )
}
```

---

## Prefer React Spectrum components over hand-rolled UI [info] [a11y, design]

Spectrum components ship with WAI-ARIA compliance, keyboard navigation, RTL support, and Adobe's design tokens. Re-implementing a `<Button>` or `<Picker>` means re-implementing all of that — and getting it slightly wrong each time.

### Check
```yaml
type: builtin
id: hand-rolled-ui-idiom
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function MyButton ({ onClick, children }) {
  return <div className="btn" onClick={onClick}>{children}</div>
}
```

### Good
```jsx
import { Button } from '@adobe/react-spectrum'

function MyButton ({ onClick, children }) {
  return <Button variant="primary" onPress={onClick}>{children}</Button>
}
```

---

## Build custom components with React Aria / React Stately hooks [info] [a11y]

When Spectrum doesn't ship the component you need, don't roll a div with `onClick`. React Aria provides behavior + accessibility hooks; React Stately provides cross-platform state. You get the same a11y guarantees Spectrum has, with full control over markup.

### Check
```yaml
type: builtin
id: hand-rolled-ui-idiom
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function CustomToggle ({ pressed, onChange }) {
  return <div onClick={() => onChange(!pressed)} role="button">{pressed ? 'On' : 'Off'}</div>
}
```

### Good
```jsx
import { useToggleButton } from 'react-aria'
import { useToggleState } from 'react-stately'
import { useRef } from 'react'

function CustomToggle (props) {
  const state = useToggleState(props)
  const ref = useRef(null)
  const { buttonProps } = useToggleButton(props, state, ref)
  return <button {...buttonProps} ref={ref}>{state.isSelected ? 'On' : 'Off'}</button>
}
```

---

## Handle Unified Shell iframe constraints — resize, CSP, and shell APIs [warn] [shell, iframe]

App Builder SPAs render inside an iframe within the Adobe Experience Cloud Unified Shell. That means a stricter CSP than a standalone app, no `window.top` access, restricted iframe sizing, and the parent shell controls navigation chrome. Use `@adobe/exc-app` to communicate with the shell (resize, locale, IMS profile, navigation events) instead of fighting the sandbox.

### Check
```yaml
type: builtin
id: no-exc-app-import
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function App () {
  // assume full-page control, ignore shell APIs
  useEffect(() => {
    document.body.style.height = '100vh'
    window.parent.postMessage({ type: 'navigate', url: '/dashboard' }, '*')
    // CSP blocks the postMessage; viewport is the iframe, not the screen
  }, [])
}
```

### Good
```jsx
import excApp from '@adobe/exc-app'

function App () {
  const [imsProfile, setImsProfile] = useState(null)

  useEffect(() => {
    const runtime = excApp()
    runtime.on('ready', ({ imsProfile, locale }) => setImsProfile(imsProfile))
    runtime.on('configuration', ({ imsToken }) => { /* ... */ })
    runtime.done()   // signal the shell that the app finished its first render
  }, [])

  return <Provider locale={locale}><Routes /></Provider>
}
```

---

## No native extension points for Frame.io, Acrobat, etc. — call APIs and render your own UI [info] [integration, scope]

App Builder's defined extension points cover Experience Cloud, AEM, and Commerce. Frame.io, Acrobat, Photoshop, Lightroom, Express, and other Adobe products have their own extensibility models — they aren't surfaced as App Builder extension points. Build a standalone SPA that calls those products' APIs; don't expect the Unified Shell to embed inside them.

### Check
```yaml
type: builtin
id: extension-point-enum
scope: repo
```

### Bad
```yaml
extensions:
  acom/frameio/1:    # not a real extension point
    operations:
      view: { type: web, impl: index.html }
```

### Good
```yaml
extensions:
  dx/excshell/1:
    operations:
      view: { type: web, impl: index.html }
runtimeManifest:
  packages:
    frameio:
      actions:
        list-projects:
          function: actions/frameio/list-projects/index.js   # calls Frame.io API directly
```
