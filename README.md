# ACQC: Adobe Commerce Quality Control

ACQC is a command-line tool that checks the code quality of Adobe Commerce projects. It runs static
analysis on PHP code, scores the result, and fails a commit or a pipeline when serious issues are
found. It also produces reports for people and helps you plan Adobe Commerce upgrades. App Builder
and Node.js code is checked by the bundled `abqc` engine.

Version: 3.1.0 (`acqc --version`).

## What it does

| Task | Command | Read more |
|---|---|---|
| Quality gate for a project, branch or commit: score, grade, exit code | `acqc audit` | [Commands](docs/commands.md#audit) |
| Block bad code at `git commit` | `acqc hooks:install` | [Pre-commit gate](docs/pre-commit.md) |
| Gate pull requests in GitHub, GitLab or Bitbucket, with SARIF output | `acqc audit --diff-from=…` | [CI/CD](docs/ci-cd.md) |
| Reports for people: HTML, Excel, JSON, SARIF, JUnit | `acqc report` | [Reports](docs/reports.md) |
| Adobe Commerce upgrade readiness and version data | `acqc upgrade:analyze`, `acqc version:*` | [Upgrade analysis](docs/upgrade.md) |
| Check App Builder and Node.js projects | `acqc appbuilder:lint` | [App Builder](docs/app-builder.md) |
| List, suppress and write YAML rules | `acqc rules:*` | [Rules](docs/rules.md) |

### What is checked

| Source | Used by | Notes |
|---|---|---|
| Built-in analyzers (Adobe Commerce, security, performance, code quality) | `audit`, `report`, `scan`, `analyze` | Written in PHP, part of ACQC. |
| Bundled YAML rules in `config/rules/` | `audit` | Rules for dependency injection, security, GraphQL, database schema, deprecations and more. `acqc rules:list` shows the rules for a profile. |
| Team rules in `<project>/.acqc/rules/team/` | `audit` | Your own YAML rules, committed with the project. See [Rules](docs/rules.md#write-a-team-rule). |
| PHP_CodeSniffer (`Magento2` standard), PHPMD, PHPStan, PHPCPD | `report` | Run only when the command is on your `PATH`. Today only PHP_CodeSniffer adds findings. See [Getting started](docs/getting-started.md#php-tools-for-report). |
| abqc (App Builder and Node.js rules) | `audit`, `report`, `appbuilder:lint` | Needs Node.js. Mechanical checks only. |

`audit` and `report` use different analyzer sets, so their results differ. Use `audit` as the gate
and `report` for detail. See [Commands](docs/commands.md#quality-checks).

## Requirements

| Need | Version | Notes |
|---|---|---|
| PHP | 8.2 or later | Composer lists any missing PHP extension during install. |
| Composer | 2.x | |
| Git | any recent | For `--staged`, `--diff-from` and the pre-commit hook. |
| Node.js and npm | 20.19 or later | Only for App Builder and Node.js projects. |

## Install

```bash
git clone https://github.com/OneAdobe/adobe-commerce-acqc.git acqc
cd acqc
composer install
php bin/acqc --version
vendor/bin/phpcs -i      # the list must contain Magento2
```

For App Builder checks, also run `npm install` in the same folder.

The examples use `acqc` as a short name. Add an alias, or run `php /path/to/acqc/bin/acqc`:

```bash
alias acqc='php /path/to/acqc/bin/acqc'
```

Full steps and optional tools: [Getting started](docs/getting-started.md).

## Quick start

Run these from the root of the Adobe Commerce project you want to check:

```bash
acqc init        # writes .acqc/project.yaml
acqc doctor      # checks the setup and lists next steps
acqc audit .     # score, grade and issues; exit code 0 = pass, 1 = gate failed, 2 = tool error
acqc report .    # HTML report in ./reports/
```

Add the pre-commit gate to the project:

```bash
acqc hooks:install
```

## Documentation

| Page | Contents |
|---|---|
| [Getting started](docs/getting-started.md) | Requirements, install, optional tools, first run |
| [Commands](docs/commands.md) | Which command to use, common options, exit codes, known limitations |
| [Configuration](docs/configuration.md) | Where settings live, profiles, environment variables |
| [Project config](docs/project-config.md) | Every `.acqc/project.yaml` key: control which findings show, scores, grades, release verdict, debt, gates |
| [Rules](docs/rules.md) | Where rules come from, list, suppress and write rules |
| [Reports](docs/reports.md) | Formats, file names, report types, filters |
| [Upgrade analysis](docs/upgrade.md) | `upgrade:analyze`, `detect-version`, `version:*` |
| [Pre-commit gate](docs/pre-commit.md) | Install the git hook, what it checks, per-commit controls |
| [CI/CD](docs/ci-cd.md) | GitHub Actions, GitLab CI, Bitbucket Pipelines, SARIF |
| [App Builder](docs/app-builder.md) | `appbuilder:lint`, abqc in `audit` |
| [Troubleshooting](docs/troubleshooting.md) | Common errors and fixes |

Every command has built-in help with the full option list:

```bash
acqc list
acqc help audit
```

## Development

```bash
composer install
composer test        # PHPUnit
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Security

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## License

`composer.json` declares the MIT license. The repository does not contain a LICENSE file yet.
