# Runtime — Quality Gates

> Part of [Runtime](./README.md).

## `<html lang>` matches the user-facing locale at runtime [warn] [a11y, runtime, i18n]

The static rule says set `lang="en"` on `<html>`. The runtime rule says it must match what the user is actually seeing — if the SPA switches locale via `i18n.changeLanguage('fr')` and the rendered text is French but `<html lang="en">`, screen readers pronounce French text with English phonetics, search engines mis-classify the page, and hyphenation breaks. Update `document.documentElement.lang` whenever locale changes.

### Check
```yaml
type: builtin
id: locale-lang-sync
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```

### Bad
```jsx
function App () {
  const [locale, setLocale] = useState('fr')
  return <div>{t('welcome', { locale })}</div>
  // <html lang="en"> in index.html — never updated; mismatched with French content
}
```

### Good
```jsx
function App () {
  const [locale, setLocale] = useState('fr')
  useEffect(() => { document.documentElement.lang = locale }, [locale])
  return <div>{t('welcome', { locale })}</div>
}
```

---

## Zero `console.error` calls on the golden-path traversal [error] [debug, observability, runtime]

`console.error` in production indicates either an unhandled exception or a developer-emitted error that nobody acted on. Either way, real users hit it. A golden-path smoke test (login → primary action → logout) should produce zero console errors. Anything else means the deploy ships a known broken state.

### Check
```yaml
type: builtin
id: golden-path-risk-factors
scope: repo
```

### Bad
```text
Golden-path Playwright smoke run on Stage:
> [error] Failed to fetch /api/v1/profile (401)
> [error] React ErrorBoundary caught: TypeError: Cannot read property 'name' of undefined
→ deploy approved anyway because "tests passed"
```

### Good
```js
// playwright.config.ts — fail any spec that emits a console error
test.beforeEach(async ({ page }) => {
  page.on('console', (msg) => {
    if (msg.type() === 'error') throw new Error(`Unexpected console.error: ${msg.text()}`)
  })
})

test('golden path: login → dashboard → logout', async ({ page }) => {
  await page.goto('/login')
  await page.fill('[name=email]', 'qa@adobe.com')
  await page.click('button[type=submit]')
  await page.waitForURL('/dashboard')
  await page.click('text=Logout')
  await page.waitForURL('/login')
})
```
