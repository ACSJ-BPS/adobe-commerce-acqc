# Accessibility — Structural

> Part of [Accessibility](./README.md).

## No distracting elements (marquee, blink) [error] [a11y, deprecated]

`jsx-a11y/no-distracting-elements`. `<marquee>` and `<blink>` are deprecated, fail vestibular-disorder accessibility, and trigger seizure risk for photosensitive users. There is no valid use case in a 2026 application.

### Bad
```jsx
<marquee>Limited time offer!</marquee>
```

### Good
```jsx
<Banner variant="info">Limited time offer — ends Friday</Banner>
```

---

## Iframes must have a title attribute [error] [a11y]

`jsx-a11y/iframe-has-title`. Screen readers announce iframes by title; missing title means the user hears "frame" with no context. Required even when the iframe is sandboxed for security.

### Bad
```jsx
<iframe src="/embedded-dashboard" />
```

### Good
```jsx
<iframe src="/embedded-dashboard" title="Sales dashboard for Q1 2026" />
```
