# Deployment — Environments

> Part of [Deployment](./README.md).

## Deploy to a Stage workspace before Production — never straight to prod [error] [pipeline, safety]

App Builder workspaces are full environment isolation: separate namespaces, credentials, runtime, gateway. Use Stage to run smoke + e2e against the deployed artifact, not just against a sandbox copy. A "this worked in dev" deploy that goes straight to prod is the most common cause of avoidable incidents.

### Bad
```bash
aio app use --workspace Production
aio app deploy
```

### Good
```bash
aio app use --workspace Stage
aio app deploy
aio app test --smoke
aio app test --e2e
# only then:
aio app use --workspace Production
aio app deploy
```

---

## Use environment-specific workspaces, not branches, for env separation [error] [pipeline, config]

Stage and Production should be App Builder *workspaces*, not git branches. Workspaces give you isolated namespaces, separate IMS credentials, separate Runtime quotas, separate gateway URLs. Branch-based env separation (single workspace, different code paths) loses all of that and creates cross-environment incidents.

### Bad
```js
async function main (params) {
  const env = params.NODE_ENV   // 'prod' or 'stage'
  const dbUrl = env === 'prod' ? PROD_DB : STAGE_DB
  // shared workspace, shared credentials, one mistake leaks prod data into stage
}
```

### Good
```bash
# one workspace per environment
aio app use --workspace Stage
aio app deploy

aio app use --workspace Production
aio app deploy
# different namespaces, different IMS clients, different default params
```
