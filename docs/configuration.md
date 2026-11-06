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
| `audit` | Rules and suppressions: the `<path>` you pass, or the git root with `--staged` or `--diff-from`. Scoring: like `report`. Profiles: the folder of the nearest `.acqc/project.yaml` above the current folder (up to the git root), else the current folder. |
| `report` | The `<path>` you pass or, inside git, the nearest parent folder with `.acqc/project.yaml`, up to the git root |
| `policy:check` | The `<path>` you pass |
| `init`, `doctor`, `rules:list`, `rules:suppress`, `rules:unsuppress`, `rules:sync` | The current folder |

So run `acqc audit .` from the project root. `acqc audit app/code` does not apply the team rules or
suppressions in `<project>/.acqc/`.

## .acqc/project.yaml

`acqc init` creates this file. Commit it. Every key is optional:

| Key | Purpose |
|---|---|
| `project.name` | Project name in `report` titles and file names. |
| `rule_bundles` | Extra rule folders under ACQC `config/rules/` for `audit`. The `global` and `adobe-commerce` folders always load. |
| `suppressions` | YAML rules switched off for this project. Manage them with `acqc rules:suppress` and `acqc rules:unsuppress`. |
| `report` | Default filters for `acqc report`. Command-line options win. |
| `analyzers` | Turn `report` analyzers on or off. |
| `scoring` | Overrides for scores, grades, the release verdict, debt and gates. The defaults are in `config/scoring.yaml`. |

Every key, its values, the score formulas and worked examples are in
[Project config](project-config.md).

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
