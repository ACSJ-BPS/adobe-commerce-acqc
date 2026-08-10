# Process — Quality

> Part of [Process](./README.md).

## Screen-reader QA sign-off (NVDA / JAWS / VoiceOver) before GA [warn] [a11y, qa]

Static a11y rules and Lighthouse catch ~30% of accessibility issues. The remaining 70% — focus order, live-region announcement timing, custom-component keyboard handling — requires a human running an actual screen reader. Block GA on a recorded SR walk-through of the top 5 user flows. Adobe's accessibility team can run this; do not skip it because "axe came back clean."

### Bad
```text
Lighthouse accessibility = 100. Ship it.
Two months later, blind customer files complaint that the modal trap-focuses incorrectly
and they cannot dismiss it without refreshing the page.
```

### Good
```markdown
# Screen Reader QA (sign-off required pre-GA)

## Coverage matrix (record video for each cell)
|  | NVDA + Chrome | JAWS + Edge | VoiceOver + Safari |
|---|---|---|---|
| Login flow         | ✓ 2026-04-22 | ✓ 2026-04-22 | ✓ 2026-04-23 |
| Primary dashboard  | ✓ 2026-04-22 | ✓ 2026-04-22 | ✓ 2026-04-23 |
| Create-order flow  | ✓ 2026-04-22 | ✓ 2026-04-22 | ✓ 2026-04-23 |
| Settings           | ✓ 2026-04-22 | ✓ 2026-04-22 | ✓ 2026-04-23 |
| Error / empty / loading states | ✓ | ✓ | ✓ |

Sign-off: A. Rodriguez (a11y lead), 2026-04-25.
Issues found: 3 (filed as PROJ-481, PROJ-482, PROJ-483). All resolved before GA.
```

---

## Author and admin training docs delivered before content-team handoff [info] [training, ops]

If your App Builder app exposes an admin UI to non-engineers (content authors, marketing ops, customer-success), they need written + recorded training, not just a Slack ping. Without it, the engineering team becomes the de-facto support desk for the first 6 months. Capture in `docs/training/` with screenshots or a 10-minute Loom for each high-frequency task.

### Bad
```text
Marketing ops Slack: "How do I set the campaign live date?"
Engineer: "Click the gear icon, then... actually let me share my screen."
This conversation repeats 4 times in week 1.
```

### Good
```markdown
# Author/Admin Training (docs/training/)

- README.md — table of contents
- 01-set-campaign-live-date.md — screenshots + 3-min Loom
- 02-roll-back-a-published-asset.md — runbook
- 03-bulk-upload-products.md — CSV format + validation rules
- 04-troubleshooting.md — common errors + how to interpret them

Hand-off meeting: 2026-05-02 with marketing ops lead.
On-call shadow rotation: 2 weeks post-launch.
```

---

## Pre-go-live load test against expected peak traffic [warn] [perf, ops]

A load test that mimics realistic peak traffic (e.g., 3× your forecast peak QPS, sustained for 30 minutes) is the only way to know your I/O Runtime quotas, your downstream API rate limits, and your DB connection pools all hold under stress. App Builder's license-pack quotas mean over-design has a hard ceiling; under-design means a 429 storm at peak. Capture the test plan, the result, and the headroom in `docs/performance/load-test-2026-04.md`.

### Bad
```text
Peak Black Friday traffic hits production. Action concurrency cap reached at minute 4.
Runtime returns 429 to 30% of users for 90 minutes. No prior load test was run.
```

### Good
```markdown
# Pre-GA Load Test 2026-04-28

## Plan
- Tool: k6 (3 regions, 5,000 VUs ramping to peak over 5 min, 30-min sustain)
- Target: stage workspace
- Forecasted peak: 1,200 req/s; tested at 3,600 req/s

## Result
- p95 latency under 3,600 req/s sustained: 412ms (SLO: <500ms ✓)
- 429 rate: 0.04% (acceptable; bounded by aio-lib-state ops/s)
- Container cold-start rate: 1.2% (acceptable)
- Downstream dependency: Stripe webhook timeout at 2,800 req/s — capacity bumped pre-GA

Sign-off: Eng Director, 2026-04-29
```
