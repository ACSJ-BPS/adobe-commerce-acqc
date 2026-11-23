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
acqc report . --run-coverage                          # explicitly request PHPUnit coverage generation
```

`--all-modules-paths` and `--specific-modules` also restrict the advanced analyzers' source
collection to the selected directories. Explicitly selected files are not discarded by the
external tools' default `vendor` exclusions; report path exclusions still apply.

To prevent automatic PHPUnit execution, configure `.acqc/project.yaml`:

```yaml
report:
  run_phpunit: false
```

Existing coverage files may still be read. Explicit `--run-coverage` or `--phpunit-binary`
overrides this setting when coverage needs generating. The default is `true`, preserving the
automatic coverage attempt.
Control environment analyzers separately through the `analyzers` section
(see [What report reads besides your code](#what-report-reads-besides-your-code)).

External tools remain optional: unavailable tools are skipped, and analyzer failures do not
prevent other analyzers from running or the report from being generated. Availability alone
does not guarantee a working integration; see [Known limitations](commands.md#known-limitations).

Successful external-tool version lookups are cached per analyzer instance and command arguments,
so recording version metadata does not launch another process for every finding. Failed lookups
are retried rather than cached.

## PHPCS severity policy

PHPCS defaults remain numeric thresholds of **5** for both errors and warnings, with the
existing ACQC mapping:

| Native PHPCS type | Numeric severity | ACQC severity |
|---|---|---|
| ERROR | 7 and above | Critical |
| ERROR | Below 7 | Error |
| WARNING | 7 and above | Error |
| WARNING | Below 7 | Warning |

Override thresholds and individual native type/severity mappings through the project's `phpcs`
section; see [Project config](project-config.md#phpcs). Numeric thresholds filter findings in
PHPCS before ACQC scoring. Mapping overrides change ACQC severity, not PHPCS's native type or
numeric severity. These settings do not change other analyzers or the report command's exit
status.

## Overall scoring

The default report Overall score uses severity-weighted findings per 1,000 scanned lines,
including comments and blank lines. It no longer divides finding counts by a fixed 1,000.
Severity/category weights and explicit project scoring overrides are preserved. Previously
generated reports retain their original scores until regenerated. See
[Overall score](project-config.md#overall-score-report) for the formula and legacy override.

## PHPMD project policy

To prefer external checks over redundant native findings without disabling whole native analyzers,
opt into `report.prefer_external_checks: true`. Native fallback remains when the replacement fails,
is unavailable, is not selected or does not analyze the file. See
[Native check policy](project-config.md#prefer-external-checks-without-disabling-native-analyzers).

Use the project's `phpmd` section to select a curated ruleset, its priority cutoff and an
optional bootstrap. Add `phpmd.severity_mapping` for priority labels and
`phpmd.rule_severity_overrides` for exact rule exceptions; exceptions take precedence.
Unspecified labels retain ACQC defaults, and setup preserves these project settings.
Severity labels do not change rule selection or native-check ownership.
See [Project config](project-config.md#phpmd). This does not change the
default PHPMD rules or severity mapping for projects without overrides.

PHPMD file-level failures are isolated automatically by retrying smaller batches. Failing
files are skipped with explicit diagnostics, not permanent exclusions. A `partial` analyzer
receipt includes successful-file coverage and `skipped_files` (`file`, `error`); CLI and HTML
also warn. Native replacement uses only successfully analyzed files, retaining fallback on
skipped files. Global tool/configuration failures, malformed output, or no successful files
remain analyzer failures. A generated report is not a claim of complete PHPMD coverage.

For automatic version-matched setup, set `project.platform_version` to the exact Commerce release
and run `acqc phpmd:setup [project-path]`. It installs the curated resources and trusted bootstrap,
validates them, and enables PHPMD without a manual PHPMD `PATH` adjustment. See
[Automated setup](project-config.md#automated-magento-phpmd-setup).

Magento's curated ruleset is not the same as enabling all generic PHPMD rulesets. Its custom
rules require the matching static-test rule resources and a project autoloader in the PHPMD
subprocess. Keep these resources version-matched and retain their license notices.

## Formats

| `-f, --format` | Default file name | Notes |
|---|---|---|
| `html` (default) | `<project>-report_<timestamp>.html` | Single file, open in a browser. |
| `excel` | `<project>-report_<timestamp>.xlsx` | |
| `json` | `<project>-report_<timestamp>.json` | Top-level keys: `metadata`, `report`, `data`, `computed`. Use `--type=detailed` for findings. `data.analysis` records selected paths, collected files, effective `phpunit_enabled` policy and analyzer statuses. |
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
  performance_redis: false
  performance_varnish: false
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
