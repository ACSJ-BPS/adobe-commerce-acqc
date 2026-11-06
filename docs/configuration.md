# Configuration

ACQC works without any configuration. Use the files below when you want to share settings with your
team or change the defaults.

## Where settings live

| File | Scope | Used by | Commit it? |
|---|---|---|---|
| `.acqc/project.yaml` | Project | Rule bundles, suppressions, report filters, analyzer toggles, scoring | Yes |
| `.acqc/profiles/<name>.yaml` | Project | Overrides a built-in profile, for example `pre-commit` | Yes |
| `.acqc/rules/team/*.yaml` | Project | Team rules for `audit` (see [Rules](rules.md)) | Yes |
| `.acqc/policies/*.yaml` | Project | Policies for `policy:check` (see [Commands](commands.md#policycheck)) | Yes |
| `~/.acqc/profiles/<name>.yaml` | Your machine | Personal profile override | No |
| `~/.acqc/rules/personal/*.yaml` | Your machine | Personal rules for `audit` (not loaded by the `ci` profile) | No |
| `~/.acqc/policies/*.yaml` | Your machine | Personal policies for `policy:check` | No |
| `acqc.json` or `.acqc.json` in the current folder, or `audit -c <file>` | Run | `audit` file patterns and analyzer settings | Optional |

Each command looks for `.acqc/` in a different place:

| Command | Where it reads `.acqc/` |
|---|---|
| `audit` | Rules, suppressions and scoring: the `<path>` you pass, or the git root with `--staged` or `--diff-from`. Profiles: the folder of the nearest `.acqc/project.yaml` above the current folder (up to the git root), else the current folder. |
| `report` | The `<path>` you pass or, inside git, the nearest parent folder with `.acqc/project.yaml`, up to the git root |
| `policy:check` | The `<path>` you pass |
| `init`, `doctor`, `rules:list`, `rules:suppress`, `rules:unsuppress`, `rules:sync` | The current folder |

So run `acqc audit .` from the project root. `acqc audit app/code` does not apply the team rules or
suppressions in `<project>/.acqc/`.

## .acqc/project.yaml

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

| Key | Purpose |
|---|---|
| `rule_bundles` | Extra rule folders under ACQC `config/rules/` to load. The `adobe-commerce` bundle always loads. |
| `suppressions` | Rules switched off for this project. Manage them with `acqc rules:suppress` and `acqc rules:unsuppress`, not by hand. |
| `report` | Default filters for `acqc report`. Command-line options win. |
| `analyzers` | Turn `report` analyzers on or off. |
| `scoring` | Overrides for scores, grades and the release verdict. |

### report filters

```yaml
report:
  min_severity: warning                     # minor | info | warning | error | critical | blocker
  exclude_categories: [seo, accessibility]
```

| Key | Matching option | Notes |
|---|---|---|
| `min_severity` | `--min-severity` | Lowest severity to keep. |
| `min_impact` | `--min-impact` | `low`, `medium`, `high`, `critical` (= `warning`, `error`, `critical`, `blocker`). Used only when no minimum severity is set. |
| `exclude_categories` | `--exclude-categories` | Categories to drop. |
| `exclude_types` / `include_types` | `--exclude-types` / `--include-types` | Issue types to drop or to keep. |

A command-line option replaces the matching key. `report` reads `.acqc/project.yaml` in the path it
analyzes or, inside a git repository, in the nearest parent folder up to the git root. So in a git
project, `acqc report app/code` uses the file in the project root.

### analyzers

Set an analyzer to `false` to skip it in `acqc report`. Common examples:

```yaml
analyzers:
  frontend_seo: false
  frontend_accessibility: false
  php_phpstan: false
```

Other IDs: `magento_security`, `magento_performance`, `magento_di`, `magento_database`,
`magento_plugin`, `magento_observer`, `magento_structure`, `magento_upgrade`, `magento_api_service`,
`magento_graphql`, `performance_layout_cache`, `performance_opcache`, `performance_varnish`,
`performance_redis`, `performance_api_rate_limiting`, `performance_server_uptime`,
`performance_indexers`, `performance_http_protocol`, `infrastructure_cdn`, `frontend_security`,
`frontend_performance`, `frontend_pwa`, `frontend_react`, `php_dead_code`, `php_code_health`,
`php_phpcs`, `php_phpmd`, `php_phpcpd`.

### scoring

The built-in scoring model is in `config/scoring.yaml`. That file explains each key in its
comments. Your `scoring:` block is merged over it:

- Maps (`overall.weights`, `category_weights`, `bands`, `dimensions.<id>`, `kpis.<id>`, `status.*`) are
  deep-merged with the defaults.
- Lists (`readiness`, `caps`, `grades`, `tiers`, `gates`, `assessment`, `categories`, `rule_ids`, `terms`)
  replace the default list.

Example:

```yaml
scoring:
  category_weights:
    seo: 0.25
    accessibility: 0.25
  bands:
    healthy: 80
    attention: 60
  overall:
    normalize: absolute          # absolute | per_file | per_kloc | share_of_total
    per: 1000
    weights: { blocker: 15, critical: 6, error: 2.5, warning: 0.6, info: 0.1, minor: 0.03 }
    caps:
      - { when: { error_weighted: ">= 100" }, max_score: 75 }
  readiness:
    - { verdict: BLOCKED,       gate: FAIL, when_any: { blocker: "> 0", critical: "> 5" } }
    - { verdict: RISKY,         gate: WARN, when_any: { critical: "> 0", error_weighted: "> 50", overall: "< 75" } }
    - { verdict: READY TO SHIP, gate: PASS }
```

| Goal | Setting |
|---|---|
| Hide SEO and accessibility findings | `report: { exclude_categories: [seo, accessibility] }` |
| Count noisy categories less in scores | `scoring: { category_weights: { seo: 0, accessibility: 0.1 } }` |
| Mark more than N errors as RISKY | a `scoring.readiness` entry with `when_any: { error: "> N" }` |
| Cap the overall score when there are blockers | `scoring.overall.caps: [{ when: { blocker: ">= 1" }, max_score: 40 }]` |
| Scale scores by project size | `normalize: per_file` or `normalize: per_kloc` |
| Map rule IDs to a score dimension | `scoring.dimensions.compliance.rule_ids: ['magento-upgrade-deprecated-*']` |
| Grow technical debt hours more slowly for large projects | `scoring.debt.scaling: marginal` |
| Add a JUnit quality gate | `scoring.gates: [{ name: max_errors, when: { error: "> 50" } }]` |

The old `thresholds` key is ignored. ACQC prints a warning; use `scoring.readiness` instead.

## Profiles

A profile sets the output format, the minimum severity and the severities that fail the `audit`
gate. It also selects the YAML rules: a rule runs only when the profile name is in the rule's
`roles` list.

| Profile | Format | Min severity | `fail_on` | YAML rules |
|---|---|---|---|---|
| `developer` (default) | console | warning | blocker, critical, error | yes |
| `tech-lead` | console | warning | blocker, critical, error | yes |
| `architect` | json | info | blocker, critical | yes |
| `ci` | json | error | blocker, critical, error | yes |
| `executive` | json | error | none | none today |
| `pre-commit` | compact | error | blocker, critical, error | none today |

Check how many rules a profile runs with `acqc rules:list --profile=<name>`.

Personal rules in `~/.acqc/rules/personal/` load for every profile except `ci`. The `personal_rules`
key in profile files is not applied today.

ACQC loads the first profile file it finds, in this order:

1. `~/.acqc/profiles/<name>.yaml`
2. `<project>/.acqc/profiles/<name>.yaml`
3. ACQC `config/profiles/<name>.yaml`

To change the pre-commit gate for your team, copy
`examples/pre-commit/.acqc/profiles/pre-commit.yaml` to `.acqc/profiles/pre-commit.yaml` in your
project and edit `fail_on`.

The `magento-*` and `ci-pipeline` files in `config/profiles/` are for `acqc analyze --profile`.
List them with `acqc analyze --list-profiles`.

## Environment variables

| Variable | Used by |
|---|---|
| `ACQC_SKIP`, `ACQC_FAIL_ON`, `ACQC_PROFILE`, `ACQC_STRICT`, `ACQC_BIN`, `ACQC_PHP` | The pre-commit hook. See [Pre-commit gate](pre-commit.md#per-commit-controls). |
| `ACQC_MAGENTO_PUBLIC_KEY`, `ACQC_MAGENTO_PRIVATE_KEY` | `version:sync` and `version:download`. If they are not set, ACQC reads `~/.composer/auth.json`. |
