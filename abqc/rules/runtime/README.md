# Runtime (Browser-Verified)

15 rules covering Lighthouse, Core Web Vitals, runtime CSP, performance, quality gates, and resource loading.

## Files

| File | Rules | Coverage |
|---|---|---|
| [vitals.md](./vitals.md) | 4 | Lighthouse ≥ 90, LCP, CLS, INP |
| [security.md](./security.md) | 3 | Runtime CSP, mixed-content, Authorization |
| [performance.md](./performance.md) | 4 | FOIT, detached DOM, layout thrash, bundle budget |
| [quality.md](./quality.md) | 2 | Console.error gate, golden-path |
| [loading.md](./loading.md) | 2 | Resource hints, service worker |
| **Total** | **15** | |

## Sources

- [web.dev — Core Web Vitals](https://web.dev/articles/vitals)
- [web.dev — Largest Contentful Paint](https://web.dev/articles/lcp)
- [web.dev — Cumulative Layout Shift](https://web.dev/articles/cls)
- [web.dev — Interaction to Next Paint](https://web.dev/articles/inp)
- [Lighthouse Performance scoring](https://developer.chrome.com/docs/lighthouse/performance/performance-scoring)
- [MDN — Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP)
- [MDN — Mixed content](https://developer.mozilla.org/en-US/docs/Web/Security/Mixed_content)
- [MDN — font-display](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display)
- [Chrome DevTools — Memory: detached DOM trees](https://developer.chrome.com/docs/devtools/memory-problems)
- [web.dev — Avoid large layout shifts](https://web.dev/articles/optimize-cls)
- [Playwright — Network](https://playwright.dev/docs/network)
- [MDN — Resource hints (preconnect)](https://developer.mozilla.org/en-US/docs/Web/HTML/Attributes/rel/preconnect)
- ui-audit-main `templates/Browser_Audit_Checklist.csv` — source-of-rows for this file
