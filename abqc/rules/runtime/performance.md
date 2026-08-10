# Runtime — Performance

> Part of [Runtime](./README.md).

## No FOIT — fonts must render with `font-display: swap` or `optional` [warn] [perf, fonts]

Flash of Invisible Text (FOIT) happens when the browser hides text until the web font finishes loading — up to 3 seconds of blank content on slow connections. The fix is `font-display: swap` (show fallback immediately, swap in the web font when ready) or `optional` (use the web font only if it loads in <100ms). The default `block` value is what causes the bug.

### Bad
```css
@font-face {
  font-family: 'Adobe Clean';
  src: url('/fonts/adobe-clean.woff2') format('woff2');
  /* no font-display → defaults to 'block' → FOIT up to 3s */
}
```

### Good
```css
@font-face {
  font-family: 'Adobe Clean';
  src: url('/fonts/adobe-clean.woff2') format('woff2');
  font-display: swap;   /* render fallback first, swap when ready */
}
```

---

## No detached DOM nodes after navigation [warn] [perf, memory, runtime]

Detached DOM nodes are elements removed from the document but still referenced by JS — typically by an event listener that never got cleaned up, a `useEffect` without a teardown, or a closure capturing a node. Over a long session they leak memory and degrade interaction responsiveness. Only visible via DevTools Memory tab → Heap snapshot → "Detached".

### Bad
```jsx
useEffect(() => {
  const onScroll = () => updatePosition()
  window.addEventListener('scroll', onScroll)
  // no cleanup → component unmounts but listener (and closure) keeps the DOM node alive
}, [])
```

### Good
```jsx
useEffect(() => {
  const onScroll = () => updatePosition()
  window.addEventListener('scroll', onScroll, { passive: true })
  return () => window.removeEventListener('scroll', onScroll)
}, [])
```

```bash
# DevTools Memory tab → take heap snapshot → filter "Detached"
# Result should be 0 after navigating between routes 5x
```

---

## No layout thrashing — purple Recalculate-Layout blocks under 50ms total [warn] [perf, runtime]

The Performance flame chart shows synchronous layout/recalc-style work as purple blocks. A single block over ~16ms drops a frame; cumulative purple exceeding 50ms per interaction means the page is layout-thrashing — typically because JS reads a layout property (`offsetHeight`, `getBoundingClientRect`) inside a write loop, forcing the browser to reflow on every iteration.

### Bad
```js
// reads offsetHeight inside a write loop → reflow per item
for (const item of items) {
  item.style.height = `${item.offsetHeight + 10}px`
}
```

### Good
```js
// batch reads first, then batch writes — single reflow
const heights = items.map((it) => it.offsetHeight)
items.forEach((it, i) => { it.style.height = `${heights[i] + 10}px` })
```

---

## First-paint JS bundle < 200KB gzipped [warn] [perf, bundle]

A 200KB-gzipped budget for the entry chunk is achievable with code-splitting and is what mid-tier devices over slow-3G can parse without dropping below 60fps for the rest of the page load. Once JS exceeds ~350KB gzipped, parse/compile time alone blocks the main thread enough to fail INP. Static rules cover code-splitting; this rule verifies the budget actually held against the production build.

### Bad
```text
dist/index.js — 1.2 MB raw, 412 KB gzipped
all routes ship every dependency at first paint; no lazy chunks
```

### Good
```bash
# CI step
npm run build
SIZE=$(stat -f%z dist/static/index*.js | head -n1)
GZIP_SIZE=$(gzip -c dist/static/index*.js | wc -c)
if [ $GZIP_SIZE -gt 204800 ]; then
  echo "Bundle budget exceeded: ${GZIP_SIZE} bytes gzipped (limit 200KB)"
  exit 1
fi
```

```js
// vite.config.js — surface the size in build output
export default { build: { reportCompressedSize: true, chunkSizeWarningLimit: 200 } }
```
