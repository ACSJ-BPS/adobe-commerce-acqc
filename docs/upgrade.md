# Upgrade analysis

`acqc upgrade:analyze` (alias `upgrade-readiness`) checks an Adobe Commerce project against a target
Adobe Commerce and PHP version. It lists the code that needs work and estimates the effort. It is
not a gate: it exits `0` when the analysis completes, whatever it finds.

## Run it

Run it on the project root (the folder with `composer.json`):

```bash
acqc upgrade:analyze /path/to/project                              # console output
acqc upgrade:analyze /path/to/project --target-version=2.4.8-p2 --target-php=8.3
acqc upgrade:analyze /path/to/project --no-db-scan                 # skip the DB schema analysis (faster)
acqc upgrade:analyze /path/to/project --frontend-scan              # add template, RequireJS and LESS checks
```

| Option | Default | Notes |
|---|---|---|
| `--target-version` (or `--target`) | `2.4.8` | Patch versions work, for example `2.4.8-p2`. |
| `--target-php` | `8.4` | |
| `--current-version` | Detected | Read from `composer.json` (`magento/product-*`) or `vendor/magento/framework/composer.json`. If detection fails, the command stops and asks for this option. |
| `--current-php` | PHP running ACQC | ACQC cannot see the project's PHP version. Pass it when the project runs another PHP version. |
| `--no-db-scan` | DB scan on | Skips the database schema upgrade analysis. |
| `--frontend-scan` | Off | Adds template overrides, RequireJS configs and LESS variables. |
| `--composer-deep` | Off | Adds transitive dependencies, abandoned packages and security advisories. Slower; needs network access. |
| `--b2b`, `--cloud` | Detected | B2B is detected from `composer.json`, Adobe Commerce on cloud infrastructure from `.magento.app.yaml`. Use the options to force these checks. |
| `--min-priority` | All | `critical`, `high`, `medium` or `low`. Shortens the console issue list only; scores and counts still cover the full scan. |

## What you get

The console output has these sections:

| Section | Content |
|---|---|
| Versions | Current and target Adobe Commerce and PHP versions, and an end-of-life warning when the current version is past its end-of-life date |
| Compatibility | Issues in custom code for the target version |
| B2B compatibility, Cloud environment | Only when B2B or cloud infrastructure is detected or forced |
| Upgrade Risk Index | Score from 0 to 100 (higher is riskier), a grade, effort hours (engineering, QA, infrastructure) and a suggested timeline |
| Executive summary, issues by priority, effort estimation, risk assessment, timeline, roadmap, recommendations | Planning detail |

The effort hours and timeline are estimates. Review them before you share them.

## Save the results

```bash
acqc upgrade:analyze /path/to/project --format=json  -o upgrade.json
acqc upgrade:analyze /path/to/project --format=excel -o upgrade.xlsx
acqc upgrade:analyze /path/to/project --format=html --generate-report -o upgrade.html
```

- HTML needs `--generate-report` (`-r`). Without it, no HTML file is written.
- JSON top-level keys: `version_info`, `summary`, `issues`, `effort_estimation`,
  `upgrade_roadmap`, `database_migrations`.
- `--export=html,excel` also writes files, but it ignores `-o`: it writes `upgrade-report.html` and
  `upgrade-readiness-<timestamp>.xlsx` in the current directory.

## Compare targets and simulate

```bash
# One row per target: risk score, grade, effort hours, blockers, errors, warnings
acqc upgrade:analyze /path/to/project --no-db-scan --compare-versions=2.4.7,2.4.8

# No project needed: checks based on the version registry only
acqc upgrade:analyze --simulate --current-version=2.4.6 --target-version=2.4.8
```

In `--simulate` mode the database, frontend and Composer analyzers are skipped.

## Related commands

| Command | Use |
|---|---|
| `acqc detect-version /path/to/project` | Shows the detected Adobe Commerce version. Add `-c` for a compatibility check, `-m` for the compatibility matrix and `-f json` for JSON. The PHP version shown is the PHP running ACQC. |
| `acqc version:list`, `version:show <v>`, `version:eol-report` | Known versions and end-of-life dates. |
| `acqc version:diff <from> <to>`, `version:path <from> <to>` | Breaking changes between versions, and the suggested upgrade path. |
| `acqc version:advisories <v>` | Security advisories for a version. |
| `acqc version:sync --source=packagist` | Refreshes the version registry. The default source, repo.magento.com, needs credentials. |
| `acqc version:download <v> --edition=ce\|ee` | Downloads a vanilla Adobe Commerce copy. `upgrade:analyze` suggests it to enable a check for modified core vendor files. Needs repo.magento.com credentials. |
| `acqc auto-fix <path> --dry-run` | Previews automatic code fixes. `acqc auto-fix -l` lists the fixers (today only `zend-to-laminas`). |

`upgrade:analyze` uses the online version registry and falls back to the cached copy when the
network is not available.

repo.magento.com credentials come from `ACQC_MAGENTO_PUBLIC_KEY` and `ACQC_MAGENTO_PRIVATE_KEY`, or
from `~/.composer/auth.json`.

### auto-fix

```bash
acqc auto-fix app/code/Vendor/Module --dry-run       # preview
acqc auto-fix app/code/Vendor/Module                 # apply
acqc auto-fix app/code/Vendor/Module --fixer=zend-to-laminas
```

Before it changes files, `auto-fix` saves a zip of the path in `./backups/` in the current
directory. `--no-backup` skips that. Review the changes with `git diff` before you commit.
