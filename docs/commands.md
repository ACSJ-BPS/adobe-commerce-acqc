# Commands

ACQC has one entry point, `bin/acqc`. This page explains which command to use and shows the common
options. For the full option list of any command, run:

```bash
acqc list               # all commands
acqc help <command>     # all options of one command, for example: acqc help audit
```

## Which command should I use?

| I want to | Command |
|---|---|
| Pass or fail a project, branch or commit (quality gate) | `acqc audit` |
| Produce a report to share (HTML, Excel, PDF, JSON, SARIF, JUnit) | `acqc report` |
| Block bad code at `git commit` | `acqc hooks:install` (see [Pre-commit gate](pre-commit.md)) |
| Check an Adobe Commerce upgrade | `acqc upgrade:analyze` (see [Upgrade analysis](upgrade.md)) |
| Lint an App Builder / Node.js project | `acqc appbuilder:lint` (see [App Builder](app-builder.md)) |
| Check team-specific YAML policies | `acqc policy:check` |
| Look at the bundled rules | `acqc rules:list` (see [Rules](rules.md)) |
| Set up or check a project | `acqc init`, `acqc doctor` |

## Quality checks

ACQC has three analysis commands. They use different analyzer sets, so their results are not the same.

| Command | Runs | Typical use |
|---|---|---|
| `audit` | Built-in Adobe Commerce, security, performance and code-quality analyzers, plus the YAML rules for the selected profile, plus abqc for App Builder and Node.js projects | Quality gate in pre-commit and CI; score and grade |
| `report` | The analyzer orchestrator: Adobe Commerce, performance, frontend and PHP analyzers. PHP_CodeSniffer (Magento2 standard), PHPMD, PHPStan and PHPCPD only when they are on `PATH` (see [Getting started](getting-started.md#php-tools-for-report)). abqc for App Builder and Node.js projects | Detailed reports for people |
| `scan` and `analyze` | The analyzer registry (no YAML rules) | Quick console checks of a path or module |

### audit

```bash
acqc audit .                                              # whole project, developer profile
acqc audit app/code --profile=tech-lead                   # another profile
acqc audit . --diff-from=origin/main                      # only files changed on this branch
acqc audit . --files=app/code/Vendor/Module/Model/A.php   # only these files
acqc audit --staged --profile=pre-commit                  # what the pre-commit hook runs
acqc audit . --format=json > audit.json                   # machine output
acqc audit . --format=sarif > acqc.sarif                  # SARIF 2.1.0
acqc audit . --sarif-file=acqc.sarif                      # console output plus a SARIF file
acqc audit . --fail-on=critical,blocker                   # choose what fails the gate
```

| Option | Meaning |
|---|---|
| `[path]` | Project path. Default: the current directory. ACQC reads team rules and suppressions from `.acqc/` in this path, or in the git root with `--staged` and `--diff-from`. Run from the project root so they apply. Scoring comes from the nearest `.acqc/project.yaml` (see [Project config](project-config.md#where-acqc-looks-for-the-file)). |
| `-p, --profile` | `developer` (default), `tech-lead`, `architect`, `executive`, `ci`, `pre-commit`. See [Configuration](configuration.md#profiles). |
| `-f, --format` | `console`, `compact`, `json`, `html`, `sarif`. Default comes from the profile. |
| `--sarif-file` | Also write SARIF to a file, whatever the `--format`. |
| `--staged` / `--diff-from` / `--files` | Limit the audit to staged content, to files changed against a ref or range, or to a file list. Use only one of them. |
| `--exclude` | Path to exclude (repeatable). |
| `--fail-on` | Severities that fail the gate, as an exact list, not a threshold. Overrides the profile `fail_on`. |
| `-c, --config` | JSON config file. Without it, ACQC looks for `acqc.json` or `.acqc.json` in the current directory. |

Exit codes:

| Code | Meaning |
|---|---|
| `0` | Gate passed, or nothing to check |
| `1` | Gate failed (an issue with a `fail_on` severity), or invalid usage |
| `2` | ACQC could not run (for example a git error, or abqc missing for changed App Builder files) |

### report

```bash
acqc report .                                    # HTML, written to ./reports/
acqc report app/code --format=excel              # Excel workbook
acqc report . --format=json -o build/acqc        # JSON into build/acqc/
acqc report . --exclude-categories=seo,accessibility
```

See [Reports](reports.md) for formats, file names and filters.

### scan and analyze

```bash
acqc scan app/code                               # console summary
acqc scan app/code --format=json -o scan.json
acqc analyze app/code                            # table output
acqc analyze Vendor/Module --module              # one module by namespace (run from the project root)
acqc analyze --list-analyzers                    # available analyzers
```

### watch

```bash
acqc watch app/code            # re-run analysis when files change; stop with Ctrl+C
```

### policy:check

Checks your own YAML policies. It loads every `*.yaml` file in `.acqc/policies/` in the project
and in `~/.acqc/policies/`, plus any file passed with `--policy`.

```bash
acqc policy:check app/code
acqc policy:check app/code --policy=examples/policies/magento-team-standards.yaml
acqc policy:check app/code --format=json --fail-on=error
acqc policy:check --list
```

Policy file format (see `examples/policies/magento-team-standards.yaml` for a full example):

```yaml
rules:
  no-object-manager:
    name: No direct ObjectManager usage
    type: pattern            # pattern | file_exists | file_missing | metric
    severity: error          # minor | info | warning | error | critical | blocker
    category: magento
    conditions:
      pattern: 'ObjectManager::getInstance\(\)'
      files: ['*.php']
    message: "Direct ObjectManager usage at line {line}"
    fix: "Use constructor injection instead"
```

`--fail-on` (default `error`) is a threshold here: the command exits `1` when a violation of that
severity or higher is found.

## Setup and health

| Command | What it does |
|---|---|
| `acqc init [--ci=github\|gitlab\|bitbucket] [--force]` | Writes `.acqc/project.yaml`. With `--ci`, also writes a starter CI file. |
| `acqc doctor [--json]` | Checks the project setup and prints a score and next steps. |
| `acqc validate` | Checks that the ACQC analyzers and rules load correctly (useful after an ACQC update). |
| `acqc hooks:install` / `hooks:status` / `hooks:uninstall` | Manage the git pre-commit hook. See [Pre-commit gate](pre-commit.md). |

## Rules

| Command | What it does |
|---|---|
| `acqc rules:list [--profile=…] [--category=…] [--tier=…] [--severity=…] [--active-only] [--format=table\|json\|csv]` | Lists the YAML rules that `audit` loads for a profile (default `developer`), including team and personal rules, for the project in the current directory. |
| `acqc rules:validate [path]` | Validates rule YAML files (default: the bundled `config/rules/` folder). |
| `acqc rules:suppress <rule-id> --reason="…" [--review-by=YYYY-MM-DD]` | Suppresses a rule for the project in the current directory, in `.acqc/project.yaml`. |
| `acqc rules:unsuppress <rule-id>` | Removes a suppression. |
| `acqc rules:new` | Creates a rule scaffold inside the ACQC `config/rules/` folder. Asks for missing values, or pass `--id`, `--category`, `--tier`, `--check-type`. |
| `acqc rules:sync [--bundle=…] [--dry-run]` | Lists the rules in the bundles named in `.acqc/project.yaml` (`rule_bundles`). It does not download anything. |

See [Rules](rules.md).

## Upgrade and versions

| Command | What it does |
|---|---|
| `acqc upgrade:analyze [path] --target-version=2.4.8` | Upgrade compatibility analysis with effort estimate. Alias: `upgrade-readiness`. |
| `acqc detect-version [path]` | Detects the Adobe Commerce and PHP versions of a project. |
| `acqc version:list` | Known Adobe Commerce versions with end-of-life status. |
| `acqc version:show <version>` | Details of one version. |
| `acqc version:diff <from> <to>` | Breaking changes between two versions. |
| `acqc version:path <from> <to>` | Recommended upgrade path with risk per step. |
| `acqc version:advisories <version>` | Security advisories for a version. |
| `acqc version:eol-report` | End-of-life timeline. |
| `acqc version:sync [--dry-run] [--source=…]` | Refreshes the version registry. The default source, `repo.magento.com`, needs credentials; `--source=packagist` does not. |
| `acqc version:download <version> [--edition=ce\|ee]` | Downloads a vanilla Adobe Commerce instance for core file comparison. Needs `repo.magento.com` credentials. |
| `acqc auto-fix [path] --dry-run` | Applies code fixers. `auto-fix -l` lists them (today: `zend-to-laminas`). Always run `--dry-run` first. |

See [Upgrade analysis](upgrade.md).

## Other commands

| Command | What it does |
|---|---|
| `acqc tech-spec <role>` | Prints the technical standards for a role: `junior-developer`, `senior-developer` or `architect`. |
| `acqc backup <create\|restore\|list\|delete\|cleanup> [path]` | Copies code paths to `./backups/` (change with `--backup-dir`) and restores them. |
| `acqc config` | Shows the ACQC configuration from `config/default.yaml`. |

## Known limitations

These commands or options exist in `acqc list` but do not work as their help text suggests.
Do not rely on them until they are fixed.

| Command or option | Current behaviour |
|---|---|
| `aggregate` | Every project shows `Analysis failed (exit 1)`, because it passes options that `audit` and `analyze` do not accept. |
| `checklist` | `senior-developer` and `architect` stop with a PHP error; `junior-developer` reports that no analyzers are available. |
| `baseline compare`, `scan --baseline`, `scan --drift-only` | The comparison is not implemented and shows no differences. Use `audit --diff-from` to focus on changed files. |
| `scan --fix`, `analyze --fix` | No fixes are applied. Use `auto-fix`. |
| `analyze --all-modules` | Reports `No files found to analyze`. Pass the folder instead: `acqc analyze app/code`. |
| `history` | Reads `~/.acqc/history.sqlite`, which only `aggregate --save-history` writes, so it is normally empty. |
| `export sarif\|junit\|pdf` | Not supported; the command points you to `report`. |
| `report --profile` | Ignored with a warning. Configure `report` through `.acqc/project.yaml`. |
| `report --diff`, `analyze --diff` | Ignored; all files are analyzed. Use `audit --diff-from` for changed files. |
| `report --type=security --format=json` | Fails with a PHP error. `--type=security` works with `html` and `excel`. |
| `report --format=json` | `-t summary` (the default) has only counts by severity and category, no list of findings. With `-t detailed` the list entries show `unknown` and `No message provided`. For a list of findings in JSON, use `audit --format=json`. |
| `rules:suppress`, `rules:unsuppress` | `audit` and `rules:list` cache the rule set for up to one hour and do not see the change until then. See [Rules](rules.md#suppress-a-rule) for the workaround. |
| `audit --profile=pre-commit` (the hook default) | Runs the built-in analyzers only. No YAML rule has the `pre-commit` role. See [Pre-commit gate](pre-commit.md#yaml-rules-in-the-hook). |
| `audit` on an App Builder / Node.js project without Node.js | A full audit skips abqc without a message and can pass with 0 App Builder findings. A scoped audit (`--staged`, `--diff-from`, `--files`) that touches App Builder files exits `2`. See [App Builder](app-builder.md). |
| `init --ci=…` | The starter CI file runs `php bin/acqc scan`, which only works when ACQC is inside the project, and `scan` does not use YAML rules. See [CI/CD](ci-cd.md#acqc-init---ci). |
| `doctor` CI check | Does not read `.gitlab-ci-acqc.yml` or `bitbucket-pipelines-acqc.yml`, the files that `init --ci=gitlab` and `init --ci=bitbucket` write. |
| `report` with PHP_CodeSniffer, PHPMD, PHPStan | PHP_CodeSniffer skips files whose absolute path contains a `var`, `vendor`, `generated`, `node_modules` or `pub/static` folder (for example every file under `/var/www/`). PHPMD output cannot be read on PHP 8.4. PHPStan stops because ACQC passes `--parallel`. In each case `report` adds no findings from that tool. |
