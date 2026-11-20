# Project config: control findings and scores

`.acqc/project.yaml` is the per-project settings file. Commit it, so the whole team and CI use
the same settings. It controls two things:

- **Which findings you see**: rule bundles, suppressions, report filters and analyzer switches.
- **How findings become scores**: the Overall score, the score rings, the grade, the release
  verdict (READY TO SHIP, RISKY, BLOCKED), technical debt, the Excel KPIs and the JUnit gates.

Every key is optional. Without the file, ACQC uses its built-in defaults. The scoring defaults are
in `config/scoring.yaml` in the ACQC folder; that file also explains each key in its comments.

## Quick answers

| I want to | Set in `.acqc/project.yaml` | Applies to |
|---|---|---|
| Hide categories in the report, for example SEO | `report.exclude_categories` | `report` |
| Hide low severities in the report | `report.min_severity` | `report` |
| Turn off a report analyzer | `analyzers.<id>: false` | `report` |
| Turn off a YAML rule for this project | `suppressions` (use `acqc rules:suppress`) | `audit` |
| Count a noisy category less in scores | `scoring.category_weights` | `report`, `audit` |
| Change when the verdict is BLOCKED or RISKY | `scoring.readiness` | `report` |
| Limit the Overall score when there are serious findings | `scoring.overall.caps` | `report` |
| Make the Overall score react to project size | `scoring.overall.normalize` | `report` |
| Change the `audit` score | `scoring.audit` | `audit` |
| Change the grade letters and labels | `scoring.grades` | `report`, `audit` |
| Add a JUnit quality gate for CI | `scoring.gates` | `report --format=junit` |
| Remove an Excel KPI row or a score ring | `scoring.kpis.<id>: null`, `scoring.dimensions.<id>: null` | `report` |
| Set the project name in report titles and file names | `project.name` | `report` |
| Change what fails the `audit` gate | Not in this file: use the profile `fail_on` or `--fail-on` (see [Configuration](configuration.md#profiles)) | `audit` |

The `audit` score never decides the `audit` gate. The gate fails when there is a finding with a
`fail_on` severity, whatever the score.

## Which command reads which key

| Key | `report` | `audit` | Other commands |
|---|---|---|---|
| `project.name`, `name` | Yes | No | |
| `rule_bundles` | No | Yes | `rules:list`, `rules:sync`, `doctor` |
| `suppressions` | No | Yes | `rules:list`, `rules:suppress`, `rules:unsuppress`, `doctor` |
| `report` | Yes | No | |
| `analyzers` | Yes | No | |
| `scoring` | Yes, all keys | Only `audit`, `grades`, `category_weights` and `severity_aliases` | |

`report` does not run the YAML rules, so rule bundles and suppressions do not change it.

## Where ACQC looks for the file

| Command | Which `.acqc/project.yaml` |
|---|---|
| `report` (all keys) and `audit` (`scoring`) | The one in the folder you scan. If there is none and the folder is inside a git repository, the nearest one in a parent folder, up to the git root. Outside git, only the scanned folder. |
| `audit` (`rule_bundles`, `suppressions`) | Only `<path>/.acqc/` for the `<path>` you pass, or the git root with `--staged` or `--diff-from`. |
| `init`, `doctor`, `rules:*` | The current folder. |

So `acqc audit app/code` uses the scoring from the project root file, but not its suppressions or
team rules. Run `acqc audit .` from the project root. See also
[Configuration](configuration.md#where-settings-live).

When a setting exists in more than one place, this order wins:

1. The command-line option, for example `--min-severity` or `--project-name`.
2. `.acqc/project.yaml`.
3. The ACQC defaults (`config/scoring.yaml` for scoring).

To check which file `report` used:

- HTML, Excel and PDF: the console shows
  `Scoring config: config/scoring.yaml + <path>/.acqc/project.yaml`.
- JSON: read `data.scores.config_sources`.

The project file is listed only when it has a `scoring:` section. JSON, SARIF and JUnit print no
progress lines, so filter warnings are also shown only for HTML, Excel and PDF.

## A complete example

```yaml
project:
  name: Shell B2B                     # report title and file name prefix

suppressions:                         # managed by acqc rules:suppress / rules:unsuppress
  - rule_id: AC_DI_001
    reason: Legacy module, rewrite planned
    review_by: '2026-12-31'

report:                               # default filters for acqc report
  min_severity: warning
  exclude_categories: [seo, accessibility]

analyzers:                            # report analyzers to skip
  performance_server_uptime: false
  performance_opcache: false

scoring:
  category_weights:
    documentation: 0.5                # a documentation finding counts half
  overall:
    caps:
      - { when: { blocker: ">= 1" }, max_score: 40 }
      - { when: { critical: ">= 1" }, max_score: 60 }
  readiness:                          # first match wins
    - { verdict: BLOCKED, gate: FAIL, when_any: { blocker: "> 0" } }
    - { verdict: RISKY, gate: WARN, when_any: { critical: "> 0", error: "> 20", overall: "< 75" } }
    - { verdict: READY TO SHIP, gate: PASS }
  gates:
    - { name: max_errors, when: { error: "> 50" } }
```

## Project name and other keys

`acqc init` writes this file:

```yaml
schema_version: '1'
acqc_version: '>=3.0.0'
project:
  platform: unknown
  platform_version: unknown
  php_version: '8.4'
rule_bundles:
  - adobe-commerce
suppressions: {  }
```

| Key | What it does |
|---|---|
| `project.name` | Project name in `report` titles and file names. `Shell B2B` gives the title `Shell B2B` and the file `shell-b2b-report_<timestamp>.json`. |
| `name` (top level) | Used when `project.name` is not set. |
| `schema_version`, `acqc_version`, `project.platform`, `project.platform_version`, `project.php_version` | Written by `init`. No command reads them today. |
| `thresholds` | An old key. It is ignored, without a message. Use `scoring.readiness`. |

`report` takes the first name it finds: `--project-name`, `project.name`, `name`, the `name` in
the target's `composer.json` (the part after `/`), the `origin` git remote, then the folder name.

## rule_bundles

A list of folders under ACQC `config/rules/` to load for `audit`. ACQC ships two folders,
`global` and `adobe-commerce`, and both always load. So this key changes nothing today unless you
add a folder to ACQC. A name with no folder is skipped without a message.

## suppressions

Switches off YAML rules for this project. `audit` then does not run them.

```yaml
suppressions:
  - rule_id: AC_DI_001
    reason: Legacy module, rewrite planned
    review_by: '2026-12-31'
```

- Use the list form above. A map (`suppressions: { AC_DI_001: { reason: ... } }`) is ignored
  without a message.
- Only YAML rules can be suppressed. Find their IDs with `acqc rules:list`. Findings from the
  built-in analyzers have no rule ID.
- `review_by` does not end the suppression. `doctor` lists suppressions whose date has passed.
- Prefer the commands, run from the project root:
  `acqc rules:suppress AC_DI_001 --reason="Legacy module" --review-by=2026-12-31`. They also record
  `suppressed_by` (your git email) and `suppressed_at`.

Both commands rewrite the whole file: comments and formatting are lost. When the file is not valid
YAML, `rules:suppress` replaces all of it with only the `suppressions` list. Commit the file before
you run them. More in [Rules](rules.md#suppress-a-rule), including the rule cache.

## report

Default filters and PHPUnit execution settings for `acqc report`. A command-line option replaces
the matching key.

```yaml
report:
  run_phpunit: false                    # skip automatic PHPUnit execution
  min_severity: warning                 # minor | info | warning | error | critical | blocker
  exclude_categories: [seo, accessibility]
```

| Key | Command-line option | Values |
|---|---|---|
| `run_phpunit` | `--run-coverage`, `--phpunit-binary` | Boolean; defaults to `true`. `false` disables automatic PHPUnit execution. Either explicit execution option overrides `false`. Existing coverage files are still read. |
| `min_severity` | `--min-severity` | Keep this severity and higher: `minor`, `info`, `warning`, `error`, `critical`, `blocker`. |
| `min_impact` | `--min-impact` | `low`, `medium`, `high`, `critical` = severity `warning`, `error`, `critical`, `blocker`. Used only when no minimum severity is set. |
| `exclude_categories` | `--exclude-categories` | Categories to drop. |
| `exclude_types` | `--exclude-types` | Finding types to drop. |
| `include_types` | `--include-types` | Keep only these finding types. |

- Lists can be YAML lists or comma-separated strings: `exclude_categories: "seo, accessibility"`.
- The categories in a report are in the JSON at `data.violations.by_category`.
- An unknown severity, for example `high`, prints
  `Invalid severity level: high. Ignoring severity filter.` and the report keeps all severities.
- Filters run before scoring. Hidden findings do not count in any score, the verdict or debt.
- There is no key for `--exclude-paths`; pass it on the command line.

## analyzers

Set an analyzer to `false` to skip it in `report`. `audit` ignores this section.

```yaml
analyzers:
  frontend_seo: false
  php_phpstan: false
```

| Group | IDs (all on by default unless noted) |
|---|---|
| Adobe Commerce | `magento_security`, `magento_performance`, `magento_di`, `magento_database`, `magento_plugin`, `magento_observer`, `magento_structure`, `magento_upgrade`, `magento_api_service`, `magento_graphql` |
| Performance and infrastructure | `performance_layout_cache`, `performance_opcache`, `performance_varnish`, `performance_redis`, `performance_api_rate_limiting`, `performance_server_uptime`, `performance_indexers`, `performance_http_protocol`, `infrastructure_cdn` |
| Frontend | `frontend_security`, `frontend_performance`, `frontend_accessibility`, `frontend_seo`, `frontend_pwa`, `frontend_react` |
| PHP | `php_dead_code`, `php_code_health`, `php_phpcs`, `php_phpmd`, `php_phpstan`, `php_phpcpd`, `php_sonar` (off by default) |

- A misspelled ID is ignored without a message.
- When the target is not an Adobe Commerce project, `performance_server_uptime` and
  `performance_opcache` are off. Set them to `true` here to force them on.
- Some analyzers read `app/etc/env.php` and call the store URL. See
  [Reports](reports.md#what-report-reads-besides-your-code).
- Disable environment checks through this section and automatic PHPUnit execution with
  `report.run_phpunit: false`. External tools remain optional.

## How the scoring section is merged

Your `scoring:` section is merged over `config/scoring.yaml`:

| You write | Result |
|---|---|
| A map, for example `overall.weights`, `bands`, `dimensions.<id>`, `kpis.<id>` | Merged key by key. Write only the keys you change. |
| A list: `readiness`, `caps`, `grades`, `tiers`, `gates`, `assessment`, `categories`, `rule_ids`, `terms` | Replaces the whole default list. Copy all entries when you change one. |
| `null`, for example `kpis: { business_risk: null }` | Removes that key. |

ACQC checks the merged result before it runs. A mistake stops `report` and `audit` with exit code
`2` and the exact path, for example:

```text
[ERROR] Configuration error: Unknown scoring condition input "erorr" at scoring.readiness[0].when_any.erorr.
```

## Conditions and inputs

Caps, readiness rules, gates and KPI terms use conditions written as `<input>: "<op> <number>"`.
The operators are `>`, `>=`, `<`, `<=`, `==` and `!=`. Example: `error: "> 50"`. Nothing else is
allowed in the string: no `and`, `or` or formulas.

| Input | Meaning |
|---|---|
| `blocker`, `critical`, `error`, `warning`, `info`, `minor`, `total` | Number of findings. Each finding counts 1. |
| `<severity>_weighted`, `total_weighted` | Each finding counts its `category_weights` value. |
| `<severity>_pct` | Share of all findings, 0 to 100, from weighted counts. |
| `<severity>_per_file`, `<severity>_per_kloc` | Weighted count per file and per 1,000 lines. |
| `security`, `performance`, `maintainability`, `deprecation`, `api`, `logic`, `documentation`, `frontend`, `seo`, `accessibility`, and every category named in `category_weights` or in a ring's `categories` | Number of findings in that category, with the same suffixes: `_weighted`, `_pct`, `_per_file`, `_per_kloc`. |
| `<ring id>_score`, for example `reliability_score` | A score ring value. Only known after the rings are calculated; in ring caps it is always 100. |
| `overall`, `debt_hours`, `files`, `kloc`, `maintainability_index` | Final Overall score (after caps), debt hours, file count, thousands of lines, maintainability index. |

A category that is only found in the scan is not an input. Using it stops `report` and `audit`
with exit code `2` (`Unknown scoring condition input`). Name it first, for example
`category_weights: { my_category: 1 }` (weight 1 keeps scores unchanged). This also applies to the
`_per_file` and `_per_kloc` forms of the ten categories above when you remove the ring that lists
them.

Use raw counts (`error`) for "the real number of findings". Use `_weighted` counts when noisy
categories should count less.

## severity_aliases and category_weights

```yaml
scoring:
  severity_aliases: { high: critical, medium: warning, low: info }   # the defaults
  category_weights: { seo: 0.25, accessibility: 0.25 }               # the defaults
```

- `severity_aliases` maps other severity names to the six ACQC severities. Any other unknown
  severity counts as `info`. `audit` uses `scoring.audit.severity_aliases` first.
- `category_weights` sets how much one finding of a category counts in scores. Any category not
  listed counts `1`. `0` removes the category from scores but keeps the findings in the report.
  It also changes the `audit` score.
- Raw-count inputs such as `error` ignore the weights. Debt ignores them unless
  `debt.apply_category_weights: true`.

To drop findings from the report itself, use `report.exclude_categories`.

## Overall score (report)

The Overall score starts at 100 and loses points per finding:

```text
penalty = sum over severities of: normalize(weighted count of that severity) x weight
score   = 100 - penalty, rounded to 1 decimal, kept between floor and 100, then caps
```

No findings means 100. The defaults:

| Key | Default |
|---|---|
| `overall.weights` | `blocker: 15`, `critical: 6`, `error: 2.5`, `warning: 0.6`, `info: 0.1`, `minor: 0.03` |
| `overall.normalize` | `absolute` |
| `overall.per` | `1000` |
| `overall.floor` | `0` |
| `overall.caps` | none |

| `normalize` | The count is divided by |
|---|---|
| `absolute` | `per` (when `per` is more than 1) |
| `per_file` | Number of files |
| `per_kloc` | Thousands of lines of code |
| `share_of_total` | Weighted total of all findings, then x 100 |

With the defaults, the Overall score moves very little: 1,000 warnings cost 0.6 points, and
40 errors plus 300 warnings plus 100 SEO warnings still give 99.7 (A+). To make it react, use the
release verdict, add caps, or normalize by size. The same 440 findings with `normalize: per_kloc`
on 50,000 lines give 94.1 (A).

Caps set a maximum score. A cap applies when any one of its conditions is true. When several apply,
the lowest `max_score` wins. Each applied cap is listed in `data.scores.caps_applied`, for example
`Capped at 70 (warning = 2 (>= 2))`.

```yaml
scoring:
  overall:
    caps:
      - { when: { blocker: ">= 1" }, max_score: 40 }
      - { when: { critical: ">= 1", error: "> 50" }, max_score: 60 }
```

## Score rings (dimensions)

The HTML report shows one ring per dimension. Each finding goes to the first ring whose `rule_ids`
pattern matches its rule ID; otherwise to the first ring whose `categories` list has its category.
Findings that match no ring are listed in `data.scores.unmapped_categories` and still count in the
Overall score.

```text
ring = 100 - normalize(count) x mult, rounded, never below floor
```

| Ring | Counts | `normalize` | `mult` | `floor` |
|---|---|---|---|---|
| `reliability` | Severities: blocker x 15, critical x 8, error x 2 | `share_of_total` | 1 | 0 |
| `security` | Categories `security`, `xss`, `injection`, `csrf`, `cryptography`, `authentication`, `access_control`, `session_management`, `input_validation`, `information_disclosure`, `file_handling`; rule IDs `ac-http-005-*` | `absolute` | 8 | 0 |
| `performance` | Categories `performance`, `caching`, `memory`, `loops`, `indexing`, `io_operations`, `algorithms`, `async`, `cron`, `http`, `database` | `share_of_total` | 2.5 | 15 |
| `maintainability` | Categories `maintainability`, `complexity`, `documentation`, `architecture`, `dependency_injection`, `di`, `object_manager`, `plugins`, `observers`, `module_structure`, `schema`, `php_best_practices`, `style`, `testing`, `configuration`, `layout_xml`, `templates` | `share_of_total` | 2 | 25 |
| `compliance` | Categories `deprecation`, `deprecated`, `cloud`; rule IDs `magento-upgrade-deprecated-*`, `magento-deprecated-pattern` | `share_of_total` | 5 | 0 |
| `api_quality` | Categories `api`, `api-quality`, `api_usage`, `graphql` | `share_of_total` | 3 | 20 |
| `code_quality` | Categories `logic`, `quality`, `syntax`, `guidance`, `data_handling`, `javascript`, `css`, `frontend`, `responsive`; plus half the warnings (`severities: { warning: 0.5 }`) | `share_of_total` | 1.5 | 20 |

Example: a scan with 1 `performance` warning, 1 `logic` warning and 1 `documentation` info gives
Overall 100 but Performance 17 (1 of 3 findings = 33.3 % x 2.5 = 83 points off). A ring with
`share_of_total` shows the share of findings, not their number.

`bands` set the ring colours: at least `healthy` (80) is green, at least `attention` (60) is amber,
lower is red.

```yaml
scoring:
  dimensions:
    api_quality: null                   # remove a ring
    storefront:                         # add a ring
      label: Storefront
      subtitle: SEO and accessibility
      categories: [seo, accessibility]
      normalize: share_of_total
      mult: 2
      floor: 0
```

## Release readiness

The verdict is the most useful setting for managers. `report` checks the rules in order and uses
the first that matches.

| Key | Meaning |
|---|---|
| `verdict` | Text shown, for example `RISKY`. Required. |
| `gate` | `PASS`, `WARN` or `FAIL`. When missing: `FAIL` if the verdict contains BLOCK or FAIL, `WARN` if it contains RISK, WARN or REVIEW, else `PASS`. |
| `when_any` | Matches when any condition is true. |
| `when_all` | Matches when all conditions are true. With `when_any` too, both must match. |
| `summary` | Short text for the HTML report. |
| `excel_text` | Excel "Release Readiness" text. `{input}` is replaced, for example `{blocker}`. |

A rule without conditions always matches, so put it last. When no rule matches, the verdict is
`READY TO SHIP` / `PASS`. The defaults:

| Verdict | Gate | When |
|---|---|---|
| `BLOCKED` | `FAIL` | `blocker > 0` |
| `RISKY` | `WARN` | `critical > 0` or `overall < 65` |
| `READY TO SHIP` | `PASS` | Otherwise |

The reason lists the matched conditions, for example `warning = 2 (> 1); overall = 70 (< 75)`.
The verdict does not change the `report` exit code.

## Grades

The grade comes from the score. Both `report` and `audit` use this list; the first `min` the score
reaches wins.

| `min` | Grade | Label |
|---|---|---|
| 95 | A+ | Excellent |
| 85 | A | Excellent |
| 75 | B | Good |
| 65 | C | Fair |
| 50 | D | Poor |
| 0 | F | Needs Improvement |

```yaml
scoring:
  grades:
    - { min: 90, grade: A, label: Good }
    - { min: 70, grade: B, label: Fair }
    - { min: 0,  grade: C, label: Poor }
```

## Audit score

`audit` has its own score under `scoring.audit`. It is a whole number.

```text
error penalty   = min(50, weighted errors / files x 10)
warning penalty = min(30, weighted warnings / files x 5)
score           = 100 - penalties, then caps: any blocker -> max 40, any critical -> max 60
```

- `high`, `medium` and `low` count as `error`, `warning` and `info`
  (`scoring.audit.severity_aliases`).
- `info` and `minor` findings do not lower the score. `blocker` and `critical` lower it through the
  caps.
- `files` is the number of files analyzed. With no files and no findings, the score is
  `empty_scope_score` (100).

| Scan | Score |
|---|---|
| 10 files, 4 errors, 6 warnings | 93 (A) |
| The same plus 1 critical | 60 (D), capped |
| 10 files, 4 `high` findings | 96 (A+) |

```yaml
scoring:
  audit:
    overall:
      weights:
        error: { mult: 20, cap: 60 }    # errors cost twice as much
      caps:
        - { when: { blocker: ">= 1" }, max_score: 30 }
        - { when: { critical: ">= 1" }, max_score: 50 }
```

## Technical debt

```text
hours = sum of (findings of each severity x hours for that severity), then tiers
```

Hours per finding: `blocker: 8`, `critical: 2`, `error: 1`, `warning: 0.5`, `info: 0.17`,
`minor: 0.083`. Tiers reduce large totals: above 5,000 hours x 0.1, above 2,000 x 0.3, above
1,000 x 0.5.

| `debt.scaling` | How tiers apply | 1,001 h | 4,999 h | 5,013 h |
|---|---|---|---|---|
| `whole_total` (default) | The factor of the highest tier passed applies to the whole total | 500.5 | 1,499.7 | 501.3 |
| `marginal` | Each factor applies only to the hours above its tier, like tax brackets | 1,000.5 | 2,399.7 | 2,401.3 |

Use `marginal` so the hours never drop when findings are added. `debt.apply_category_weights: true`
makes debt use weighted counts.

## JUnit gates

`report --format=junit` writes a `QualityGates` group with these test cases:

1. `release_readiness`: fails only when the readiness gate is `FAIL`.
2. Each entry in `scoring.gates` (none by default).
3. Always: `no_critical_violations` (`critical == 0`), `minimum_quality_score` (`overall >= 65`)
   and `maximum_technical_debt` (`debt_hours <= 500`).

```yaml
scoring:
  gates:
    - { name: max_errors, when: { error: "> 50" } }          # when = when the gate FAILS
    - { name: big_and_risky, mode: all, when: { error: "> 20", security: "> 0" } }
```

`mode` is `any` (default) or `all`. A failed gate shows as
`max_errors failed: error = 51 (> 50)`. `report` still exits `0`, so let your CI test view fail
the build. A `--quality-gates=<file.json>` file replaces all of these gates.

In the JSON report, `data.scores.gates` lists only `release_readiness` and your `scoring.gates`.
The three fixed gates and a `--quality-gates` file are checked only in the JUnit file.

## Excel KPIs, assessment and status

| Key | Controls |
|---|---|
| `kpis` | The nine rows of the Excel KPI scorecard. |
| `assessment` | The Excel "Overall Assessment" and "Business Impact" text, chosen by Overall score (`min`). |
| `status` | Good, Warning and Critical thresholds for status cells in the Security, Performance, Code Quality and Module Analysis sheets. |
| `placeholders` | `show_na` (default) shows N/A for metrics that were not measured; `legacy` shows old sample values. |

| KPI ID | Pass | Warn |
|---|---|---|
| `release_readiness` | `>= 80` | `>= 60` |
| `business_risk` | `<= 20` | `<= 50` |
| `quality_confidence` (= Overall score) | `>= 80` | `>= 65` |
| `security_health` | `>= 90` | `>= 70` |
| `maintainability_index` | `>= 70` | `>= 50` |
| `performance_stability` | `>= 80` | `>= 60` |
| `criticality` | `<= 5` | `<= 20` |
| `upgrade_readiness` | `>= 80` | `>= 60` |
| `customer_impact_risk` | `<= 15` | `<= 40` |

A KPI is either `source: <input>` or `base` plus `terms`. Each term adds `input x mult` (optionally
`cap`), or adds `add` when its `when` matches. `direction: deduct` subtracts the terms from `base`;
`accumulate` adds them. The value is kept between 0 and 100. Status is Pass when `pass` matches,
else Warn when `warn` matches, else Fail. `maintainability_index` shows N/A when no module was
measured. The full formulas are in `config/scoring.yaml`.

```yaml
scoring:
  kpis:
    business_risk: null                 # remove the row
    criticality:
      pass: "<= 10"                     # relax one threshold
```

## Where each score appears

| Output | What it shows |
|---|---|
| `report` HTML | Overall score and grade, score rings, readiness pill, debt |
| `report` Excel | Summary sheet (Quality Score, Release Readiness, Readiness Reason, Technical Debt), KPI scorecard, assessment, sheet status cells |
| `report` PDF | Quality Score, Release Readiness, Technical Debt |
| `report` JSON | `data.overview` (score, readiness, grade, debt) and `data.scores` (all values, inputs and `config_sources`) |
| `report` SARIF | Run `properties`: `score`, `readiness`, `gate` |
| `report` JUnit | The `QualityGates` test cases |
| `audit` | `score`, `grade`, `grade_label`, `caps_applied` (console and JSON) |

## Test a change

1. Run the report as JSON:
   `acqc report . --format=json -o build/acqc-check`
2. In the file, check `data.scores.config_sources` (your file is listed), then
   `data.scores.overall`, `caps_applied`, `readiness` and `gates`.
3. For `audit`: `acqc audit . --format=json` and read `score`, `grade` and `caps_applied`.

| Problem | What happens |
|---|---|
| Invalid scoring value, for example an unknown input, a bad operator such as `"=> 5"`, `normalize: per_line`, a negative weight, or `grades` written as a map | `report` and `audit` stop with exit code `2` and the config path in the message. `audit --format=json` prints `{"error": "..."}`. |
| The file is not valid YAML | `report` exits `1` with `Report generation failed: <parser message>`; `audit` exits `1` with the parser error. The message does not name the file. |
| Unknown key, misspelled analyzer ID, unknown rule bundle, `thresholds`, suppressions as a map | Ignored without a message. |

## Known limits

- Some mistakes are ignored without a message (see the table above). Check the result as shown in
  [Test a change](#test-a-change).
- `scoring:` written as a list (`- ...`) is not rejected. Write it as a map.
- `rules:suppress` and `rules:unsuppress` rewrite the file and remove comments.
- `audit app/code` uses the scoring from the project root file but not its suppressions.
- JSON, SARIF and JUnit runs do not print the `Scoring config` line or filter warnings.
