# @abqc/aio-plugin

aio CLI plugin for `appbuilder-quality-check` — **v0.2 placeholder**.

When implemented, this plugin will register `aio app review` as a wrapper over the CLI, with awareness of:

- The current aio workspace (`aio app use --workspace=Stage`).
- The `.aio` config directory for project, IMS context, and runtime URL.
- A `--watch` mode for re-running on file save.

For v0.1, use the CLI directly:

```bash
node /path/to/appbuilder_quality_check/packages/cli/dist/bin.js review .
```
