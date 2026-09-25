# ACQC — Adobe Commerce Quality Control

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](<https://img.shields.io/badge/php-%3E%3D8.1-blue>)](https://php.net)
[![Adobe Commerce](https://img.shields.io/badge/adobe--commerce-2.4+-orange)](https://experienceleague.adobe.com/docs/commerce-operations/installation-guide/overview.html)
[![Build Status](https://img.shields.io/badge/build-passing-brightgreen)](https://github.com/adobe-commerce-quality/acqc)
[![Version](https://img.shields.io/badge/version-3.1.0-blue)](https://github.com/adobe-commerce-quality/acqc)
[![Rules](https://img.shields.io/badge/rules-112-purple)](https://github.com/adobe-commerce-quality/acqc)

> **Enterprise code quality platform for Adobe Commerce / Magento 2 — built on the Magento 2 coding standard.**

ACQC is a static analysis and reporting platform that enforces the [Magento 2 coding standard](https://github.com/magento/magento-coding-standard) as its primary quality gate, then layers PHPMD, PHPCPD, and 112 purpose-built YAML rules on top. It helps development teams maintain consistent standards, reduce technical debt, detect upgrade issues early, and generate enterprise-grade reports — all from a single CLI.

---

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Pre-commit Quality Gate (git hook)](#pre-commit-quality-gate-git-hook)
- [Analysis Commands](#analysis-commands)
  - [scan](#1-scan)
  - [analyze](#2-analyze)
  - [audit](#3-audit)
  - [upgrade:analyze](#4-upgradeanalyze)
  - [detect-version](#5-detect-version)
  - [watch](#6-watch)
  - [auto-fix](#7-auto-fix)
  - [validate](#8-validate)
- [Version Intelligence Commands](#version-intelligence-commands)
- [Reporting Commands](#reporting-commands)
  - [report](#12-report)
  - [checklist](#13-checklist)
  - [tech-spec](#14-tech-spec)
  - [history](#15-history)
  - [aggregate](#16-aggregate)
  - [export](#17-export)
- [Project Management Commands](#project-management-commands)
  - [baseline](#18-baseline)
  - [backup](#19-backup)
  - [policy:check](#20-policycheck)
- [Rules Management](#rules-management)
  - [rules:list](#21-ruleslist)
  - [rules:new](#22-rulesnew)
  - [rules:suppress / rules:unsuppress](#23-rulessuppress--rulesiinsuppress)
  - [rules:sync](#24-rulessync)
  - [rules:validate](#25-rulesvalidate)
- [Rule System Deep Dive](#rule-system-deep-dive)
- [Report Deep Dive](#report-deep-dive)
- [Policy-as-Code](#policy-as-code)
- [History &amp; Trends](#history--trends)
- [Multi-Project Aggregation](#multi-project-aggregation)
- [CI/CD Integration](#cicd-integration)
- [Configuration](#configuration)
- [Contributing](#contributing)
- [Roadmap](#roadmap)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Overview

Adobe Commerce projects accumulate technical debt quickly — custom modules, third-party extensions, and upgrade pressure compound over time. ACQC gives development teams a single command to measure and enforce quality across all three dimensions that matter: standards compliance, upgrade safety, and security posture.

**Who it is for:**

- **Developers** — instant feedback on violations, suppression management, live watch mode
- **Tech leads** — team-level scoring, policy-as-code enforcement, baseline tracking
- **Architects** — upgrade readiness reports, API change detection, architecture rule validation
- **DevOps / CI teams** — SARIF/JUnit/JSON output, GitHub Actions, GitLab CI, Bitbucket Pipelines
- **Executives** — PDF/Excel audit reports with 8-dimension quality scores

**What makes ACQC different from generic PHP linters:**

ACQC understands Adobe Commerce semantics that generic tools miss — dependency injection anti-patterns, plugin conflict detection, observer collision risks, declarative schema requirements, Zend→Laminas migration paths, GraphQL resolver N+1 patterns, and upgrade compatibility across 2.4.x versions. Its 112 built-in rules encode Magento 2 architecture knowledge directly.

---

## Key Features

### Analysis Stack — Layered, Standards-First

| Layer                  | Tool                                       | Role                                                                       | Required                  |
| ---------------------- | ------------------------------------------ | -------------------------------------------------------------------------- | ------------------------- |
| **Base gate**    | PHPCS +`magento/magento-coding-standard` | Primary standard — all Adobe Commerce coding rules                        | Yes                       |
| **Code health**  | PHPMD                                      | Code smells, complexity, unused code                                       | Yes                       |
| **Duplication**  | PHPCPD                                     | Copy-paste detection across files and classes                              | Optional (global install) |
| **Type safety**  | PHPStan                                    | Static type analysis, level 0–9                                           | Optional                  |
| **YAML rules**   | 112 purpose-built rules                    | Commerce-specific patterns: DI, security, GraphQL, DB schema, deprecations | Bundled                   |
| **Custom rules** | Policy-as-Code (YAML)                      | Team-specific rules, no PHP required                                       | Optional                  |

### 112 Rules Across 15 Categories

| Category         | Rules | What It Catches                                           |
| ---------------- | ----- | --------------------------------------------------------- |
| `architecture` | 10    | Plugin conflicts, preference chains, observer collisions  |
| `async`        | 3     | Blocking operations in message queue consumers            |
| `cloud`        | 3     | Local filesystem writes, env config in cloud              |
| `deprecation`  | 13    | Zend/Laminas migrations, deprecated APIs, removed classes |
| `di`           | 6     | ObjectManager direct use, around-plugin anti-patterns     |
| `graphql`      | 5     | N+1 resolvers, missing auth, unbounded list queries       |
| `indexing`     | 4     | Schedule vs. save mode, missing FK indexes                |
| `performance`  | —    | N+1 queries, eager loading, unbounded loops               |
| `schema`       | 10    | db_schema.xml naming, types, constraints                  |
| `security`     | 7     | SQL injection, XSS, CSRF, path traversal                  |
| `http`         | —    | Response handling, session misuse                         |
| `cron`         | —    | Cron group and schedule issues                            |
| `frontend`     | —    | LESS/SCSS, UI component issues                            |
| `guidance`     | —    | Best-practice advisory rules                              |
| `global`       | 1     | Cross-language best practices                             |

### Enterprise Reporting

- **8-dimension score dashboard** — Overall, Reliability, Security, Performance, Maintainability, Compliance, API Quality, Code Quality — rendered as SVG ring charts
- **Interactive HTML reports** — dark/light mode, filterable violations table, clickable category charts, hotspot analysis
- **Executive-grade exports** — PDF, Excel (12-sheet comprehensive audit), SARIF 2.1.0, JUnit XML, JSON, CSV
- **Auto-named report files** — project name auto-detected from `composer.json` / git remote: `myproject-report-2026-04-25.html`
- **500-row cap with full export** — reports stay browser-renderable; complete data available via CSV/JSON export

### Developer Experience

- **Project initialization** — `acqc init` generates `.acqc/project.yaml` with project config and optional CI workflow files
- **Health checks** — `acqc doctor` validates config, rules, CI integration, and suppression debt in one command
- **Live watch mode** — `acqc watch` re-runs analysis on every file save
- **Suppression management** — `rules:suppress` with required reason and optional review date for accountability
- **Baseline tracking** — lock in the current state, then only report new violations going forward
- **History & trends** — SQLite-backed scan history with trend comparison and delta reporting

---

## Requirements

| Requirement                | Version       | Notes                                                         |
| -------------------------- | ------------- | ------------------------------------------------------------- |
| PHP                        | 8.1 or higher | 8.2/8.3 recommended                                           |
| Composer                   | 2.x           |                                                               |
| Adobe Commerce / Magento 2 | 2.4.x         | Target project being analyzed                                 |
| ext-sqlite3                | Any           | For history tracking (`~/.acqc/history.sqlite`)             |
| ext-xml                    | Any           | For PHPCS and report generation                               |
| Xdebug 3.x                 | Optional      | For PHPUnit coverage collection in reports                    |
| PHPStan                    | Optional      | For type-safety analysis layer                                |
| PHPCPD                     | Optional      | Install globally:`composer global require sebastian/phpcpd` |

---

## Installation

```bash
git clone https://github.com/adobe-commerce-quality/acqc.git
cd acqc
composer install
```

Then register the Magento 2 coding standard:

```bash
./vendor/bin/phpcs --config-set installed_paths vendor/magento/magento-coding-standard

# Verify — should list "Magento2"
./vendor/bin/phpcs -i
```

That's it. The binary is at `bin/acqc`.

---

## Quick Start

Navigate to your Adobe Commerce project, then run ACQC against it:

```bash
# 1. Initialize ACQC for your project (creates .acqc/project.yaml)
php /path/to/acqc/bin/acqc init

# 2. Verify setup
php /path/to/acqc/bin/acqc doctor

# 3. Run a quick scan
php /path/to/acqc/bin/acqc scan app/code

# 4. Generate an interactive HTML report
php /path/to/acqc/bin/acqc report app/code --format=html --type=summary

# 5. Run a full audit
php /path/to/acqc/bin/acqc audit app/code --severity=error
```

> **Tip:** Add `bin/acqc` to your shell PATH or create an alias to avoid typing the full path every time:
>
> ```bash
> alias acqc="php /path/to/acqc/bin/acqc"
> ```

---

## Pre-commit Quality Gate (git hook)

Stop low-quality code **before it is committed**. Once installed in a target repository, every
`git commit` audits the **staged** content of the changed files and **blocks the commit** when it
contains `blocker`, `critical` or `error` issues. Warnings and info are reported as a count but never block.

### Install (once per repository clone)

```bash
cd /path/to/your-magento-project
php /path/to/acqc/bin/acqc hooks:install     # writes .git/hooks/pre-commit
php /path/to/acqc/bin/acqc hooks:status      # verify: "installed and healthy"
```

That's it — now just commit as usual:

```text
$ git commit -m "Add product importer"
ACQC: 1 file(s) checked (staged), fail on: blocker, critical, error
  app/code/Vendor/Module/Model/Importer.php:42  ERROR  [object_manager_usage] Direct ObjectManager usage is discouraged. Use dependency injection instead.
BLOCKED: 1 error (2 non-blocking issue(s) not shown).
Fix the issues above and re-stage, or bypass once with: git commit --no-verify
```

Fix the code, `git add` it again, and commit — it passes with `PASSED: no blocking issues.`

### What exactly is checked

| Aspect          | Behaviour                                                                                                                                                                                                                                                                                                          |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Files           | Only files**staged** for this commit (added/copied/modified/renamed): `.php`, `.phtml`, `.xml`, `.graphqls`, plus — when the repo is detected as App Builder / Node.js — `.js/.mjs/.cjs/.jsx/.ts/.tsx`, `package.json`, `app.config.yaml` and other YAML (from `config/platforms/*.yaml`)  |
| App Builder     | Changed JS/TS/config files are checked by abqc against the**staged** snapshot (unchanged `package.json`, `app.config.yaml`, ESLint config are copied in as context). Requires Node.js and a built `abqc/`; if either is missing the audit exits `2` (hook warns, `ACQC_STRICT=1` blocks, CI fails) |
| Content         | The**staged version** (git index), not your working copy — partial staging (`git add -p`) is handled correctly                                                                                                                                                                                            |
| Existing code   | Untouched files are not checked, so legacy issues elsewhere in the repo never block your commit                                                                                                                                                                                                                    |
| Rules / profile | Profile`pre-commit` (`config/profiles/pre-commit.yaml`); override per project with `.acqc/profiles/pre-commit.yaml`                                                                                                                                                                                          |
| Personal rules  | Ignored — every developer gets the same result                                                                                                                                                                                                                                                                    |
| Network         | None — the check is fully local                                                                                                                                                                                                                                                                                   |
| Speed           | Typically well under 1 second for a normal commit                                                                                                                                                                                                                                                                  |

### Per-commit controls (environment variables)

| Variable                  | Effect                                                             |
| ------------------------- | ------------------------------------------------------------------ |
| `ACQC_SKIP=1`           | Skip the ACQC check for this commit (other hooks still run)        |
| `ACQC_FAIL_ON=critical` | Override the blocking severities for this commit (comma-separated) |
| `ACQC_PROFILE=ci`       | Run with a different profile                                       |
| `ACQC_STRICT=1`         | Also block when ACQC itself cannot run (default: warn and allow)   |
| `ACQC_BIN=/path/acqc`   | Use a different ACQC binary                                        |
| `ACQC_PHP=/path/php`    | Use a different PHP binary                                         |

`git commit --no-verify` skips **all** hooks, including ACQC.

### How the hook finds ACQC

`$ACQC_BIN` → `<repo>/vendor/bin/acqc` (if ACQC is a Composer dev dependency) → the absolute path
recorded by `hooks:install`. If none is found, or ACQC crashes (exit code 2), the hook prints
`ACQC WARNING …` and **allows** the commit so a broken tool never blocks work (use `ACQC_STRICT=1` to block instead).
To make the hook **fail closed** by default, install it with `acqc hooks:install --strict`
(a one-off `ACQC_STRICT=0 git commit …` still overrides).

Scoped audits also lint staged `*.min.js` files and names that are case-variants of the usual
extensions (`Bad.PHP`), and flag hard-coded credentials (AWS/GitHub/Slack/Google keys, private keys)
in changed App Builder YAML such as `manifest.yml` and `app.config.yaml`. `.env` files are not scanned.

### Existing hooks, `core.hooksPath`, worktrees

- An existing non-ACQC `pre-commit` hook is preserved as `pre-commit.local` and **runs first**; if it fails, the commit is blocked.
- `hooks:uninstall` removes only the ACQC hook and restores `pre-commit.local`.
- `core.hooksPath` (e.g. Husky-style `.githooks/`) and git worktrees are respected.
- Re-running `hooks:install` updates the hook in place (e.g. after moving ACQC).

### Hook commands

| Command                                                                                              | Purpose                                                 |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| `acqc hooks:install [path] [--profile=pre-commit] [--acqc-bin=…] [--php=…] [--force] [--strict]` | Install or update the hook                              |
| `acqc hooks:status [path]`                                                                         | Show hook details; exit 0 only if installed and healthy |
| `acqc hooks:uninstall [path]`                                                                      | Remove the ACQC hook, restore a preserved hook          |

### Run the same check manually

```bash
acqc audit --staged --profile=pre-commit          # exactly what the hook runs
acqc audit . --diff-from=origin/main              # everything changed on your branch
acqc audit . --files=app/code/Vendor/Module/Model/A.php,app/code/Vendor/Module/etc/di.xml
```

> **Note:** a local hook gives fast feedback but can be bypassed (`--no-verify`). To *enforce*
> quality, also run `acqc audit --diff-from=<base> --fail-on=critical,error` in CI.

---

## Analysis Commands

### 1. `scan`

Fast, focused scan of a directory. Outputs to console by default; supports multiple output formats.

```bash
php bin/acqc scan <path> [options]
```

**Options:**

| Option                | Description                                                                                  | Default                  |
| --------------------- | -------------------------------------------------------------------------------------------- | ------------------------ |
| `--format`          | Output format:`console`, `json`, `xml`, `html`, `pdf`, `junit`, `csv`, `txt` | `console`              |
| `--output`          | Output file path                                                                             | stdout                   |
| `--severity`        | Minimum severity:`info`, `warning`, `error`, `critical`                              | `warning`              |
| `--profile`         | Role profile:`developer`, `tech-lead`, `architect`, `executive`, `ci`              | project default          |
| `--analyzers`       | Comma-separated list of analyzers to run                                                     | all                      |
| `--exclude-paths`   | Path patterns to exclude                                                                     | `vendor,var,generated` |
| `--exclude-modules` | Module names to exclude                                                                      | —                       |
| `--parallel`        | Number of parallel workers                                                                   | 4                        |
| `--no-cache`        | Disable caching                                                                              | —                       |

**Examples:**

```bash
# Scan current directory
php bin/acqc scan .

# Scan specific module
php bin/acqc scan app/code/Vendor/Module

# Errors only, JSON output
php bin/acqc scan app/code --severity=error --format=json --output=scan.json

# Run only security and DI analyzers
php bin/acqc scan . --analyzers=security,di

# Exclude test directories
php bin/acqc scan . --exclude-paths="*/Test/*,*/tests/*"
```

**Available analyzers:**

| Analyzer        | What it runs                                                 |
| --------------- | ------------------------------------------------------------ |
| `phpcs`       | Magento 2 coding standard (PHPCS)                            |
| `phpmd`       | Code smell and complexity analysis (PHPMD)                   |
| `phpcpd`      | Copy-paste detection                                         |
| `phpstan`     | Static type analysis                                         |
| `security`    | Security rule set (SQL injection, XSS, CSRF, path traversal) |
| `di`          | Dependency injection anti-patterns                           |
| `graphql`     | GraphQL resolver quality                                     |
| `schema`      | `db_schema.xml` validation                                 |
| `performance` | N+1 queries and performance patterns                         |
| `deprecation` | Deprecated and removed API usage                             |
| `upgrade`     | Upgrade compatibility analysis                               |

---

### 2. `analyze`

Deep, file-level analysis with full violation context. Intended for module-by-module investigation.

```bash
php bin/acqc analyze <target> [options]
```

**Options:**

| Option             | Description                                                        |
| ------------------ | ------------------------------------------------------------------ |
| `--module`       | Treat target as a Magento module namespace (e.g.`Vendor/Module`) |
| `--format`       | Output format:`table`, `json`, `xml`, `html`               |
| `--output`       | Output file path                                                   |
| `--severity`     | Minimum severity:`info`, `warning`, `error`, `critical`    |
| `--generate-fix` | Generate an executable fix script for detected issues              |
| `--analyzers`    | Comma-separated analyzers to run                                   |
| `--exclude`      | Path patterns to exclude                                           |

**Examples:**

```bash
# Analyze a directory
php bin/acqc analyze app/code/Vendor/Module

# Analyze as a module namespace
php bin/acqc analyze Vendor/Module --module

# Generate a fix script
php bin/acqc analyze app/code/Vendor/Module --generate-fix

# JSON output
php bin/acqc analyze app/code --format=json --output=analysis.json
```

---

### 3. `audit`

Comprehensive audit combining all analyzers with scoring and executive summary. The primary command for full-project quality assessment.

```bash
php bin/acqc audit <path> [options]
```

**Options:**

| Option           | Description                                                                                                                            | Default            |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ------------------ |
| `--format`     | Output format:`console`, `json`, `html`, `compact` (one line per blocking issue, for hooks), `sarif` (SARIF 2.1.0 on stdout) | profile default    |
| `--sarif-file` | Also write SARIF 2.1.0 to this file, whatever`--format` is                                                                           | —                 |
| `--severity`   | Minimum severity                                                                                                                       | profile default    |
| `--profile`    | Role profile:`developer`, `tech-lead`, `architect`, `executive`, `ci`, `pre-commit`                                        | `developer`      |
| `--fail-on`    | Comma-separated severities that fail the gate, e.g.`critical,error` (overrides profile `fail_on`)                                  | profile`fail_on` |
| `--staged`     | Audit only the staged content of changed files (what the next commit contains)                                                         | —                 |
| `--diff-from`  | Audit only files changed vs a branch, commit or range (`main`, `origin/main..HEAD`)                                                | —                 |
| `--files`      | Audit only these files (comma-separated or repeated, relative to`<path>`)                                                            | —                 |
| `--exclude`    | Paths to exclude                                                                                                                       | —                 |

`--staged`, `--diff-from` and `--files` are mutually exclusive. If the selected scope has no
auditable files, the audit passes.

**Exit codes:**

| Code  | Meaning                                                             |
| ----- | ------------------------------------------------------------------- |
| `0` | Gate passed (no issue with a failing severity, or nothing to check) |
| `1` | Gate failed, or invalid usage (unknown profile/format/severity)     |
| `2` | ACQC runtime error (e.g. not a git repository, unknown git ref)     |

**Examples:**

```bash
# Full audit with console output
php bin/acqc audit app/code

# Fail if any critical violation found
php bin/acqc audit app/code --fail-on=critical

# Only what you are about to commit (used by the pre-commit hook)
php bin/acqc audit --staged --profile=pre-commit

# Only files changed on your branch
php bin/acqc audit . --diff-from=origin/main --fail-on=critical,error

# JSON for programmatic processing
php bin/acqc audit app/code --format=json > audit.json

# SARIF for GitHub code scanning / VS Code SARIF Viewer (changed files only)
php bin/acqc audit . --diff-from=origin/main --format=sarif > acqc.sarif
```

**SARIF output** (`--format=sarif` or `--sarif-file=…`):

- Paths are repo-relative (`SRCROOT`), also for `--staged` — temporary snapshot paths never appear.
- Each result has `partialFingerprints["acqcFingerprint/v1"]` = hash of rule + path + the flagged
  line's text, so an alert keeps its identity when code above it moves and closes only when the code is fixed.
- Levels: `blocker`/`critical`/`error` → `error`, `warning` → `warning`, `info`/`minor` → `note`.
  Security rules also get `security-severity` (critical 9.0, error 7.5, …) for GitHub's severity buckets.
- Exit codes are unchanged (0/1/2). On a tool error stdout stays empty and the message goes to stderr,
  so a redirected `.sarif` file is never half-written.
- The file contains paths, line numbers and messages — no source code.

GitHub Actions — upload the findings **and** enforce the gate as separate steps, so alerts are
published even when the gate fails, and the job still goes red (requires `security-events: write`
and a checkout with history, e.g. `fetch-depth: 0`):

```yaml
- name: ACQC audit (SARIF)
  id: acqc
  run: |
    set +e
    php acqc/bin/acqc audit . --diff-from="origin/${{ github.base_ref }}" --format=sarif > acqc.sarif
    echo "exit=$?" >> "$GITHUB_OUTPUT"

# Exit 0/1 = valid SARIF was produced; exit 2 = tool error, acqc.sarif is empty — skip the upload.
- name: Upload SARIF
  if: always() && (steps.acqc.outputs.exit == '0' || steps.acqc.outputs.exit == '1')
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: acqc.sarif
    category: acqc

- name: Enforce ACQC quality gate
  if: always() && steps.acqc.outputs.exit != '0'
  run: |
    case "${{ steps.acqc.outputs.exit }}" in
      1) echo "::error::ACQC quality gate failed — see the Code scanning alerts on this PR." ;;
      *) echo "::error::ACQC could not run (exit ${{ steps.acqc.outputs.exit }}) — check the audit step log." ;;
    esac
    exit 1
```

In CI a tool error (exit 2) also fails the job — unlike the local pre-commit hook, which allows the
commit unless `ACQC_STRICT=1`.

---

### 4. `upgrade:analyze`

Analyze an Adobe Commerce project for upgrade compatibility. Identifies removed APIs, deprecated APIs, Zend→Laminas migration requirements, and PHPCS violations against the target version.

```bash
php bin/acqc upgrade:analyze <path> [options]
```

**Options:**

| Option                | Description                                                    | Default     |
| --------------------- | -------------------------------------------------------------- | ----------- |
| `--current-version` | Current Magento version (`auto` to detect)                   | `auto`    |
| `--target-version`  | Target Magento version                                         | `2.4.8`   |
| `--format`          | Output format:`console`, `json`, `html`, `excel`       | `console` |
| `--output`          | Output file path                                               | —          |
| `--priority`        | Filter by priority:`critical`, `high`, `medium`, `low` | all         |

**Examples:**

```bash
# Upgrade compatibility from 2.4.4 to 2.4.8
php bin/acqc upgrade:analyze app/code \
  --current-version=2.4.4 \
  --target-version=2.4.8

# Auto-detect current version
php bin/acqc upgrade:analyze app/code \
  --current-version=auto \
  --target-version=2.4.8

# HTML report of upgrade blockers
php bin/acqc upgrade:analyze app/code \
  --target-version=2.4.8 \
  --format=html \
  --output=upgrade-report.html
```

**What it detects:**

- Removed or renamed PHP classes and methods between versions
- Deprecated APIs with removal timeline
- Zend Framework → Laminas migration (breaking in 2.4.4+)
- `db_schema.xml` declarative schema requirements
- PHP version compatibility (8.1 / 8.2 / 8.3)
- Composer package constraints

---

### 5. `detect-version`

Auto-detect the Adobe Commerce version of a project without running a full scan.

```bash
php bin/acqc detect-version [path] [--format=console|json]
```

**Examples:**

```bash
php bin/acqc detect-version .
php bin/acqc detect-version /path/to/project --format=json
```

---

### 6. `watch`

Live analysis mode — re-runs the configured scan on every file save. Useful during active development.

```bash
php bin/acqc watch [path] [options]
```

**Options:**

| Option          | Description                 | Default                 |
| --------------- | --------------------------- | ----------------------- |
| `--interval`  | Polling interval in seconds | `2`                   |
| `--severity`  | Minimum severity to display | `warning`             |
| `--analyzers` | Analyzers to run on change  | all                     |
| `--exclude`   | Path patterns to exclude    | `*/Test/*,*/vendor/*` |

**Examples:**

```bash
php bin/acqc watch app/code
php bin/acqc watch app/code --interval=3 --severity=error
```

---

### 7. `auto-fix`

Automatically fix common upgrade issues. Currently supports Zend Framework → Laminas migration. More fixers are planned.

```bash
php bin/acqc auto-fix <path> [options]
```

**Options:**

| Option            | Description                                  |
| ----------------- | -------------------------------------------- |
| `--fixer=NAME`  | Apply a specific fixer only (default: all)   |
| `--dry-run`     | Preview changes without applying them        |
| `--no-backup`   | Skip creating a backup before applying fixes |
| `--list-fixers` | Show all available fixers                    |

**Available fixers:**

| Fixer               | What it fixes                                                   |
| ------------------- | --------------------------------------------------------------- |
| `zend-to-laminas` | Converts Zend Framework class references to Laminas equivalents |

**Examples:**

```bash
# List available fixers
php bin/acqc auto-fix --list-fixers

# Preview what would be changed (safe, no writes)
php bin/acqc auto-fix app/code --dry-run

# Apply all fixes (creates a backup first)
php bin/acqc auto-fix app/code

# Apply only the Zend → Laminas fixer
php bin/acqc auto-fix app/code --fixer=zend-to-laminas
```

> **Note:** A backup is created automatically before any changes are applied. Use `--dry-run` to review changes first.

---

### 8. `validate`

Validate that all ACQC analyzers and audit rules are working correctly. Use this to verify your ACQC setup after installation or after adding custom rules.

```bash
php bin/acqc validate [options]
```

**Options:**

| Option       | Description                              | Default  |
| ------------ | ---------------------------------------- | -------- |
| `--format` | Export format:`json`, `yaml`, `md` | `json` |
| `--export` | Export validation results to a file      | —       |

**Examples:**

```bash
# Validate all analyzers and rules
php bin/acqc validate

# Export results as Markdown
php bin/acqc validate --format=md --export=validation-report.md
```

---

## Version Intelligence Commands

ACQC maintains a version database of Adobe Commerce releases and their compatibility information.

```bash
php bin/acqc version:list                    # List all known versions
php bin/acqc version:show 2.4.7             # Show release details
php bin/acqc version:diff 2.4.4 2.4.7      # API changes between versions
php bin/acqc version:path 2.4.2 2.4.7      # Safe upgrade path
php bin/acqc version:advisories 2.4.6      # Security advisories for a version
php bin/acqc version:eol-report             # End-of-life status for all versions
php bin/acqc version:sync                   # Sync version database
```

**`version:diff` example:**

```bash
php bin/acqc version:diff 2.4.4 2.4.7 --format=json > api-changes.json
```

Shows added, removed, deprecated, and changed PHP APIs between two versions.

**`version:path` example:**

```bash
php bin/acqc version:path 2.4.2 2.4.7
```

Outputs the recommended step-by-step upgrade path with intermediate versions where direct upgrade is risky.

---

## Reporting Commands

### 12. `report`

Generate a comprehensive report with quality scores, violation breakdown, and trend data.

```bash
php bin/acqc report <path> [options]
```

**Options:**

| Option                | Description                                                  | Default      |
| --------------------- | ------------------------------------------------------------ | ------------ |
| `--format`          | `html`, `pdf`, `excel`, `json`, `junit`, `sarif` | `html`     |
| `--type`            | `summary`, `detailed`, `executive`, `diff`           | `detailed` |
| `--output`          | Output file or directory                                     | auto-named   |
| `--min-severity`    | Minimum severity to include                                  | `info`     |
| `--module`          | Single module:`Vendor_Module`                              | —           |
| `--exclude-modules` | Comma-separated modules to skip                              | —           |
| `--diff-baseline`   | Compare against a baseline file                              | —           |
| `--coverage-clover` | Path to PHPUnit clover.xml for code coverage                 | —           |

**Format details:**

| Format    | Use Case                                                                       |
| --------- | ------------------------------------------------------------------------------ |
| `html`  | Interactive browser report with charts, dark/light mode, filterable table      |
| `pdf`   | Printable report for stakeholder distribution                                  |
| `excel` | 12-sheet workbook: summary, violations by category, by file, by module, trends |
| `json`  | Machine-readable for CI or custom tooling                                      |
| `junit` | JUnit XML for CI/CD pipeline test reporters                                    |
| `sarif` | SARIF 2.1.0 for GitHub Code Scanning / Azure DevOps                            |
| `csv`   | Full violation dataset for spreadsheet analysis                                |

**Examples:**

```bash
# Interactive HTML report (auto-named from project)
php bin/acqc report app/code --format=html

# Executive PDF report, errors and above only
php bin/acqc report app/code --format=pdf --type=executive --min-severity=error

# Excel audit workbook
php bin/acqc report app/code --format=excel --output=audit.xlsx

# SARIF for GitHub Code Scanning
php bin/acqc report app/code --format=sarif --output=acqc.sarif

# Diff report — only new violations since baseline
php bin/acqc report app/code --type=diff --diff-baseline=.acqc-baseline.json
```

### HTML Report Features

- **8-dimension score ring** — Overall, Reliability, Security, Performance, Maintainability, Compliance, API Quality, Code Quality
- **Violations table** — sortable, filterable by category, severity, file, rule ID; 500-row cap with export
- **Category donut chart** — clickable bars scroll to filtered violations
- **Module hotspot** — which modules have the most violations
- **Dark/light mode** — persisted preference
- **Full data export** — CSV and JSON export buttons bypass the 500-row cap

### SARIF Output

```bash
php bin/acqc report app/code --format=sarif --output=acqc.sarif
```

Upload to GitHub Code Scanning:

```yaml
- uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: acqc.sarif
    category: acqc
```

---

### 13. `checklist`

Generate a role-based quality checklist, optionally scanning a path for violations relevant to that role.

```bash
php bin/acqc checklist <role> [path] [options]
```

`role` is required: `junior-developer`, `senior-developer`, or `architect`. `path` is optional (defaults to the current directory).

| Option           | Description                                                     | Default   |
| ---------------- | --------------------------------------------------------------- | --------- |
| `--format`     | Output format:`table`, `json`, `xml`, `html`            | `table` |
| `--category`   | Restrict to a specific rule category                            | —        |
| `--severity`   | Minimum severity:`info`, `warning`, `error`, `critical` | `info`  |
| `--output`     | Output file path                                                | stdout    |
| `--detailed`   | Show detailed checklist items                                   | —        |
| `--tools-only` | Show only tool recommendations                                  | —        |
| `--summary`    | Show summary only                                               | —        |

---

### 14. `tech-spec`

Generate a technical specification document describing the responsibilities, competencies, and quality requirements for a developer role.

```bash
php bin/acqc tech-spec <role> [options]
```

`role` is required: `junior-developer`, `senior-developer`, or `architect`.

| Option                 | Description                                | Default      |
| ---------------------- | ------------------------------------------ | ------------ |
| `--format`           | `markdown`, `json`, `yaml`, `html` | `markdown` |
| `--output`           | Output file path                           | stdout       |
| `--include-tools`    | Include tool configurations                | —           |
| `--include-examples` | Include code examples                      | —           |
| `--include-metrics`  | Include quality metrics and thresholds     | —           |

---

### 15. `history`

View scan history and quality trends over time.

```bash
php bin/acqc history [options]
```

**Options:**

| Option       | Description                       | Default   |
| ------------ | --------------------------------- | --------- |
| `--format` | Output format:`table`, `json` | `table` |

**Examples:**

```bash
php bin/acqc history
php bin/acqc history --format=json
```

History is stored in `~/.acqc/history.sqlite` and includes per-scan scores, violation counts by severity, and timing data.

---

### 16. `aggregate`

Aggregate scan results across multiple projects into a single report.

```bash
php bin/acqc aggregate [options]
```

**Examples:**

```bash
# Aggregate results from multiple result files
php bin/acqc aggregate \
  --results-dir=./scan-results \
  --format=html \
  --output=org-overview.html

# Multi-project comparison
php bin/acqc aggregate \
  --projects=/projects/store-a,/projects/store-b \
  --format=excel
```

---

### 17. `export`

Export ACQC rules and configuration as context files for use in developer tools and IDEs.

```bash
php bin/acqc export <target> [options]
```

| Target     | Output                     | Use Case                              |
| ---------- | -------------------------- | ------------------------------------- |
| `cursor` | `.cursor/rules/acqc.mdc` | Cursor IDE rules context              |
| `claude` | `CLAUDE.md`              | Claude-aware coding assistant context |
| `sarif`  | SARIF 2.1.0 file           | GitHub Code Scanning, Azure DevOps    |
| `junit`  | JUnit XML                  | CI pipeline test reporters            |
| `json`   | Rules as JSON              | Custom integrations                   |

---

## Project Management Commands

### 18. `baseline`

Create and manage a quality baseline — lock in the current violation state, then only report new violations introduced after that point.

```bash
php bin/acqc baseline <subcommand> [options]
```

| Subcommand  | Description                                      |
| ----------- | ------------------------------------------------ |
| `create`  | Snapshot current violations as the baseline      |
| `compare` | Show violations added or resolved since baseline |
| `reset`   | Remove the current baseline                      |
| `show`    | Display current baseline summary                 |

**Examples:**

```bash
php bin/acqc baseline create
php bin/acqc baseline compare
php bin/acqc report app/code --type=diff --diff-baseline=.acqc-baseline.json
```

Commit `.acqc-baseline.json` to share the agreed-upon starting point across the team.

---

### 19. `backup`

Create and restore backups of ACQC project configuration and scan history.

```bash
php bin/acqc backup [create|restore|list] [options]
```

```bash
php bin/acqc backup create --output=acqc-backup-2026-04.tar.gz
php bin/acqc backup list
php bin/acqc backup restore acqc-backup-2026-04.tar.gz
```

---

### 20. `policy:check`

Enforce a policy file against the current scan results. Exits non-zero if thresholds are breached. Designed for CI gates.

```bash
php bin/acqc policy:check [options]
```

| Option        | Description                                         |
| ------------- | --------------------------------------------------- |
| `--policy`  | Path to policy file (default:`.acqc/policy.yaml`) |
| `--results` | Path to scan results JSON                           |
| `--format`  | Output format:`console`, `json`                 |

**Example policy file (`.acqc/policy.yaml`):**

```yaml
thresholds:
  quality_score:
    minimum: 70
    fail_on_breach: true
  critical_violations:
    maximum: 0
    fail_on_breach: true
  error_violations:
    maximum: 10
    fail_on_breach: false     # warn only

rules:
  required_passing:
    - AC_SEC_001              # SQL injection check must always pass
    - AC_SEC_002              # XSS check must always pass
  allowed_failing:
    - AC_STYLE_003            # Legacy style — tracked for improvement
```

```bash
php bin/acqc scan app/code --format=json --output=scan.json
php bin/acqc policy:check --policy=.acqc/policy.yaml --results=scan.json
```

---

## Rules Management

### 21. `rules:list`

List all rules available to the current project, with filtering options.

```bash
php bin/acqc rules:list [options]
```

| Option            | Description                             | Default   |
| ----------------- | --------------------------------------- | --------- |
| `--format`      | `table`, `json`, `csv`            | `table` |
| `--category`    | Filter by category                      | —        |
| `--severity`    | Filter by severity                      | —        |
| `--profile`     | Filter by role profile                  | —        |
| `--active-only` | Show only active (non-suppressed) rules | —        |

```bash
php bin/acqc rules:list
php bin/acqc rules:list --category=security
php bin/acqc rules:list --active-only
```

---

### 22. `rules:new`

Scaffold a new custom YAML rule. Generates a template file with the correct structure.

```bash
php bin/acqc rules:new <rule-id> [options]
```

```bash
php bin/acqc rules:new MY_RULE_001 \
  --category=security \
  --severity=critical \
  --type=regex
```

This generates a template at `.acqc/rules/team/MY_RULE_001.yaml`:

```yaml
id: MY_RULE_001
name: "Disallow eval() usage"
description: "Using eval() creates security vulnerabilities and is prohibited"
category: security
severity: critical
type: regex
checker:
  pattern: '\beval\s*\('
  message: "eval() is prohibited. Refactor to use proper PHP logic."
  files: "*.php"
fix: "Remove eval() and replace with the appropriate logic or a dedicated parser."
```

---

### 23. `rules:suppress` / `rules:unsuppress`

Suppress or re-enable a rule for the current project. Suppressions require a reason for accountability.

```bash
php bin/acqc rules:suppress <rule-id> --reason="..." [--review-by=YYYY-MM-DD]
php bin/acqc rules:unsuppress <rule-id>
```

```bash
# Suppress with a reason and review date
php bin/acqc rules:suppress AC_DI_001 \
  --reason="Legacy module — scheduled for refactor in Q3" \
  --review-by=2026-10-01

# Remove when the issue is fixed
php bin/acqc rules:unsuppress AC_DI_001
```

Suppression state is stored in `.acqc/suppressions.yaml` — commit this file so suppressions are shared across the team.

---

### 24. `rules:sync`

Download or update the ACQC rule bundles from the remote registry.

```bash
php bin/acqc rules:sync [--bundle=adobe-commerce] [--force]
```

---

### 25. `rules:validate`

Validate all YAML rule files for correct syntax, required fields, and pattern validity.

```bash
php bin/acqc rules:validate [<path>] [--format=text|json]
```

---

## Rule System Deep Dive

ACQC rules are defined in YAML and support three checker types:

### `regex` — Pattern Matching

```yaml
id: AC_DI_001
name: "Avoid ObjectManager direct use"
description: "Direct ObjectManager usage bypasses the DI framework"
category: di
severity: error
type: regex
checker:
  pattern: 'ObjectManager::getInstance\(\)'
  message: "Use constructor injection instead of ObjectManager::getInstance()"
  files: "*.php"
  exclude: "*/Test/*"
fix: "Declare the dependency in the constructor and let the DI framework inject it."
references:
  - "https://devdocs.magento.com/guides/v2.4/extension-dev-guide/depend-inj.html"
```

### `xpath` — XML Analysis

```yaml
id: AC_SCHEMA_001
name: "db_schema column must have comment"
category: schema
severity: warning
type: xpath
checker:
  file_pattern: "*/etc/db_schema.xml"
  xpath: "//column[not(@comment)]"
  message: "Column '{@name}' in table '{parent::table/@name}' is missing a comment attribute"
fix: "Add a comment attribute describing the column's purpose."
```

### `guidance` — Documentation Advisory

```yaml
id: AC_ARCH_001
name: "Prefer service contracts over model classes"
category: architecture
severity: info
type: guidance
checker:
  pattern: 'use Magento\\.*\\Model\\(?!.*Interface)'
  message: "Using concrete model class directly — prefer the service contract interface"
fix: "Inject the repository or service contract interface instead of the concrete model."
```

### Rule Loading Layers

Rules are loaded in priority order (highest to lowest):

```
1. Personal     ~/.acqc/rules/personal/
2. Project      .acqc/rules/project/
3. CI           .acqc/rules/ci/ (loaded only in CI environments)
4. Team         .acqc/rules/team/
5. Department   .acqc/rules/department/
6. Global       vendor/adobe/acqc/config/rules/
```

Rules at higher layers override rules with the same ID from lower layers.

---

## Policy-as-Code

Encode your team's quality standards as a version-controlled policy file. ACQC enforces the policy automatically in CI.

```yaml
# .acqc/policy.yaml

thresholds:
  quality_score:
    minimum: 70
    fail_on_breach: true
  critical_violations:
    maximum: 0
    fail_on_breach: true
  error_violations:
    maximum: 10
    fail_on_breach: false

rules:
  required_passing:
    - AC_SEC_001      # SQL injection — always enforced
    - AC_SEC_002      # XSS — always enforced
    - AC_SEC_003      # CSRF — always enforced
  allowed_failing:
    - AC_STYLE_003    # Legacy style — tracked, not blocking

profiles:
  ci:
    severity: error
    fail_on: critical
  pre_release:
    severity: warning
    fail_on: error
    threshold: 80
```

```bash
php bin/acqc scan app/code --format=json --output=scan.json
php bin/acqc policy:check --policy=.acqc/policy.yaml --results=scan.json
```

---

## History & Trends

ACQC records every scan in a local SQLite database (`~/.acqc/history.sqlite`).

```bash
php bin/acqc history
php bin/acqc history --format=json
```

Every `scan`, `report`, and `audit` command automatically writes a history entry.

---

## Multi-Project Aggregation

```bash
php bin/acqc scan /projects/store-a/app/code --format=json --output=./results/store-a.json
php bin/acqc scan /projects/store-b/app/code --format=json --output=./results/store-b.json

php bin/acqc aggregate \
  --results-dir=./results \
  --format=html \
  --output=org-overview.html
```

---

## CI/CD Integration

### GitHub Actions

```yaml
name: ACQC Code Quality

on: [push, pull_request]

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install ACQC
        run: |
          git clone https://github.com/adobe-commerce-quality/acqc.git /opt/acqc
          cd /opt/acqc && composer install --no-dev
          /opt/acqc/vendor/bin/phpcs --config-set installed_paths /opt/acqc/vendor/magento/magento-coding-standard

      - name: Initialize
        run: php /opt/acqc/bin/acqc init --silent

      - name: Run quality scan
        run: |
          php /opt/acqc/bin/acqc scan app/code \
            --severity=error \
            --format=json \
            --output=acqc-scan.json

      - name: Enforce policy
        run: php /opt/acqc/bin/acqc policy:check --results=acqc-scan.json

      - name: Generate SARIF report
        run: |
          php /opt/acqc/bin/acqc report app/code \
            --format=sarif \
            --min-severity=error \
            --output=acqc.sarif

      - name: Upload SARIF to GitHub Code Scanning
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: acqc.sarif
          category: acqc
        if: always()
```

### GitLab CI

```yaml
code-quality:
  stage: test
  image: php:8.3
  before_script:
    - apt-get install -y git unzip
    - curl -sS https://getcomposer.org/installer | php
    - git clone https://github.com/adobe-commerce-quality/acqc.git /opt/acqc
    - cd /opt/acqc && php composer.phar install --no-dev
    - /opt/acqc/vendor/bin/phpcs --config-set installed_paths /opt/acqc/vendor/magento/magento-coding-standard
  script:
    - php /opt/acqc/bin/acqc init --silent
    - php /opt/acqc/bin/acqc scan app/code --severity=error --format=json --output=acqc.json
    - php /opt/acqc/bin/acqc policy:check --results=acqc.json
  artifacts:
    paths:
      - acqc.json
    expire_in: 30 days
```

### Bitbucket Pipelines

```yaml
pipelines:
  default:
    - step:
        name: ACQC Quality Gate
        image: php:8.3
        script:
          - git clone https://github.com/adobe-commerce-quality/acqc.git /opt/acqc
          - cd /opt/acqc && composer install --no-dev
          - /opt/acqc/vendor/bin/phpcs --config-set installed_paths /opt/acqc/vendor/magento/magento-coding-standard
          - php /opt/acqc/bin/acqc init --silent
          - php /opt/acqc/bin/acqc scan app/code --severity=error
          - php /opt/acqc/bin/acqc policy:check
```

---

## Configuration

### Project Settings (`.acqc/project.yaml`)

```yaml
project:
  name: "My Commerce Store"
  description: "Magento 2.4.7 custom module suite"
  version: "2.4.7"

rule_bundles:
  - adobe-commerce           # bundled — 112 rules across 15 categories

profiles:
  default: developer         # developer | tech-lead | architect | executive | ci

thresholds:
  quality_score: 70          # minimum acceptable score
  max_critical: 0            # CI fails on any critical violation
  max_error: 10              # CI warns on more than 10 errors
  test_coverage: 60          # minimum test coverage percentage

analyzers:
  phpcs:
    standard: Magento2
    severity: 5
  phpmd:
    rulesets: cleancode,codesize,controversial,design,naming,unusedcode
  phpstan:
    level: 5
    enabled: false           # enable when phpstan is installed
```

### Environment Variables

| Variable              | Description                                               | Default                    |
| --------------------- | --------------------------------------------------------- | -------------------------- |
| `ACQC_PROJECT_PATH` | Override project root detection                           | auto                       |
| `ACQC_HISTORY_DB`   | Path to history SQLite database                           | `~/.acqc/history.sqlite` |
| `ACQC_CACHE_DIR`    | Cache directory                                           | `~/.acqc/cache`          |
| `ACQC_LOG_LEVEL`    | Log verbosity:`debug`, `info`, `warning`, `error` | `warning`                |

---

## Contributing

Contributions are welcome — bug reports, feature requests, new rules, documentation improvements, and code.

### Reporting Bugs

Open an issue on [GitHub](https://github.com/adobe-commerce-quality/acqc/issues) with:

- ACQC version (`php bin/acqc --version`)
- PHP version and OS
- The exact command you ran
- Expected vs. actual output

### Development Setup

```bash
git clone https://github.com/adobe-commerce-quality/acqc.git
cd acqc
composer install
./vendor/bin/phpcs --config-set installed_paths vendor/magento/magento-coding-standard
composer test
```

### Running Tests

```bash
composer test
composer test:unit
composer test:integration
XDEBUG_MODE=coverage composer test:coverage
```

### Code Standards

All contributions must pass ACQC's own quality gate:

```bash
# Run ACQC on the ACQC source — must score ≥ 85
php bin/acqc audit src --threshold=85

composer cs-check     # PSR-12 check
composer cs-fix       # auto-fix
```

### Submitting a Pull Request

1. Create a feature branch: `git checkout -b feature/your-feature-name`
2. Write tests for any new behavior
3. Ensure all tests pass: `composer test`
4. Ensure the quality gate passes: `php bin/acqc audit src --threshold=85`
5. Use [Conventional Commits](https://conventionalcommits.org/) for commit messages
6. Open a PR against `main`

### Adding a New Rule

```bash
php bin/acqc rules:new AC_CAT_NNN --category=<category> --severity=<severity>
```

Write a test in `tests/Rules/<Category>/AC_CAT_NNN_Test.php` with fixtures for both triggering and non-triggering cases, then validate:

```bash
./vendor/bin/phpunit --testsuite=rules
php bin/acqc rules:validate
```

---

## Roadmap

### v3.1 — Current ✅

- 112 YAML rules across 15 categories
- `init`, `doctor`, `scan`, `report`, `audit`, `upgrade:analyze`, `export`, `rules:*` commands
- 8-dimension HTML score dashboard with density-based scoring
- Interactive violations table (500-row cap + CSV/JSON export)
- Dark/light mode HTML reports with clickable category chart
- Suppression management with review dates
- SARIF 2.1.0, JUnit, PDF, Excel export
- GitHub Actions, GitLab CI, Bitbucket Pipelines support

### v3.2 — CI/CD & Team Integrations

- GitHub Actions marketplace action (`adobe-commerce-quality/acqc-action`)
- GitLab CI native Code Quality JSON format
- Slack / Teams notifications
- Jira and GitHub Issues integration for critical violations
- Per-developer violation attribution via `git blame`

### v3.3 — IDE Integration

- VS Code extension — inline squiggles, hover cards, Quick Fix lightbulb, on-save lint
- JetBrains / PHPStorm plugin — inspection provider, intention actions
- `acqc hooks install` — git pre-push and post-merge hook installer

### v4.0 — Central Server & Dashboard

- Web dashboard for visualizing scan history and multi-project metrics
- `acqc server start` — REST + WebSocket central server
- Org-level overview: all projects, health scores, real-time scan updates
- Docker support and SaaS deployment guide

---

## Troubleshooting

### `Class not found` errors after install

```bash
composer dump-autoload
```

### Memory exhaustion on large codebases

```bash
php -d memory_limit=-1 bin/acqc report app/code --format=html
```

### `PHPCS: Magento2 standard not found`

```bash
./vendor/bin/phpcs --config-set installed_paths vendor/magento/magento-coding-standard
./vendor/bin/phpcs -i    # should list "Magento2"
```

### PHPStan reports "not available"

PHPStan is optional. Install and enable when needed:

```bash
composer require --dev phpstan/phpstan
```

Then in `.acqc/project.yaml`:

```yaml
analyzers:
  phpstan:
    enabled: true
    level: 5
```

### `doctor` reports CI integration failure

```bash
php bin/acqc init --ci=github      # .github/workflows/acqc.yml
php bin/acqc init --ci=gitlab      # .gitlab-ci.yml
php bin/acqc init --ci=bitbucket   # bitbucket-pipelines.yml
```

### Suppression debt warning in `doctor`

```bash
php bin/acqc rules:list --active-only
php bin/acqc rules:unsuppress AC_DI_001
```

---

## License

MIT — see [LICENSE](LICENSE.txt) for details.

---

*Adobe Commerce Quality Control (ACQC) v3.1.0 · 112 rules across 15 categories · Built on the Magento 2 coding standard.*
