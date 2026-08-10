# Process — Operations

> Part of [Process](./README.md).

## Incident-response runbook with named on-call rotation [warn] [ops, governance]

A runbook tells the on-call who to page, where the dashboards are, how to roll back, and what counts as a Sev-1. Without one, the first Sev-1 produces 30 minutes of confusion before anyone touches the rollback. The deployment.md rule covers the rollback *mechanism*; this rule covers the *human process* around it.

### Bad
```text
Sev-1 at 3am:
"Who do I page?"
"Try slacking #engineering?"
"Where's the rollback script?"
"Was it Greg who knew? He's on PTO."
```

### Good
```markdown
# Incident Response Runbook v1.4

## Sev definitions
- Sev-1: customer-facing outage, data loss, security incident → page primary on-call immediately
- Sev-2: degraded performance > 30 min → file ticket, page during business hours
- Sev-3: minor, non-blocking → ticket only

## On-call rotation (PagerDuty schedule "appbuilder-prod")
- Primary: rotates weekly Mon 09:00 PT
- Secondary: rotates with primary
- Manager escalation after 30 min: J. Smith

## First-30-minutes checklist
1. Acknowledge page in PagerDuty
2. Open #incidents Slack channel, post status
3. Open Activation dashboard: <link>
4. If error rate > 5% sustained → ./scripts/rollback.sh <last-good-tag>
5. Update status page within 15 min

## Postmortem
- Required for any Sev-1 within 5 business days
- Template: docs/incidents/postmortem-template.md
```

---

## Post-go-live monitoring dashboards reviewed in week 1 and month 1 [warn] [ops]

The week-1 review catches deployment-induced regressions before they become quarterly tech debt; the month-1 review catches slow drifts (memory leak, cache hit ratio decay, error budget burn). Without scheduled reviews, drift is detected only when a customer complains. Put both reviews on the calendar at GA, not "we'll get to it."

### Bad
```text
Week 4 post-launch: error rate has been at 4% (baseline: 0.2%) for 18 days.
Nobody noticed because nobody opened the dashboard.
```

### Good
```markdown
# Post-GA Monitoring Cadence

## Week 1 (every weekday 10:00 standup)
- p95 latency by route — alert if > baseline +30%
- Error rate by action — alert if > 1% sustained
- 429 rate — alert if > 0.5% sustained
- Top 10 slowest queries — investigate any new entry

## Month 1 (weekly 1-hour review with eng lead + on-call)
- Trend lines for above + container reuse %, cold-start %, memory tier utilization
- License-pack quota burn rate vs. forecast
- Top user-reported issues (linked from support tickets)
- One action item per review: ship, document, or schedule

## Quarter (monthly thereafter)
- Architectural retro
- Quota planning for next quarter
```
