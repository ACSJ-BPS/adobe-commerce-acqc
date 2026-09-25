# Deployment — Rollback

> Part of [Deployment](./README.md).

## Monitor activation logs after deploy — error rate, p95, throttling [warn] [monitoring, ops]

Adobe Developer Console exposes per-action activation metrics: success/failure counts, p50/p95/p99 latency, 429 throttling, container reuse. Wire these to your monitoring (New Relic, Datadog, Grafana via API export) and alert on a sustained error-rate spike or latency drift after every deploy. A deploy that succeeds in CI but breaks 10% of real traffic is invisible without this.

### Check
```yaml
type: builtin
id: missing-post-deploy-monitoring
scope: repo
```

### Bad
```text
# deploy succeeded → close PR → walk away
# error rate goes from 0.1% → 4% → noticed on Monday by a customer ticket
```

### Good
```text
post-deploy automation:
  1. Wait 5 minutes for warm-up
  2. Pull last 5 minutes of activations from Runtime API
  3. Compare error rate, p95 against pre-deploy baseline
  4. If error rate +1pp or p95 +30%, page on-call and offer one-click rollback
```

---

## Have a one-command rollback — never "redeploy the previous commit" under pressure [error] [rollback, safety]

A real incident is the worst time to discover that "rollback" means cherry-picking commits, rebuilding, and re-deploying. Tag every prod deploy (`prod-2026-04-29-1430`), keep the previous artifact retrievable, and have a single command (or button) that restores it. `aio app deploy --workspace Production` from a known-good tag is acceptable; building from main is not.

### Check
```yaml
type: builtin
id: missing-rollback-script
scope: repo
```

### Bad
```bash
# 3am, prod is down
git log --oneline | head -20
# "uhh which commit was it before this morning's deploy?"
git revert abc123
git push
# (waits 12 minutes for CI gate, builds, deploys)
```

### Good
```bash
# tag every prod deploy in CI
git tag "prod-$(date +%Y-%m-%d-%H%M)"
git push --tags

# rollback is one command:
./scripts/rollback.sh prod-2026-04-29-1430
# which: git checkout <tag>, builds, deploys, runs smoke, verifies — all under 5 min
```
