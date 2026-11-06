# App Builder and Node.js

ACQC checks Adobe App Builder and Node.js / TypeScript code with **abqc**, a Node.js engine bundled in [abqc/](../abqc). abqc has 387 rules in [abqc/rules/](../abqc/rules), grouped by domain: `a11y`, `appbuilder` (including API Mesh and Admin UI SDK), `deployment`, `documentation`, `imports`, `node`, `process`, `react`, `runtime`, `security`, `stylistic`, `testing`, `typescript`.

ACQC runs abqc in mechanical mode only: declarative checks plus ESLint. It does not use abqc's AI layer.

## Setup

| Need | Notes |
|---|---|
| Node.js 20.19+ and npm | On `PATH` for the user that runs ACQC |
| Build abqc | Run `npm install` in the ACQC folder. Its `postinstall` step builds abqc into `abqc/packages/*/dist/`. |

```bash
cd /path/to/acqc
npm install
node abqc/packages/cli/dist/bin.js --version     # prints: abqc 0.1.0
```

Do not set `ABQC_PROVIDER` in the environment. abqc has an optional AI layer that ACQC does not use, and that variable turns it on.

## How ACQC detects a project

| Platform | Detected when |
|---|---|
| App Builder | `app.config.yaml` exists **and** `package.json` depends on `@adobe/aio-sdk`, `@adobe/aio-lib-*` or `@adobe/generator-app*` |
| Node.js | `package.json` exists |

The definitions live in [config/platforms/](../config/platforms).

## Lint a project: `appbuilder:lint`

```bash
acqc appbuilder:lint /path/to/app                       # summary by severity and category
acqc appbuilder:lint /path/to/app --format=json         # every finding
acqc appbuilder:lint /path/to/app --min-confidence=high # drop low-confidence findings
```

| Option | Values | Notes |
|---|---|---|
| `[path]` | folder | Default: the current folder |
| `-f`, `--format` | `console` (default), `json` | Console shows only counts by severity and category. Use `json` to see each finding. |
| `--min-confidence` | `high`, `medium`, `low` | Drops findings below this level. Pattern-only rules (for example object injection, non-literal `require`/`fs`/RegExp) are marked `low` because a person must confirm the risk. |

### Exit codes

| Code | When |
|---|---|
| `0` | No `error` findings. Also `0` when Node.js is missing or abqc is not built: the command prints `No abqc findings (or abqc is not built ...)`. |
| `1` | At least one `error` finding, or the path is not a Node.js / App Builder project |

### JSON output

Top-level keys: `schema_version`, `tool`, `analyzer`, `platforms`, `issues_found`, `by_severity`, `by_category`, `issues`.

Each issue has `rule_id` (prefixed `ABQC:`), `severity`, `file`, `line`, `message`, `recommendation`, `category`, `source`, `confidence` and `roles`.

## App Builder in `audit` and the pre-commit hook

`acqc audit` adds abqc findings to the same score and gate as the PHP checks.

| Audit mode | When abqc runs | If Node.js or abqc is missing |
|---|---|---|
| Full: `acqc audit <path>` | The path is detected as App Builder or Node.js | abqc is skipped silently. The audit can pass with 0 findings. |
| Scoped: `--staged`, `--diff-from`, `--files` | A changed file sits under an App Builder root: a folder with `package.json` and `app.config.yaml` or `ext.config.yaml`. Repos that mix Adobe Commerce and App Builder in sub-folders work. | The audit exits `2` (tool error). The pre-commit hook warns and allows the commit unless `ACQC_STRICT=1`. |

A scoped audit also:

- reports findings only for the changed files;
- uses the staged content with `--staged`;
- flags hard-coded credentials in changed App Builder YAML (for example `app.config.yaml`, `manifest.yml`) as `ACQC:appbuilder/yaml-hardcoded-secret` (critical). It detects AWS access key IDs, private keys, GitHub tokens, Slack tokens and Google API keys. `.env` files are not checked.

See [Pre-commit gate](pre-commit.md) and [CI/CD](ci-cd.md).

## App Builder in `report`

`acqc report <path>` on an App Builder or Node.js project runs abqc and adds its findings to the
report, without the low-confidence ones. When the project is not also an Adobe Commerce project,
`report` skips PHP and XML files. See [Reports](reports.md).

## What abqc skips

| Item | Behaviour |
|---|---|
| Folders | `node_modules`, `dist`, `build`, `coverage`, `.turbo`, `.next`, `out` and dot-folders other than `.github` |
| Large projects | A full walk stops at 2,000 files. Scoped audits pass the changed files directly, so the limit does not apply to them. |

## Limitations

- A full `audit` and `appbuilder:lint` do not fail when Node.js or abqc is missing. In CI, run `npm install` in the ACQC folder and use a scoped audit (`--diff-from`) so a missing engine fails the job.
- In a full audit, `files_analyzed` counts only PHP-side files. abqc findings are still counted and scored.

More about abqc itself: [abqc/README.md](../abqc/README.md).
