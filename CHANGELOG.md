# Changelog

All notable changes to ACQC (Adobe Commerce Quality Control) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Config-driven quality scoring engine (`config/scoring.yaml`, `ScoringConfigLoader`, `ScoreInputs`, `ScoreCalculator`, `ConditionEvaluator`) controlling HTML rings, release readiness pill/CI gate, Excel Executive KPI Scorecard (`E1-E20`), status bands, technical debt hours, JSON/PDF/SARIF/JUnit scores, and `audit` score/grade via `.acqc/project.yaml`.
- `ProjectConfigLocator`: locates `.acqc/project.yaml` by walking upwards from the target folder (e.g. `app/code`) to the git root, and supports `report:` filter defaults (`exclude_categories`, `min_severity`, etc.) and `analyzers:` flags in `.acqc/project.yaml`.
- `audit --staged | --diff-from=<ref> | --files=<list>` scoped audits and `--fail-on=<severities>`.
- `audit --format=compact` and the `pre-commit` profile; `hooks:install`, `hooks:status`, `hooks:uninstall` (git pre-commit gate).
- `audit --format=sarif` and `--sarif-file=<path>` (SARIF 2.1.0 with stable `partialFingerprints`).
- Scoped audits include App Builder / Node.js files (JS/TS, `package.json`, `app.config.yaml`, YAML) when the platform is detected; abqc checks the staged snapshot.

### Changed
- **Default category weights and dimension ring mappings (`config/scoring.yaml`):** `seo` and `accessibility` default to weight `0.25` in scores, and dimension rings now map expanded rule categories plus `rule_ids` globs (including deprecation rules `magento-upgrade-deprecated-*` and `magento-deprecated-pattern` under Compliance).
- **`audit` score and grade:** `audit` now uses `ScoreCalculator` (`scoring.audit.overall`) with caps for `blocker >= 1` (max 40) and `critical >= 1` (max 60), unified grade/label bands (`scoring.grades`), and `100` for zero-file scopes.
- **Dead gate config removed:** removed unread `quality_gates` blocks from `config/reports.yaml`, `config/severity-levels.json`, and `config/profiles/{ci-pipeline,magento-code-review,magento-lead}.yaml`, and removed unused `thresholds.*` from `config/default.yaml` and `ConfigCommand`. `ConfigManager::getQualityGates()` now returns the effective `scoring.readiness` and `scoring.gates` configuration.
- **JUnit quality gates:** an unknown condition in `--quality-gates` now stops the report (exit 2) instead of passing silently. Built-in gates use the 0–100 scale: `overall >= 65` (was `quality_score >= 7.0`, which always failed because it read an unset key), `debt_hours <= 500` (was `<= 100`), plus a `release_readiness` gate from `scoring.readiness`.
- **`audit` score severities:** YAML rule severities `high` / `medium` / `low` now count as `error` / `warning` / `info` in the audit score (`scoring.audit.severity_aliases`). Before, they took no points off. The audit gate (`fail_on`) is unchanged.
- **`audit` exit codes are now a contract:** `0` gate passed, `1` gate failed or invalid usage, `2` ACQC runtime error (git failure, Node.js/abqc unavailable in a scoped audit). Previously only `0`/`1`.
- **Default audited file types are now `*.php`, `*.phtml`, `*.graphqls`, `*.xml`** (previously `*.php` only — the intended XML/GraphQL default in `AuditEngine` was never applied because `ConfigLoader` always supplied `*.php`). Full-audit `files_analyzed`, issue counts and scores for Commerce projects will change; templates are now checked for unescaped output. To keep the old behaviour, set `"include_patterns": ["*.php"]` in `acqc.json`.
- `audit` console output: "Audit completed." plus a quality-gate verdict block; JSON output gains `scope`, `fail_on`, `gate`, `critical`.
- `audit --output` is now an alias of `--format` (it was previously undefined for `audit`).
- **Documentation rewritten:** `README.md` is now a short landing page. The manual moved to `docs/` (`getting-started`, `commands`, `configuration`, `rules`, `reports`, `upgrade`, `pre-commit`, `ci-cd`, `app-builder`, `troubleshooting`). Every documented option was checked against `acqc help`. Pages for features this repository does not have (REST API, SDKs, PHP plugin system, AI commands) or does not use (MCP server) were removed: `docs/api.md`, `docs/extending.md`, `docs/installation.md`, `docs/usage.md`, `docs/index.md`, `mcp/README.md`, `GOVERNANCE.md`, `OPEN_SOURCE_GUIDE.md`, `config/rules/README.md`, `examples/custom-rules/`. `CONTRIBUTING.md`, `SECURITY.md`, `abqc/README.md`, `abqc/rules/README.md` and `server/README.md` were rewritten. `SECURITY.md` now lists what ACQC runs, reads and sends over the network.

### Fixed
- `SEOAnalyzer` and `AccessibilityAnalyzer`: page-level checks (title, meta tags, H1, structured data, thin content, lang attribute, skip links, landmarks, main element, live regions) now run only on full HTML documents (`<html` or `<!DOCTYPE`), and PHP class files without HTML markup are skipped, eliminating ~11,690 false positives on PHP classes while preserving element-level template checks.
- Report & generator scoring bugs: clean projects (0 findings) now score `100.0` instead of `0.0`; Excel default Summary sheet now populates `Files Analyzed`, `Lines of Code`, and `Release Readiness`; Excel Performance sheet status cells now read `scoring.status.performance_sheet`; `config/scoring.yaml` documents every key inline; scoring weights, hours and tiers must be numbers >= 0 (a negative or text value now fails with the config path); without a git repo only `<target>/.acqc/project.yaml` is used; Excel Threshold cells follow the configured status bands; `report --profile` warning goes to stderr (visible with `--format=json`); `acqc config` help no longer lists the removed `thresholds` keys; Excel Code Quality sheet shows `N/A` instead of invented placeholder values when metrics are absent; JUnit quality gates reject unknown conditions instead of silently passing and use the 0–100 score scale; PDF, JSON, SARIF, and HTML generators all use unified 0–100 `scores.*` values.
- Test suite: `phpunit.xml.dist` now bootstraps `tests/bootstrap.php` (test helpers and `ACQC_*` constants were never loaded, so ConfigManager, FullScan, ScanCommand, AnalyzerPerformance and all 9 `Integration/Rules` test classes errored or found no tests). The test error handler no longer turns deprecations or warnings from `src/` / `vendor/` into exceptions. Hooks functional tests ignore inherited `GIT_CONFIG_*` command-line config. Full suite: 628 tests, 0 errors, 0 failures (was 502 tests, 19 errors, 8 failures).
- `VersionRegistry::getLatestPatchFor('2.4.6')` returned `2.4.6` because the synced registry keys latest patches by minor (`2.4`). It now returns the highest known security patch of the line (`2.4.6-p14`), so upgrade paths stop at patched versions (e.g. 2.4.3 → 2.4.5-p16 → 2.4.6-p14 → 2.4.7-p9 → 2.4.8).
- SARIF output: issues were lost (summary keys treated as results), `file:////` source root, invalid empty `fixes`, missing rule ids.
- `scripts/build.sh` quality-check step referenced a non-existent `m2-quality` binary; it now runs `bin/acqc`.

## [3.1.0] - 2026-04-27

### Fixed
- HTML report: category and severity filter dropdowns now reflect only violations present in the visible rows (top 500). Previously, dropdowns showed counts from the full dataset while filters operated only on visible rows, causing categories such as SEO, Documentation and severities such as Warning/Info to always return 0 results on large codebases.
- HTML report: "Violations by Category" chart bars are now clickable — clicking a bar applies the category filter and scrolls to the violations table. A toast is shown if the selected category has no representation in the visible rows.
- HTML report: "Most Common Rule Violations" block now displays a short description excerpt (from the first matching violation) beside each rule ID, giving engineers immediate context without expanding individual rows.
- HTML report: truncation notice updated to explicitly state that filters apply to the N visible rows only, directing users to CSV/JSON export for the full dataset.

## [3.0.0] - 2026-04-25

### Added
- Enterprise CLI platform: `scan`, `validate`, `init`, `doctor`, `baseline`, `watch`, `aggregate`, `auto-fix`, `backup`, `history`, `dashboard`, `export`, `policy:check`, `project-quality`, `tech-spec`, `upgrade-readiness`, `detect-version` commands
- YAML-based rules engine with `RegexChecker`, `XmlXpathChecker`, `GuidanceChecker`, and `CheckerRegistry`
- 62-rule Adobe Commerce department bundle across 10 categories (security, DI, cron, HTTP, performance, GraphQL, indexing, cloud, async, architecture)
- Multi-layer rule loading (project → team → department → global → personal → CI layers)
- Role profiles: developer, tech-lead, architect, executive, ci — with tier filtering and fail-on severity gates
- Multi-platform export engine: Cursor `.mdc` files, Claude `CLAUDE.md`, SARIF 2.1.0, JUnit XML, HTML, PDF
- React dashboard: org-level overview, project detail, findings panel, suppression management, analytics, reports, settings pages
- Laravel API server with Sanctum auth, IDOR-hardened controllers, and scan/finding/suppression endpoints
- MCP server for AI IDE integration (Cursor, Claude Code)
- `rules:list`, `rules:validate`, `rules:suppress`, `rules:unsuppress`, `rules:sync`, `rules:new` management commands
- GitHub Actions CI/CD: PHP 8.1/8.2/8.3/8.4 matrix, rules-engine validation, self-analysis gate at score ≥ 85
- Docker Compose deployment stack with nginx, PHP-FPM, MySQL 8, Redis 7, queue worker

### Fixed
- IDOR vulnerabilities in FindingController and ScanController
- `bin/acqc` now catches `\Throwable` instead of `\Exception`
- YAML rules engine wired into AuditEngine (was previously disconnected)
- Shell injection in BaselineCommand git helpers — paths now escaped with `escapeshellarg()`
- `analyze --format=json` output path: `$output->write()` now correctly in scope
- `baseline create` confirmation prompt: `$output` undefined variable resolved
- SARIF driver version now reads from `Application::VERSION` instead of hardcoded `2.0.0`
- `init --ci` no longer coerces bare flag to bool `true`
- Sanctum `/csrf-cookie` endpoint correctly proxied by nginx
- PHP 8.4 implicit nullable parameter deprecations in `RuleEngine`, `RuleLoader`, `ScanCommand`

### Security
- nginx security headers: `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`
- `APP_DEBUG=false` in `.env.example` (was `true`)
- `SANCTUM_EXPIRATION` documented in `.env.example`
- `ACQC_SCAN_BASE_PATH` documented for path restriction in scan jobs

History before 3.0.0 is not kept in this file.
