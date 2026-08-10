# Runtime — Resource Loading

> Part of [Runtime](./README.md).

## Resource hints (`<link rel=preconnect>`) for known third-party origins [info] [perf, hints]

Preconnect tells the browser to begin DNS / TCP / TLS to a known third-party origin before the first request hits it — saving 100–300ms per origin on the critical path. App Builder apps almost always reach Adobe IMS (`https://ims-na1.adobelogin.com`) and the Runtime gateway; preconnecting both cuts measurable LCP on cold loads. Verify in the Network waterfall that DNS/TLS for those origins doesn't appear inside the LCP window.

### Bad
```html
<head>
  <title>App</title>
  <!-- no preconnect; browser only learns about adobelogin.com when first XHR fires at LCP+ -->
</head>
```

### Good
```html
<head>
  <title>App</title>
  <link rel="preconnect" href="https://ims-na1.adobelogin.com" crossorigin>
  <link rel="preconnect" href="https://adobeioruntime.net" crossorigin>
  <link rel="dns-prefetch" href="https://cdn.example.com">
</head>
```

---

## Service worker registers with valid scope (PWA only) [info] [pwa, runtime]

If the app advertises itself as a PWA (manifest.json, install prompt, offline strategy), the service worker registration must succeed at runtime — the `navigator.serviceWorker.controller` should be non-null after first navigation. A failed registration leaves the app online-only and silently strips offline support. For non-PWA apps this rule does not apply; do not register a service worker speculatively.

### Bad
```js
// sw.js exists, but registration silently fails because the scope is wrong
navigator.serviceWorker.register('/static/sw.js')   // scope = /static/, can't intercept root
```

### Good
```js
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js', { scope: '/' })
    .then((reg) => console.info('SW registered with scope', reg.scope))
    .catch((err) => {
      console.error('SW registration failed', err)
      reportToRum('sw_registration_failed', { message: err.message })
    })
}
```
