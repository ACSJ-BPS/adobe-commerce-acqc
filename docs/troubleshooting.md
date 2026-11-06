# Troubleshooting

Find the message or symptom, then apply the fix. Known product gaps are listed in [Commands: Known limitations](commands.md#known-limitations).

## Install and setup

| Message or symptom | Cause and fix |
|---|---|
| `composer install` stops on the PHP version or a missing extension | ACQC needs PHP 8.2+. Install the PHP extension Composer names, then run `composer install` again. |
| `Class "..." not found` | The autoloader is out of date. Run `composer dump-autoload`. |
| `vendor/bin/phpcs -i` does not list `Magento2` | The `dealerdirect/phpcodesniffer-composer-installer` plugin registers the standards during `composer install`. Run `composer install` again and allow the plugin if Composer asks. |
| `Allowed memory size ... exhausted` | Large codebase. Raise the limit for one run: `php -d memory_limit=-1 bin/acqc report app/code`. |
| `PDF generation requires TCPDF. Install it with: composer require tecnickcom/tcpdf` | PDF is optional. Run that command in the ACQC folder, or use `--format=html`. |
| PHP_CodeSniffer, PHPMD or PHPStan results are missing from `report` | `report` runs them only when `phpcs`, `phpmd` and `phpstan` are on `PATH`. PHP_CodeSniffer also skips projects under a `/var/` path, PHPMD adds nothing on PHP 8.4, and PHPStan adds nothing today. See [Getting started: PHP tools for report](getting-started.md#php-tools-for-report). |
| A `var/` folder with `cache/acqc` and `metrics` appears | ACQC writes its cache in the current folder. Adobe Commerce projects already ignore `var/` in git. |

## audit

| Message or symptom | Cause and fix |
|---|---|
| Team rules or suppressions are not applied | `audit` reads `.acqc/` only from the path you pass. Run `acqc audit .` from the project root, or `acqc audit . --files=...`, not `acqc audit app/code`. See [Configuration](configuration.md#where-settings-live). |
| A rule you suppressed or unsuppressed still shows (or is still missing) | `audit` and `rules:list` cache the rule set for up to 1 hour and the cache does not see `project.yaml` changes. Clear it: `rm -rf "$(php -r 'echo sys_get_temp_dir();')/acqc_cache"`. See [Rules: Suppress a rule](rules.md#suppress-a-rule). |
| `Profile 'x' not found. Available built-in profiles: ...` | Use a listed name, or add `.acqc/profiles/x.yaml` next to the project's `.acqc/project.yaml`. See [Configuration: Profiles](configuration.md#profiles). |
| `Warning: 'thresholds' ... is ignored; use 'scoring.readiness' in .acqc/project.yaml.` | Move the values to `scoring.readiness`. A `thresholds` key in `.acqc/project.yaml` is ignored without this message. See [Project config](project-config.md#release-readiness). |
| Exit `2` with `git diff failed for "<ref>"` | The ref is not in the clone. Fetch the base branch or use full history (CI: `fetch-depth: 0`). See [CI/CD](ci-cd.md). |
| Exit `2`: App Builder files changed but Node.js (`node`) was not found, or abqc is not built | Install Node.js 20.19+ and run `npm install` in the ACQC folder. See [App Builder](app-builder.md). |
| A full audit of an App Builder project shows no App Builder findings | Node.js or abqc is missing. A full audit skips abqc silently. Check with `node abqc/packages/cli/dist/bin.js --version` in the ACQC folder. |
| The pre-commit profile misses a rule that `developer` reports | The `pre-commit` profile runs the built-in analyzers only, without YAML rules. See [Pre-commit gate: YAML rules in the hook](pre-commit.md#yaml-rules-in-the-hook). |

## Pre-commit hook

See [Pre-commit gate: Troubleshooting](pre-commit.md#troubleshooting) for `ACQC WARNING: ...` messages and hooks that stop running.

## report

| Message or symptom | Cause and fix |
|---|---|
| `Call to a member function getType() on int` with `--type=security --format=json` | Known issue. Use `--format=html` or `--format=excel` for the security report. |
| `Unsupported report type` for `comprehensive_magento_audit` | This report type supports only `--format=excel`. See [Reports](reports.md). |
| `report` exits `0` although it found critical issues | `report` is not a gate. Use `acqc audit` in CI. |

## Upgrade analysis

| Message or symptom | Cause and fix |
|---|---|
| `Could not detect current Magento version. Please specify with --current-version` | Run on the Adobe Commerce root (with `composer.json`), or pass `--current-version=2.4.6`. |
| `detect-version`: `Could not detect Magento version. Please ensure you are in a Magento project directory.` | Point it at the Adobe Commerce root. |
| `Network unavailable — continuing with cached version registry.` | Shown with `--simulate` when ACQC cannot reach Packagist. Information only: it uses the version data in `config/versions/adobe-commerce/`. |

See [Upgrade analysis](upgrade.md).

## doctor

| Check | Cause and fix |
|---|---|
| `CI integration`: `No CI file references acqc` | `doctor` looks for the text `acqc` in `.github/workflows/*`, `.gitlab-ci.yml` and `bitbucket-pipelines.yml`. `acqc init --ci=gitlab` and `--ci=bitbucket` write `.gitlab-ci-acqc.yml` and `bitbucket-pipelines-acqc.yml`, which `doctor` does not read. Add the ACQC job to your main CI file. See [CI/CD](ci-cd.md). |
| `Suppression debt`: `N expired suppression(s): ...` | A suppression's `review_by` date has passed. Fix the code and run `acqc rules:unsuppress <RULE_ID>`, or suppress it again with a new `--review-by=YYYY-MM-DD`. |

`doctor` checks the current folder, so run it from the project root.
