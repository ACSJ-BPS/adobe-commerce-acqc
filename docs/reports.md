# Reports

`acqc report` analyzes a path and writes a report file for people or for other tools. It is not a
quality gate: it exits `0` when the report is written, whatever it finds. Use
[`acqc audit`](commands.md#audit) to pass or fail a build.

`report` runs its own analyzer set (see [Project config](project-config.md#analyzers)). It does not
use the YAML rules or the profiles. PHP_CodeSniffer and the other PHP tools run only when they are on
your `PATH` (see [Getting started](getting-started.md#php-tools-for-report)). For an App Builder or
Node.js project that is not also an Adobe Commerce project, `report` skips PHP and XML files and
adds abqc findings instead, without the low-confidence ones.

## Examples

```bash
acqc report .                                          # HTML in ./reports/
acqc report app/code --format=excel                    # Excel workbook in ./reports/
acqc report . --format=json -o build/acqc              # JSON in the build/acqc/ folder
acqc report . --format=json -o build/acqc-report.json  # JSON in this exact file
acqc report . --format=excel -t comprehensive_magento_audit
acqc report . --min-severity=error                     # only error, critical and blocker
```

## Formats

| `-f, --format` | Default file name | Notes |
|---|---|---|
| `html` (default) | `<project>-report_<timestamp>.html` | Single file, open in a browser. |
| `excel` | `<project>-report_<timestamp>.xlsx` | |
| `json` | `<project>-report_<timestamp>.json` | Top-level keys: `metadata`, `report`, `data`, `computed`. The default `summary` type has counts only. For a list of findings, use `acqc audit --format=json` (see [Known limitations](commands.md#known-limitations)). |
| `sarif` | `acqc-<timestamp>.sarif` | For code-scanning tools. For a CI gate, `audit --format=sarif` is usually the better choice. |
| `junit` | `junit-acqc_<timestamp>.xml` | For CI test-result views. |
| `pdf` | | Needs TCPDF: `composer require tecnickcom/tcpdf`. Without it, the command fails. |

`<timestamp>` looks like `2026-11-06_07-05-12`. `<project>` is the project name in lower case with
dashes. ACQC takes the first one it finds: `--project-name`, `project.name` or `name` in `.acqc/project.yaml`, `name` in
the target's `composer.json` (the part after `/`), the repository name of the `origin` git remote,
then the folder name.

## Where the file goes

| `-o, --output` value | Result |
|---|---|
| Not set | Folder `reports/` in the current directory. |
| A path with a file extension, for example `build/acqc.json` | Written to exactly that file. |
| Any other path, for example `build/acqc` | Treated as a folder (created when missing); the default file name is used. |

`--filename=<prefix>` changes the start of the default file name. Example:
`--filename=shell-audit` writes `shell-audit_<timestamp>.json`.

## Report types

| `-t, --type` | Works with |
|---|---|
| `summary` (default) | `html`, `excel`, `json`, `sarif`, `junit` |
| `detailed` | `html`, `excel`, `json` |
| `executive` | `html`, `excel`, `json` |
| `performance` | `html`, `excel`, `json` |
| `security` | `html`, `excel` (fails with `json`) |
| `comprehensive_magento_audit` | `excel` only |

The `comprehensive_magento_audit` workbook has these sheets: Executive Summary, Code Quality
Analysis, Security Vulnerabilities, Performance Analysis, Module Analysis, Database Analysis,
REST API Analysis, GraphQL Analysis, Compliance Check and Coverage by Module.

## Filters

| Option | Effect |
|---|---|
| `-s, --min-severity` | Keep only this severity and higher: `minor`, `info`, `warning`, `error`, `critical`, `blocker` (or `1`-`6`). |
| `--min-impact` | `low`, `medium`, `high`, `critical`, the same as severity `warning`, `error`, `critical`, `blocker`. Ignored when `--min-severity` is set. |
| `--exclude-categories` | Comma-separated categories to drop, for example `seo,accessibility`. |
| `--exclude-types`, `--include-types` | Comma-separated issue types to drop or to keep, for example `performance`. |
| `--exclude-paths` | Comma-separated path patterns to skip. Default: `*/Test/*,*/Tests/*,*/test/*,*/tests/*`. A value you pass replaces the default. |

Set project defaults for the first four in the `report:` section of `.acqc/project.yaml`. See
[Project config](project-config.md#report). Filters run before scoring, so hidden findings do not
count in the scores.

## Test coverage

```bash
acqc report . --format=excel --coverage-clover=build/logs/clover.xml
```

`--coverage-clover` reads a PHPUnit Clover XML file (or a `--coverage-text` `.txt` file) and adds
the coverage data to the report.

`--generate-clover` writes a Clover file from static analysis, without running tests. Every file in
it shows 0% coverage, so use it only as a starting point. `--clover-output` sets where it is written.

## What report reads besides your code

When the target is an Adobe Commerce project (it has `bin/magento`), some `report` analyzers look
at the installation, not only the code:

| What | Analyzers |
|---|---|
| Load `app/etc/env.php` with PHP `include`, so any code in that file runs | `performance_http_protocol`, `infrastructure_cdn`, `performance_opcache`, `performance_api_rate_limiting`, `performance_indexers` |
| Send HTTP requests to the store base URL from `env.php`. When `env.php` has no base URL, `performance_http_protocol` runs `php bin/magento config:show web/secure/base_url` to find it. | `performance_http_protocol`, `infrastructure_cdn` |
| Read the OPcache settings and the load average of the machine that runs ACQC | `performance_opcache`, `performance_server_uptime` |

`audit`, `analyze` and `scan` do not do this. Run `report` only on code you trust. To turn these
checks off, set them to `false` in the `analyzers` section of `.acqc/project.yaml` (see
[Project config](project-config.md#analyzers)):

```yaml
analyzers:
  performance_http_protocol: false
  infrastructure_cdn: false
  performance_opcache: false
  performance_api_rate_limiting: false
  performance_indexers: false
  performance_server_uptime: false
```

## More options

`acqc help report` lists every option. Options that are not on this page are not covered by this
documentation.
