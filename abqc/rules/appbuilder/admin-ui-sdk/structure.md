# Admin UI SDK — Project Structure

> Part of [Admin UI SDK](./README.md).

## Use the extensions pattern in app.config.yaml — not actions/applications [error] [config, structure]
Admin UI SDK apps must declare `extensions:` at the top level of `app.config.yaml`, with `$include` pointing at `src/commerce-backend-ui-1/ext.config.yaml`. The legacy `actions:` / `application:` pattern at the top level is the most common review-failure cause and produces apps that simply don't show up in Commerce admin.

### Bad
```yaml
# app.config.yaml — legacy pattern, app won't register
application:
  actions:
    function: actions/registration/index.js
```

### Good
```yaml
# app.config.yaml
extensions:
  - $include: src/commerce-backend-ui-1/ext.config.yaml

productDependencies:
  - code: COMMC
    minVersion: 2.4.5
```

## Place all Admin UI SDK code under src/commerce-backend-ui-1/ — not at repo root [error] [structure, layout]
The Admin UI SDK extension layout requires `actions/` and `web-src/` to live under `src/commerce-backend-ui-1/`, not at the repo root. Putting them at the root means the `$include` in `app.config.yaml` won't resolve and the registration action won't deploy.

### Bad
```text
my-app/
├── app.config.yaml
├── actions/registration/index.js   # wrong location
└── web-src/                          # wrong location
```

### Good
```text
my-app/
├── app.config.yaml
├── install.yaml
└── src/commerce-backend-ui-1/
    ├── ext.config.yaml
    ├── actions/registration/index.js
    └── web-src/src/...
```

## Application block belongs in ext.config.yaml, not app.config.yaml [error] [config, structure]
The `application:` block (with `web.root`, `web.dist`, `entryFile`) lives in `src/commerce-backend-ui-1/ext.config.yaml`, alongside the `actions:` block for the registration runtime. Putting it in `app.config.yaml` works for plain App Builder apps but breaks the Admin UI SDK extension lifecycle.

### Bad
```yaml
# app.config.yaml
application:
  web:
    root: web-src
    entryFile: src/index.jsx
```

### Good
```yaml
# src/commerce-backend-ui-1/ext.config.yaml
application:
  web:
    root: web-src
    dist: build
    entryFile: src/index.jsx
actions:
  registration:
    function: actions/registration/index.js
    web: 'yes'
    runtime: nodejs:22
    annotations:
      require-adobe-auth: true
```
