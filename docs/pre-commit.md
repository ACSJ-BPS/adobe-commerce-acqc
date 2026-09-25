# ACQC Pre-commit Gate — Onboarding

Blocks a `git commit` when the **staged** changes contain `blocker`, `critical` or `error` issues.

## 1. Prerequisites

- PHP >= 8.1 on the developer machine
- A local ACQC checkout with `composer install` done
- App Builder / Node.js repos: Node.js on `PATH` and `npm install` run in the ACQC checkout (builds `abqc/`)
- The target project is a git repository

## 2. Install (per clone, per developer)

```bash
cd /path/to/target-repo
php /path/to/acqc/bin/acqc hooks:install
php /path/to/acqc/bin/acqc hooks:status   # expect: installed and healthy
```

Git hooks are not versioned, so every developer (and every new clone) runs this once.

## 3. Share the team configuration (optional, recommended)

Copy `examples/pre-commit/.acqc/profiles/pre-commit.yaml` into the target repo at
`.acqc/profiles/pre-commit.yaml` and commit it. Adjust `fail_on` there.

## 4. Daily use

| Situation                         | What to do                                    |
| --------------------------------- | --------------------------------------------- |
| Commit blocked                    | Fix the listed lines, `git add`, commit again |
| Emergency, skip ACQC only         | `ACQC_SKIP=1 git commit ...`                  |
| Skip all hooks                    | `git commit --no-verify`                      |
| Block only on critical this time  | `ACQC_FAIL_ON=critical git commit ...`        |
| Preview before committing         | `acqc audit --staged --profile=pre-commit`    |

## 5. Troubleshooting

| Message                               | Cause / fix                                                               |
| ------------------------------------- | ------------------------------------------------------------------------- |
| `ACQC WARNING: acqc not found at ...` | ACQC moved — re-run `hooks:install` or set `ACQC_BIN`                     |
| `ACQC WARNING: PHP not found`         | Set `ACQC_PHP` or re-run `hooks:install --php=...`                        |
| `ACQC WARNING: acqc exited with status 2` | Tool error; the commit was allowed. Run the audit manually to see details |
| `... Node.js ("node") was not found` / `abqc is not built` | App Builder files changed but abqc cannot run — install Node.js / run `npm install` in ACQC |
| `hooks:status` reports not installed  | `core.hooksPath` changed (e.g. Husky) — re-run `hooks:install`            |
| Existing hook stopped running         | It is preserved as `pre-commit.local` and runs first — check its output   |

## 6. Uninstall

```bash
php /path/to/acqc/bin/acqc hooks:uninstall
```

## 7. Limitations

- Local hooks can be bypassed; enforce in CI with
  `acqc audit . --diff-from=origin/main --fail-on=critical,error`.
- No baseline yet: every blocking issue in a touched file blocks, including pre-existing ones in that file.
