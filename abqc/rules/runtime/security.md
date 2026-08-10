# Runtime — Security

> Part of [Runtime](./README.md).

## Zero `Refused to load` CSP violations in the browser console [error] [security, csp, runtime]

A static-analysis rule can verify the `Content-Security-Policy` header is set; only a real browser can verify the policy actually permits the resources the page tries to load. `Refused to load the script` / `Refused to apply inline style` console errors mean either the CSP is too strict (functionality broken) or too loose (genuine XSS attempts not blocked). Either way the deploy is broken — fix before promotion.

### Bad
```text
DevTools console on Stage:
Refused to load script 'https://cdn.tracking.com/x.js' because it violates CSP directive "script-src 'self'"
Refused to apply inline style because it violates CSP directive "style-src 'self'"
→ analytics broken in production, but never noticed because no one opened DevTools after deploy
```

### Good
```js
// post-deploy smoke test that fails on any CSP violation
import { chromium } from 'playwright'

const browser = await chromium.launch()
const page = await browser.newPage()
const violations = []
page.on('console', (msg) => {
  if (msg.type() === 'error' && /Refused to|Content Security Policy/.test(msg.text())) {
    violations.push(msg.text())
  }
})
await page.goto('https://stage--my-app.adobeio-static.net/')
await page.waitForLoadState('networkidle')
if (violations.length) {
  console.error('CSP violations detected:', violations)
  process.exit(1)
}
```

---

## Zero Mixed Content warnings on production HTTPS pages [error] [security, transport]

A page served over HTTPS that loads any subresource over HTTP triggers a Mixed Content warning, downgrades the lock icon, and (for active mixed content like scripts) is blocked entirely by modern browsers. Always-on HTTPS is the static rule; this rule verifies it actually held in the browser.

### Bad
```text
Page: https://my-app.adobeio-static.net/dashboard
Console: "Mixed Content: The page was loaded over HTTPS, but requested an insecure script 'http://cdn.legacy.com/widget.js'. This request has been blocked."
→ widget broken in production
```

### Good
```html
<!-- audit every resource URL: must be https:// or protocol-relative -->
<script src="https://cdn.example.com/widget.js"></script>
<link rel="stylesheet" href="https://cdn.example.com/theme.css">
```

```bash
# CI grep for any http:// URL in built assets
if grep -rE 'http://[^"]+' dist/ --include='*.{html,js,css}'; then
  echo "Mixed content risk: insecure URL in build artifacts"
  exit 1
fi
```

---

## Authenticated routes carry the expected Authorization header at runtime [warn] [auth, runtime]

A static rule can verify your fetch wrapper attaches `Authorization`. Only a runtime check confirms it actually fired — token refresh races, missing await, conditionally-skipped headers all bypass the static rule. Verify via DevTools Network tab on the golden path: every call to `/api/*` carries `Authorization: Bearer ...`.

### Bad
```text
DevTools Network tab on Stage:
GET /api/v1/orders → 200 OK (no Authorization header — caller is anonymous)
→ silently returns public-only orders, missing customer-specific data
```

### Good
```js
// Playwright assertion in CI
test('all /api requests carry Authorization on auth\'d routes', async ({ page }) => {
  const unauth = []
  page.on('request', (req) => {
    if (req.url().includes('/api/') && !req.headers()['authorization']) {
      unauth.push(req.url())
    }
  })
  await loginAndNavigateToDashboard(page)
  expect(unauth).toEqual([])
})
```
