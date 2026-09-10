# Process — Discovery

> Part of [Process](./README.md).

## Document business KPIs and SEO goals before kickoff [warn] [discovery]

Without a written list of what success looks like (conversion lift, time-to-first-action, organic-traffic target), every later trade-off becomes an opinion. A written KPI doc forces the team to choose: are we optimizing for time-to-first-paint or feature breadth? It also gives post-launch the baseline to measure against. Capture in `docs/discovery/kpis.md` or the project tracker.

### Check
```yaml
type: builtin
id: kpi-doc
scope: repo
```

### Bad
```text
Project kickoff:
PM says "we want to ship in Q2." No measurable goal beyond "ship."
Six months later, leadership asks "did this work?" — nobody can answer.
```

### Good
```markdown
# Project KPIs (signed off 2026-04-30)

| KPI | Baseline | Target (90 days post-launch) |
|---|---|---|
| Daily active users | 12,000 | 18,000 (+50%) |
| Median time-to-first-action | 14s | <8s |
| Self-service ticket deflection | 22% | 35% |
| Organic search → product-page sessions | 4,200/mo | 6,000/mo |

Sign-offs: Product (J. Smith), Eng (R. Patel), Marketing (D. Lee)
```

---

## Configure Real User Monitoring before GA [warn] [observability, ops]

Real User Monitoring (RUM) captures Core Web Vitals, errors, and user flows from production traffic — the only source of truth for actual user experience. Synthetic checks miss device/network diversity. App Builder apps should ship a small RUM beacon (Adobe RUM, web-vitals + a custom action, or Datadog RUM) on day one of GA, not "after we launch and have time." Without it, regressions discovered post-launch are unattributable.

### Check
```yaml
type: builtin
id: missing-rum
scope: repo
```

### Bad
```text
Launched without RUM. Two weeks later customers report slowness.
No data on which routes, which devices, which network — investigation takes 10 days.
```

### Good
```js
// embed once in the SPA root
import { onLCP, onCLS, onINP, onFID } from 'web-vitals'

function reportToBeacon (metric) {
  navigator.sendBeacon('/api/v1/rum',
    JSON.stringify({ name: metric.name, value: metric.value, id: metric.id, route: location.pathname }))
}
onLCP(reportToBeacon)
onCLS(reportToBeacon)
onINP(reportToBeacon)
onFID(reportToBeacon)
```

```yaml
# manifest.yml — backing action that ingests beacons into your observability stack
actions:
  rum-beacon:
    function: actions/rum-beacon/index.js
    web: 'yes'
    annotations:
      require-adobe-auth: false
      final: true
```
