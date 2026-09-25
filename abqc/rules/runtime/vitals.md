# Runtime — Lighthouse & Core Web Vitals

> Part of [Runtime](./README.md).

## Lighthouse Performance score >= 90 on the production build [warn] [perf, lighthouse]

Lighthouse aggregates dozens of weighted metrics (FCP, LCP, TBT, CLS, Speed Index) into a single score that correlates with real-world user experience. A score below 90 on the production build indicates at least one Core Web Vital is at-risk; below 50 means the user experience is broken on mid-tier devices. Run Lighthouse against the deployed Stage workspace, not local dev (which masks bundle-size and CDN issues).

### Check
```yaml
type: builtin
id: lighthouse-anti-pattern-checklist
scope: repo
```

### Bad
```text
Lighthouse run on `localhost:9080` (aio app dev) — score 95.
Production deploy never re-measured.
Real users on slow-3G see LCP > 6s; team is unaware.
```

### Good
```bash
# CI step against deployed Stage URL
npx lighthouse https://stage--my-app.adobeio-static.net \
  --preset=desktop \
  --only-categories=performance \
  --output=json \
  --output-path=./reports/lighthouse-stage.json

# fail the build if score < 90
node ./scripts/check-lighthouse-score.js --min 90
```

---

## Largest Contentful Paint < 2.5s on slow-3G [error] [perf, cwv]

LCP is the timestamp of the largest visible element rendering. Google's Core Web Vitals threshold for "good" is 2.5s on a Moto G4 over slow-3G. Above 4s is "poor" and degrades search ranking. The metric is dominated by hero-image size, render-blocking JS, and origin TTFB — all of which need a real browser to observe.

### Check
```yaml
type: builtin
id: lcp-risk-factors
scope: repo
```

### Bad
```text
LCP on hero image: 5.8s
Cause: 2.4 MB un-optimized JPEG, no preload hint, blocked behind 480 KB JS bundle.
```

### Good
```html
<!-- Preload the LCP image, ship a sized AVIF, decode async -->
<link rel="preload" as="image" href="/hero.avif" fetchpriority="high">
<img src="/hero.avif" alt="..." width="1920" height="1080"
     decoding="async" fetchpriority="high">
```

```js
// Verify in browser via PerformanceObserver
new PerformanceObserver((list) => {
  for (const entry of list.getEntries()) {
    if (entry.entryType === 'largest-contentful-paint' && entry.startTime > 2500) {
      console.warn('LCP regression', entry.startTime, entry.element)
    }
  }
}).observe({ type: 'largest-contentful-paint', buffered: true })
```

---

## Cumulative Layout Shift < 0.1 across all top routes [error] [perf, cwv]

CLS measures unexpected layout shifts during page load — content that jumps because an image loaded late or an ad slot expanded. The "good" threshold is < 0.1. The most common causes are unsized media (`<img>` without width/height), late-loading fonts that reflow text, and ads/embeds that resize after render.

### Check
```yaml
type: builtin
id: cls-risk-factors
scope: repo
```

### Bad
```html
<img src="/banner.jpg" alt="banner">   <!-- no width/height — reflows on load -->
<iframe src="https://feed.partner.com"></iframe>   <!-- expands after embed loads -->
```

### Good
```html
<img src="/banner.jpg" alt="banner" width="1200" height="320">
<iframe src="https://feed.partner.com" width="600" height="400" loading="lazy"></iframe>
```

```js
// observe CLS in real-user monitoring
let clsValue = 0
new PerformanceObserver((list) => {
  for (const entry of list.getEntries()) {
    if (!entry.hadRecentInput) clsValue += entry.value
  }
  if (clsValue > 0.1) reportToRum('cls_regression', clsValue)
}).observe({ type: 'layout-shift', buffered: true })
```

---

## Interaction to Next Paint < 200ms on primary interactions [warn] [perf, cwv]

INP replaced FID in March 2024 as the responsiveness Core Web Vital. It measures the worst end-to-end delay between a user input (click, tap, key) and the next paint, across the page lifetime. The "good" threshold is < 200ms. Long INP usually traces to a blocking event handler doing synchronous work (large JSON parse, layout-forcing reads, expensive React renders).

### Check
```yaml
type: builtin
id: cpu-bound-sync-handler
files: ["**/*.{js,jsx,ts,tsx}"]
```

### Bad
```jsx
<button onClick={() => {
  const result = expensiveSyncCalculation(items)  // 800ms blocking
  setResult(result)
}}>Compute</button>
```

### Good
```jsx
<button onClick={async () => {
  setLoading(true)
  // yield to the event loop, run heavy work in a worker, or chunk it
  const result = await runInWorker(() => expensiveSyncCalculation(items))
  setResult(result)
  setLoading(false)
}}>Compute</button>
```
