# App Builder — Tooling

> Part of [App Builder](./README.md).

## TypeScript actions require Webpack with ts-loader [warn] [build, typescript]

I/O Runtime executes the bundled JavaScript in `dist/`, not your `.ts` source. Without a Webpack/ts-loader build step, deploying a TypeScript action either fails the build or ships uncompiled TS that the runtime can't execute.

### Bad
```yaml
actions:
  typed:
    function: actions/typed/index.ts   # no Webpack config — deploy fails
```

### Good
```js
// webpack.config.js
module.exports = {
  module: { rules: [{ test: /\.ts$/, use: 'ts-loader' }] },
  resolve: { extensions: ['.ts', '.js'] }
}
```

```yaml
# app.config.yaml
actions:
  typed:
    function: actions/typed/index.ts
```

---

## aio app dev does not run scheduled events or webhook triggers [warn] [local-dev, gotcha]

The local development server (`aio app dev`) emulates synchronous web actions but cannot fire scheduled (cron-like) triggers or receive inbound I/O Events webhooks. Code paths that depend on those will look "fine" locally and break only after deploy. Test scheduled and event-driven actions by deploying to a non-prod workspace and invoking via `aio runtime action invoke`.

### Bad
```bash
# rely entirely on local dev for an event-handler action
aio app dev
# ... never tested that the registration handshake works
aio app deploy --prod
# webhook registration fails in prod
```

### Good
```bash
aio app dev                                              # iterate UI + sync actions locally
aio app deploy --workspace Stage                         # push event handler to a non-prod workspace
aio runtime action invoke my-pkg/event-handler -p challenge=test
# verify response: { "body": { "challenge": "test" } }
```

---

## Use aio runtime action invoke for cloud smoke tests [info] [tooling, smoke]

`aio runtime action invoke <actionName>` calls the deployed action on Adobe's cloud directly — bypassing the SPA, Unified Shell, and any other frontend. Fast feedback after deploy without waiting for a UI build. Pair with `--param-file` for fixture inputs.

### Bad
```bash
aio app deploy
# now click through the UI for 5 minutes to test if the deploy worked
```

### Good
```bash
aio app deploy
aio runtime action invoke commerce/list-orders --param-file ./test/fixtures/list-orders.json --result
```

---

## Track the aio CLI version — watch release notes for deprecations [info] [tooling, deps]

App Builder is actively maintained: extension points get added, defaults change, plugins evolve. An old `aio` CLI silently misses templates and emits stale manifests. Pin the CLI version in `package.json` `engines` (or commit a `.tool-versions`/`asdf` file) and review Adobe's release notes when bumping.

### Bad
```bash
# CI installs whatever is current; new dev installs whatever they happened to grab
npm install -g @adobe/aio-cli
```

### Good
```json
{
  "engines": { "node": ">=18", "@adobe/aio-cli": "^10.0.0" },
  "scripts": {
    "preinstall": "node ./scripts/check-aio-version.js"
  }
}
```

---

## Remove all template/sample actions and placeholder content before ship [error] [hygiene, deploy]

`aio app create` scaffolds with sample actions, default app names, and placeholder routes. Shipping with `application.id: my-app`, sample `generic` action, or "starter kit" copy in the README is the most common review failure for partner apps. Strip them before the first deploy.

### Bad
```yaml
# app.config.yaml from scaffold
application:
  id: my-app    # placeholder
actions:
  generic:      # sample action
    function: actions/generic/index.js
```

### Good
```yaml
application:
  id: acme-product-sync
actions:
  sync-products:
    function: actions/sync-products/index.js
# generic action removed
```

---

## Parameterize app.config.yaml inputs via env vars; mirror them in env.dist [warn] [config, env]

Hard-coded values in `app.config.yaml` (URLs, region codes, IMS org IDs) make the app non-portable across environments. Use `${ENV_VAR}` interpolation and document every required var in `env.dist` so a new dev or CI environment knows what to set.

### Bad
```yaml
inputs:
  COMMERCE_URL: https://prod.example.com
  IMS_ORG_ID: ABC123@AdobeOrg
```

### Good
```yaml
inputs:
  COMMERCE_URL: ${COMMERCE_URL}
  IMS_ORG_ID: ${IMS_ORG_ID}
# env.dist
# COMMERCE_URL=https://your-store.example.com
# IMS_ORG_ID=YOUR_ORG@AdobeOrg
```
