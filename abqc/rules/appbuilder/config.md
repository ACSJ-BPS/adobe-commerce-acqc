# App Builder — Config

> Part of [App Builder](./README.md).

## Use $include directive for large app.config.yaml files [info] [config, maintainability]

Once `app.config.yaml` exceeds ~200 lines it becomes impossible to review in PR. Use `$include` to factor out the runtime manifest and per-extension configs into separate files. Note that paths inside an included file are resolved relative to that file (except JS hooks, which always resolve relative to root).

### Check
```yaml
type: yaml-path
condition: include-large
files: ["**/app.config.yaml"]
maxLines: 120
```

### Bad
```yaml
# app.config.yaml — 800 lines mixing manifest, hooks, web, extensions
runtimeManifest:
  packages:
    # ... 600 lines of action defs ...
```

### Good
```yaml
# app.config.yaml
runtimeManifest:
  $include: ./runtime-manifest.yaml
extensions:
  dx/excshell/1:
    $include: ./excshell-config.yaml
```

---

## Action file paths must be relative to the config file [error] [config, build]

When `app.config.yaml` references `function: actions/foo/index.js`, that path is resolved relative to the config file's directory. Including a config from a subfolder breaks paths unless they're relative to the included file. Mismatched paths deploy but never resolve at runtime.

### Check
```yaml
type: yaml-path
condition: relative-path
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

### Bad
```yaml
# extensions/orders.yaml — wrong: path is relative to root, not to this file
runtimeManifest:
  packages:
    orders:
      actions:
        process:
          function: extensions/orders/actions/process/index.js
```

### Good
```yaml
# extensions/orders.yaml — correct: path relative to this file's location
runtimeManifest:
  packages:
    orders:
      actions:
        process:
          function: actions/process/index.js
```
