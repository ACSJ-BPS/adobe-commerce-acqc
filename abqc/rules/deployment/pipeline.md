# Deployment — Pipeline

> Part of [Deployment](./README.md).

## Pre-deploy gate: code review, tests, security scan, perf check, docs [error] [pipeline, gate]

A deploy that bypasses any of these is a deploy that ships a regression. Every workspace beyond a developer sandbox should have a CI gate that runs the full set: code review approval, `aio app test` (unit + e2e), `npm audit --audit-level=high`, performance smoke, and updated `CHANGELOG.md` / `README.md`. The gate is the contract between dev and operations.

### Check
```yaml
type: builtin
id: missing-predeploy-gate
scope: repo
```

### Bad
```yaml
# .github/workflows/deploy.yml
on: { push: { branches: [main] } }
jobs:
  deploy:
    steps:
      - uses: actions/checkout@v4
      - run: aio app deploy   # straight to prod, no checks
```

### Good
```yaml
on: { push: { branches: [main] } }
jobs:
  gate:
    steps:
      - uses: actions/checkout@v4
      - run: npm ci
      - run: aio app test                          # unit + smoke
      - run: npm audit --audit-level=high
      - run: ./scripts/check-changelog-updated.sh
      - run: ./scripts/perf-budget-check.sh        # bundle size, action timing
  deploy:
    needs: gate
    steps:
      - uses: actions/checkout@v4
      - run: aio app deploy --workspace Production
```

---

## Use deployment hooks (post-app-deploy.js) for verification, not for state changes [warn] [hooks, ops]

App Builder's `hooks` config in `app.config.yaml` runs custom scripts at lifecycle stages — `pre-app-deploy`, `post-app-deploy`, `pre-app-undeploy`, etc. Use them for post-deploy verification (smoke-ping each action, confirm gateway provisioning, post deploy notification), not for state migrations or business-logic side effects. Hooks aren't transactional with the deploy — a failed hook leaves you with deployed code and unmigrated state.

### Check
```yaml
type: builtin
id: deploy-hook-mutates-state
files: ["**/post-app-deploy.js"]
```

### Bad
```js
// hooks/post-app-deploy.js — runs DB migrations as a "deployment step"
async function postDeploy () {
  await migrateDatabaseSchema()   // not transactional with deploy; partial failure = corruption
  await seedProductionData()
}
module.exports = postDeploy
```

### Good
```js
// hooks/post-app-deploy.js — verify deploy, notify, never mutate state
const fetch = require('node-fetch')

async function postDeploy ({ appConfig }) {
  const baseUrl = appConfig.app.actionUrl
  for (const action of ['health/ping', 'orders/list']) {
    const res = await fetch(`${baseUrl}/${action}`)
    if (!res.ok) throw new Error(`smoke check failed for ${action}: ${res.status}`)
  }
  await postSlackNotification(`Deploy ${process.env.GITHUB_SHA?.slice(0, 7)} complete`)
}
module.exports = postDeploy
```

---

## CI must run aio app test, not just jest [warn] [pipeline, tooling]

`aio app test` honors `app.config.yaml`'s test-path config, applies coverage thresholds set there, and respects extension scoping in multi-extension apps. CI that calls `npx jest` directly may pick up the wrong files, miss the coverage gate, or skip e2e tests entirely.

### Check
```yaml
type: builtin
id: raw-jest-ci
scope: repo
```

### Bad
```yaml
- run: npx jest --coverage
```

### Good
```yaml
- run: aio app test
- run: aio app test --e2e --workspace Stage
```

---

## Use aio app deploy --no-build when the build runs in a separate CI step [info] [cicd, performance]

Re-running `aio app build` inside the deploy step doubles CI time and risks producing a different build than the one tested. If the pipeline already has a build job that produced a verified `dist/`, use `--no-build` so deploy uses that artifact.

### Check
```yaml
type: builtin
id: missing-no-build-flag
scope: repo
```

### Bad
```yaml
- run: aio app build
- run: aio app test
- run: aio app deploy   # rebuilds, may produce different output
```

### Good
```yaml
- run: aio app build
- run: aio app test
- run: aio app deploy --no-build
```

---

## Each Git branch or environment maps to specific `AIO_PROJECT_*` and `AIO_RUNTIME_*` secrets — explicit, not implicit [error] [cicd, env]

Implicit mapping ("main deploys to whichever workspace was last selected") creates incidents where a stage deploy lands in prod. Each branch/environment must have its own secret set, gated on the workflow trigger so the wrong combination cannot fire.

### Check
```yaml
type: builtin
id: ungated-shared-secrets
scope: repo
```

### Bad
```yaml
deploy:
  steps:
    - run: aio app deploy
      env:
        AIO_RUNTIME_AUTH: ${{ secrets.AIO_AUTH }}  # whatever workspace last set
```

### Good
```yaml
deploy-stage:
  if: github.ref == 'refs/heads/main'
  environment: stage
  steps:
    - run: aio app deploy
      env:
        AIO_PROJECT_WORKSPACE: ${{ vars.STAGE_WORKSPACE }}
        AIO_RUNTIME_AUTH:      ${{ secrets.STAGE_RUNTIME_AUTH }}

deploy-prod:
  if: startsWith(github.ref, 'refs/tags/v')
  environment: prod
  needs: [deploy-stage]
  steps:
    - run: aio app deploy
      env:
        AIO_PROJECT_WORKSPACE: ${{ vars.PROD_WORKSPACE }}
        AIO_RUNTIME_AUTH:      ${{ secrets.PROD_RUNTIME_AUTH }}
```
