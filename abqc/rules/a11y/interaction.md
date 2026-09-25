# Accessibility — Interactive patterns

> Part of [Accessibility](./README.md).

## Click events on non-interactive elements need keyboard equivalents [error] [a11y]

`jsx-a11y/click-events-have-key-events`. A `<div onClick>` works for mouse users but not keyboard users. Use `<button>` (Spectrum's `<Button>` is best) or pair `onClick` with `onKeyDown` for Enter/Space.

### Bad
```jsx
<div onClick={openMenu}>☰</div>
```

### Good
```jsx
<Button variant="quiet" onPress={openMenu} aria-label="Open menu">☰</Button>
```

---

## Don't use autoFocus — disorients keyboard and screen-reader users [warn] [a11y, ux]

`jsx-a11y/no-autofocus`. AutoFocus on page load skips the user past the navigation and breaks the natural reading order. Screen readers may begin in the middle of the page with no context.

### Bad
```jsx
<input autoFocus type="search" />
```

### Good
```jsx
// let users tab to the field naturally; or, on a dedicated search route, focus on a user action
<input ref={inputRef} type="search" />
// inside an explicit user-initiated event:
<Button onPress={() => inputRef.current?.focus()}>Find</Button>
```

---

## tabindex must be 0 or -1 — never positive [error] [keyboard, a11y]

`jsx-a11y/tabindex-no-positive`. `tabindex={1}` (or any positive integer) puts the element ahead of natural tab order, breaking the document flow. Use `0` to make non-interactive elements focusable, `-1` to remove from tab order, and let DOM order do the rest.

### Bad
```jsx
<input tabIndex={1} name="email" />
<input tabIndex={2} name="password" />
<button tabIndex={3}>Sign in</button>
```

### Good
```jsx
<input name="email" />
<input name="password" />
<button>Sign in</button>
// natural DOM order = natural tab order
```

---

## Static elements with click handlers need role + keyboard [error] [a11y, keyboard]

`jsx-a11y/no-static-element-interactions`. `<div onClick>` is not announced as interactive by screen readers. Either use a real interactive element (`<button>`, Spectrum's `<Button>`) or add `role`, `tabIndex`, and keyboard handlers to make the div behave like one.

### Bad
```jsx
<div onClick={selectRow}>{row.name}</div>
```

### Good
```jsx
// preferred:
<button onClick={selectRow} className="row-button">{row.name}</button>

// if you must keep the div:
<div
  role="button"
  tabIndex={0}
  onClick={selectRow}
  onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && selectRow()}
>
  {row.name}
</div>
```

---

## Non-interactive elements should not have tabIndex [warn] [a11y]

`jsx-a11y/no-noninteractive-tabindex`. Adding `tabIndex={0}` to a `<div>` or `<span>` that doesn't behave like a control creates focus traps where the user lands on something they can't act on. The lint rule flags `tabIndex` on a non-interactive element even with a landmark role like `region` added — if it genuinely needs to be focusable, use an actually-interactive element (e.g. `<button>`) instead.

### Bad
```jsx
<div tabIndex={0}>{message}</div>
```

### Good
```jsx
<div>{message}</div>
```

---

## Interactive elements must support focus [error] [keyboard, a11y]

`jsx-a11y/interactive-supports-focus`. An element with `role="button"` or `role="link"` that isn't focusable (no `tabIndex`) is announced as interactive but can't be activated by keyboard.

### Bad
```jsx
<div role="button" onClick={save}>Save</div>
```

### Good
```jsx
<div role="button" tabIndex={0} onClick={save} onKeyDown={handleKey}>Save</div>
// better:
<button onClick={save}>Save</button>
```
