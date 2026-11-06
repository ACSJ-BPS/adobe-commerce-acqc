# Getting started

This page takes you from a fresh clone to your first audit and report.

## Requirements

| Need | Version | Why |
|---|---|---|
| PHP | 8.2 or later | The locked dependencies (for example `phpoffice/phpspreadsheet` 5.x and Symfony 7.4) need PHP 8.2+. |
| Composer | 2.x | Installs ACQC and the PHP tools that `report` can use. |
| Git | any recent | Needed for `audit --staged`, `audit --diff-from` and the pre-commit hook. |
| Node.js and npm | Node 20.19+ | Only for App Builder / Node.js projects. See [App Builder](app-builder.md). |

Composer reports any missing PHP extension during `composer install`.

## Install

```bash
git clone https://github.com/OneAdobe/adobe-commerce-acqc.git acqc
cd acqc
composer install
php bin/acqc --version
```

`composer install` also creates `vendor/bin/acqc` as a link to `bin/acqc`, and registers the
`Magento2` coding standard with PHP_CodeSniffer. Check it with:

```bash
vendor/bin/phpcs -i     # the list must contain Magento2
```

For App Builder checks, also run `npm install` in the ACQC folder. It builds the bundled `abqc` engine.

### PHP tools for `report`

`acqc report` runs PHP_CodeSniffer, PHPMD, PHPStan and PHPCPD only when it finds the `phpcs`,
`phpmd`, `phpstan` and `phpcpd` commands on your `PATH`. It does not look in the ACQC `vendor/bin`
folder. To use the copies that Composer installed:

```bash
export PATH="/path/to/acqc/vendor/bin:$PATH"
```

| Tool | Installed by `composer install` | Adds findings to `report` |
|---|---|---|
| PHP_CodeSniffer (`Magento2` standard) | Yes | Yes, when `phpcs` is on `PATH`. Files whose absolute path contains a `var`, `vendor`, `generated`, `node_modules` or `pub/static` folder are skipped, so a project under `/var/www/` gets no PHP_CodeSniffer findings. |
| PHPMD | Yes | Not on PHP 8.4: PHPMD prints deprecation notices that ACQC cannot parse. |
| PHPStan | Yes (it comes with `rector/rector`) | No: ACQC passes `--parallel`, which the installed PHPStan 2 rejects. |
| PHPCPD | No | ACQC runs it when `phpcpd` is on `PATH`. |
| TCPDF | No | Needed only for `report --format=pdf`: `composer require tecnickcom/tcpdf`. |

Without these tools, the built-in analyzers still run. `audit` does not use any of them.

### Shell alias (optional)

The examples in these docs use `acqc`. Add an alias, or run `php /path/to/acqc/bin/acqc` instead:

```bash
alias acqc='php /path/to/acqc/bin/acqc'
```

## First run

Run these from the root of the Adobe Commerce project you want to check.

```bash
cd /path/to/your-project

acqc init          # writes .acqc/project.yaml
acqc doctor        # checks the setup and lists next steps
acqc audit .       # quality gate: score, grade, issues, exit code
acqc report .      # HTML report in ./reports/
```

| Step | What you get |
|---|---|
| `acqc init` | `.acqc/project.yaml` with the detected platform, PHP version and rule bundle. Add `--ci=github`, `--ci=gitlab` or `--ci=bitbucket` to also write a starter CI file (see [CI/CD](ci-cd.md)). |
| `acqc doctor` | A checklist of the project setup (config file, rule bundle, team rules, CI file, suppressions) with a score and the commands to fix gaps. Add `--json` for machine output. |
| `acqc audit .` | Findings from the built-in analyzers and the YAML rules, a score and a grade. Exit code `0` = pass, `1` = gate failed, `2` = tool error. |
| `acqc report .` | A self-contained HTML report named `<project>-report_<timestamp>.html` in `./reports/` (see [Reports](reports.md#formats)). |

Tip: `acqc audit app/code` checks only custom code, but it does not apply the team rules and
suppressions in `<project>/.acqc/`. To check selected files with them, run from the project root:
`acqc audit . --files=app/code/Vendor/Module/Model/A.php`.

## Next steps

| I want to | Read |
|---|---|
| See every command and when to use it | [Commands](commands.md) |
| Block bad code before it is committed | [Pre-commit gate](pre-commit.md) |
| Run ACQC in a pipeline | [CI/CD](ci-cd.md) |
| Plan an Adobe Commerce upgrade | [Upgrade analysis](upgrade.md) |
| Tune scoring, filters and profiles | [Configuration](configuration.md) |
| Add, suppress or write rules | [Rules](rules.md) |
| Fix a problem | [Troubleshooting](troubleshooting.md) |
