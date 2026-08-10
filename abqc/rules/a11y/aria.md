# Accessibility — ARIA correctness

> Part of [Accessibility](./README.md).

## All aria-* props must be valid ARIA attributes [error] [aria]

`jsx-a11y/aria-props`. Typos in ARIA attributes (`aria-lable`, `aria-haspopUp`) silently disable the screen-reader feature. The rule validates each attribute against the WAI-ARIA spec.

### Bad
```jsx
<button aria-lable="Close">×</button>
<div aria-expand="true">...</div>
```

### Good
```jsx
<button aria-label="Close">×</button>
<div aria-expanded="true">...</div>
```

---

## ARIA role attribute must be a valid role [error] [aria]

`jsx-a11y/aria-role`. Custom or misspelled roles (`role="buton"`, `role="dialogue"`) are ignored by assistive technology. The rule validates against the WAI-ARIA role spec.

### Bad
```jsx
<div role="buton" onClick={save}>Save</div>
<div role="dialogue">...</div>
```

### Good
```jsx
<button onClick={save}>Save</button>
<div role="dialog" aria-modal="true">...</div>
```

---

## ARIA properties must match the role's required and supported set [error] [aria]

`jsx-a11y/role-has-required-aria-props` + `role-supports-aria-props`. A `role="checkbox"` without `aria-checked` is broken; `aria-selected` on a `role="button"` is ignored. The combination has to be valid.

### Bad
```jsx
<div role="checkbox" onClick={toggle}>Subscribe</div>
<button aria-selected="true">Tab 1</button>
```

### Good
```jsx
<div role="checkbox" aria-checked={subscribed} tabIndex={0} onClick={toggle} onKeyDown={handleKey}>
  Subscribe
</div>
<button role="tab" aria-selected={true}>Tab 1</button>
```

---

## Form labels must associate with their control [error] [forms, a11y]

`jsx-a11y/label-has-associated-control`. A floating `<label>` without `htmlFor` or wrapping the control fails screen-reader form announcement — the user hears the input type but never the field name.

### Bad
```jsx
<label>Email</label>
<input type="email" />
```

### Good
```jsx
<label htmlFor="email-field">Email</label>
<input id="email-field" type="email" />

// or wrap:
<label>Email <input type="email" /></label>

// or use Spectrum which handles this:
<TextField label="Email" type="email" />
```

---

## Don't put aria-hidden on focusable elements [error] [a11y]

`jsx-a11y/no-aria-hidden-on-focusable`. `aria-hidden="true"` removes the element from the accessibility tree, but if the element is still focusable via Tab, keyboard users land on an "invisible" element with no announcement — completely broken.

### Bad
```jsx
<button aria-hidden="true">Submit</button>
<a href="/help" aria-hidden="true">Help</a>
```

### Good
```jsx
// either remove aria-hidden:
<button>Submit</button>

// or remove from tab order too:
<button aria-hidden="true" tabIndex={-1}>Submit</button>

// or hide visually only:
<span className="sr-only">Submit</span>
```
