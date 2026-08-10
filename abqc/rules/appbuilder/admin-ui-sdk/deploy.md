# Admin UI SDK — Deployment

> Part of [Admin UI SDK](./README.md).

## App must be PUBLISHED in a Production workspace for ACCS to render extensions [error] [deploy, env]
Adobe Commerce Cloud Service (ACCS) only loads Admin UI SDK extensions from apps that are in `PUBLISHED` state in a Production workspace. Apps stuck in `DRAFT` or only deployed to a Stage workspace will deploy without errors but produce no visible menus, columns, or mass actions in the ACCS admin.

### Bad
```bash
aio app deploy            # deploys to default workspace, status remains DRAFT
# user opens ACCS admin, sees nothing, no error
```

### Good
```bash
# explicit Production workspace, then publish
aio app use --workspace Production
aio app deploy
aio app publish           # transitions to PUBLISHED state
# associate the PUBLISHED app with the Commerce store in App Management
```

## Pin @adobe/uix-core and @adobe/uix-guest versions to the App Review Checklist version [warn] [deps, compatibility]
The UIX SDK packages move quickly and the Admin UI SDK App Review Checklist specifies the supported `@adobe/uix-core` and `@adobe/uix-guest` versions (currently 0.8.3). Newer versions may work in dev but cause subtle compatibility breaks against the deployed Admin UI SDK in Commerce, especially around `attach()` shared-context shape.

### Bad
```json
{ "dependencies": {
    "@adobe/uix-core": "^1.0.0",
    "@adobe/uix-guest": "latest"
} }
```

### Good
```json
{ "dependencies": {
    "@adobe/uix-core": "0.8.3",
    "@adobe/uix-guest": "0.8.3"
} }
```

## Declare productDependencies with explicit Commerce min version (2.4.5+) [error] [deps, compatibility]
Admin UI SDK requires Commerce 2.4.5+ for the IMS module to be installable, with first-class metapackage support starting in 2.4.7. Without explicit `productDependencies`, the app will install on older Commerce versions and fail at runtime with no menu visible.

### Bad
```yaml
# no productDependencies declared
extensions:
  - $include: src/commerce-backend-ui-1/ext.config.yaml
```

### Good
```yaml
extensions:
  - $include: src/commerce-backend-ui-1/ext.config.yaml

productDependencies:
  - code: COMMC
    minVersion: 2.4.5
```
