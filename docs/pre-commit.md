# Pre-commit gate

The ACQC git hook audits the **staged** files on every `git commit`. It blocks the commit when they contain `blocker`, `critical` or `error` issues. Warnings and info are counted but never block.

## Requirements

| Need | Notes |
|---|---|
| PHP 8.2 or later | On each developer machine |
| ACQC checkout | `composer install` done. See [Getting started](getting-started.md). |
| Target project | Must be a git repository |
| App Builder / Node.js projects | Node.js on `PATH` and `npm install` run in the ACQC folder (this builds `abqc/`) |

## Install

Run this once in each clone. Git does not version hooks, so every developer and every new clone needs it.

```bash
cd /path/to/your-project
php /path/to/acqc/bin/acqc hooks:install
php /path/to/acqc/bin/acqc hooks:status      # expect: installed and healthy
```

`hooks:install` writes `.git/hooks/pre-commit`. It respects `core.hooksPath` and git worktrees. Running it again updates the hook in place, for example after you move ACQC.

## What a blocked commit looks like

```text
$ git commit -m "Add importer"
ACQC: 1 file(s) checked (staged), fail on: blocker, critical, error
  app/code/Vendor/Mod/Model/Importer.php:8  ERROR  [object_manager_usage] Direct ObjectManager usage is discouraged. Use dependency injection instead.
BLOCKED: 1 error (2 non-blocking issue(s) not shown).
Fix the issues above and re-stage, or bypass once with: git commit --no-verify
```

Fix the code, `git add` it again and commit. A clean commit prints `PASSED: no blocking issues.`

## What is checked

| Aspect | Behaviour |
|---|---|
| Files | Only files staged for this commit (added, copied, modified, renamed): `.php`, `.phtml`, `.xml`, `.graphqls`. In an App Builder / Node.js project, changed JS/TS and config files are also checked by abqc. |
| Content | The staged version (git index), not your working copy. Partial staging with `git add -p` works. |
| Existing code | Files you did not touch are not checked. Every blocking issue in a file you touched blocks, including old ones. |
| Checks | The `pre-commit` profile runs the built-in analyzers only. It applies no YAML rules (bundled, team or personal). See [YAML rules in the hook](#yaml-rules-in-the-hook). |
| Network | None. The check is fully local. |
| Files written | The audit creates `var/cache/acqc/` and `var/metrics/` in the project root. Adobe Commerce projects already ignore `var/` in git. |

### YAML rules in the hook

The built-in `pre-commit` profile does not apply the YAML rules described in [Rules](rules.md). For example, it reports the ObjectManager call above as `object_manager_usage` (error) but not as `AC_DI_001` (critical). To include the YAML rules, install the hook with another profile:

```bash
php /path/to/acqc/bin/acqc hooks:install --profile=developer
```

The `developer` profile blocks on `error`, `critical` and `blocker` and applies the YAML rules for the developer role. You can also switch for one commit with `ACQC_PROFILE=developer git commit ...`.

## Share the gate settings with the team

Copy [examples/pre-commit/.acqc/profiles/pre-commit.yaml](../examples/pre-commit/.acqc/profiles/pre-commit.yaml) to `.acqc/profiles/pre-commit.yaml` in your project and commit it. It replaces the built-in `pre-commit` profile for everyone. Change `fail_on` there, for example to `[blocker, critical]` while you adopt ACQC on legacy code.

Profile lookup order: `~/.acqc/profiles/` -> `<project>/.acqc/profiles/` -> built-in. See [Configuration](configuration.md#profiles).

## Per-commit controls

| Variable | Effect |
|---|---|
| `ACQC_SKIP=1` | Skip the ACQC check for this commit. Other hooks still run. |
| `ACQC_FAIL_ON=critical` | Use these blocking severities for this commit (comma-separated) |
| `ACQC_PROFILE=developer` | Use a different profile for this commit |
| `ACQC_STRICT=1` | Also block when ACQC itself cannot run. Default: warn and allow. |
| `ACQC_BIN=/path/to/bin/acqc` | Use a different ACQC binary |
| `ACQC_PHP=/path/to/php` | Use a different PHP binary |

`git commit --no-verify` skips **all** hooks, including ACQC.

Example: `ACQC_FAIL_ON=critical git commit -m "..."`

## How the hook finds ACQC and PHP

| Item | Lookup order |
|---|---|
| ACQC | `$ACQC_BIN` -> `<project>/vendor/bin/acqc` -> the path recorded by `hooks:install` |
| PHP | `$ACQC_PHP` -> the PHP recorded by `hooks:install` -> `php` on `PATH` |

When ACQC or PHP is not found, or ACQC exits with status 2 (tool error), the hook prints `ACQC WARNING: ...` and **allows** the commit. To block in these cases:

- set `ACQC_STRICT=1` for one commit, or
- install with `hooks:install --strict` so the hook fails closed by default. `ACQC_STRICT=0 git commit ...` still overrides it once.

## Existing hooks

- An existing non-ACQC `pre-commit` hook is kept as `pre-commit.local` and runs first. If it fails, the commit is blocked.
- If a non-ACQC `pre-commit` hook and `pre-commit.local` both exist, `hooks:install` stops. `--force` overwrites the `pre-commit` hook.
- `hooks:uninstall` removes only the ACQC hook and restores `pre-commit.local`.

## Hook commands

| Command | Purpose |
|---|---|
| `acqc hooks:install [path] [--profile=pre-commit] [--acqc-bin=...] [--php=...] [--force] [--strict]` | Install or update the hook |
| `acqc hooks:status [path]` | Show hook details. Exit 0 only when installed and healthy. |
| `acqc hooks:uninstall [path]` | Remove the ACQC hook and restore a preserved hook |

## Run the same check by hand

```bash
acqc audit . --staged --profile=pre-commit          # what the hook runs
acqc audit . --diff-from=origin/main                # everything changed on your branch
acqc audit . --files=app/code/Vendor/Mod/Model/A.php,app/code/Vendor/Mod/etc/di.xml
```

## Troubleshooting

| Message or symptom | Cause and fix |
|---|---|
| `ACQC WARNING: acqc not found at '...'` | ACQC moved. Run `hooks:install` again or set `ACQC_BIN`. |
| `ACQC WARNING: PHP not found (set ACQC_PHP)` | Set `ACQC_PHP` or run `hooks:install --php=...`. |
| `ACQC WARNING: acqc exited with status 2` | ACQC hit a tool error and the commit was allowed. Run `acqc audit . --staged --profile=pre-commit` to see the error. |
| Error says Node.js (`node`) was not found, or abqc is not built | App Builder files changed but abqc cannot run. Install Node.js, or run `npm install` in the ACQC folder. |
| `hooks:status` says not installed | `core.hooksPath` changed (for example by Husky). Run `hooks:install` again. |
| Your old hook stopped running | It is kept as `pre-commit.local` and runs first. Check its output. |

More in [Troubleshooting](troubleshooting.md).

## Limitations

- A local hook can be bypassed with `--no-verify`. Enforce the same check in CI. See [CI/CD](ci-cd.md).
- There is no baseline. Every blocking issue in a touched file blocks, including old issues in that file.
