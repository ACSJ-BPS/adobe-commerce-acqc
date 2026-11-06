# Rules

`acqc audit` checks code with its built-in analyzers and with YAML rules. This page covers the
YAML rules: where they come from, how to list, suppress and write them, and what they can check.

`report`, `scan` and `analyze` do not use YAML rules.

## Where rules come from

ACQC loads rules in this order. A rule with the same `id` as an earlier one replaces it, so a team
file can override a bundled rule.

| Order | Folder | Notes |
|---|---|---|
| 1 | ACQC `config/rules/global/` | Bundled |
| 2 | ACQC `config/rules/adobe-commerce/` | Bundled, always loaded |
| 3 | ACQC `config/rules/<bundle>/` | One folder per entry in `rule_bundles` in `.acqc/project.yaml` |
| 4 | `<project>/.acqc/rules/team/` | Team rules. Commit them with the project. |
| 5 | `~/.acqc/rules/personal/` | Your own rules. Not loaded by the `ci` profile. |

Every folder is read recursively for `*.yaml` and `*.yml` files. After loading:

1. Suppressions from `.acqc/project.yaml` switch off bundled and team rules.
2. Only rules whose `roles` list contains the profile name stay. `tech-lead` matches the role
   `tech_lead`.
3. Rule files that fail validation are skipped without a message. Check new files with
   `acqc rules:validate`.

`<project>` is the path you pass to `audit` (the git root with `--staged` and `--diff-from`). Run
`acqc audit .` from the project root so that team rules and suppressions apply.

## List rules

```bash
acqc rules:list                                   # rules for the developer profile
acqc rules:list --profile=ci                      # rules for another profile
acqc rules:list --category=security --severity=critical
acqc rules:list --active-only                     # hide suppressed rules
acqc rules:list --format=csv > rules.csv
```

`rules:list` reads `.acqc/` from the current directory, so run it from the project root. It shows
every loaded rule, including rules whose check type does not report findings (see
[What a rule can check](#what-a-rule-can-check)).

The `pre-commit` and `executive` profiles have no YAML rules today: no bundled rule lists them in
`roles`, and `pre_commit` is not an allowed role value.

## Suppress a rule

Suppressions are stored per project in `.acqc/project.yaml`. Run the commands from the project
root; `acqc init` must have created the file first.

```bash
acqc rules:suppress AC_PERF_001 --reason="Legacy import, rewrite planned" --review-by=2026-12-31
acqc rules:unsuppress AC_PERF_001
```

The entry records the reason, your git user email (or system user) and the date. `doctor` reports
suppressions whose `review_by` date has passed; the suppression stays active after that date.

Both commands rewrite the whole `.acqc/project.yaml`: comments and formatting are lost. When the
file is not valid YAML, `rules:suppress` replaces all of it with only the `suppressions` list.
Commit the file before you run them. If you edit the file by hand, use the list form shown in
[Project config](project-config.md#suppressions); a map is ignored without a message.

Known issue: `audit` and `rules:list` cache the loaded rules for up to one hour in the system temp
folder, and a suppression change does not clear that cache. To apply the change at once, delete
the cache:

```bash
rm -rf "$(php -r 'echo sys_get_temp_dir();')/acqc_cache"
```

## Write a team rule

1. Create a YAML file in `.acqc/rules/team/` (any subfolder and file name).
2. Validate it: `acqc rules:validate .acqc/rules/team`
3. Check that it loads: `acqc rules:list --category=<your category>`
4. Run it: `acqc audit .`

Example: a regex rule.

```yaml
schema_version: "1.0"
id: "TEAM_DEBUG_001"
name: "team-debug-001-var-dump"
title: "Debug output left in code"
description: "var_dump() and print_r() calls must not be committed."
category: "code_quality"
severity: "error"
tier: 2
roles: [developer, tech_lead, ci]
platforms: [cli, ci, sarif]
introduced_in: "1.0.0"
globs:
  - "**/*.php"
  - "**/*.phtml"
check:
  type: regex
  pattern: '\b(var_dump|print_r)\s*\('
recommendation: "Remove the debug call. Use the PSR-3 logger for diagnostics."
examples:
  bad: "var_dump($order);"
  good: "$this->logger->debug('Order loaded', ['id' => $order->getId()]);"
```

Example: an XPath rule for XML files.

```yaml
schema_version: "1.0"
id: "TEAM_DI_001"
name: "team-di-001-core-preference"
title: "Preference on a core class"
description: "Replacing a core class with a preference conflicts with other modules. Use a plugin."
category: "dependency_injection"
severity: "warning"
tier: 2
roles: [developer, tech_lead, ci]
platforms: [cli, ci, sarif]
introduced_in: "1.0.0"
globs:
  - "**/etc/di.xml"
check:
  type: xml_xpath
  expression: "//preference[starts-with(@for, 'Magento\\')]"
  attribute: "for"
recommendation: "Use a plugin (interceptor) or an event observer instead of a preference."
examples:
  bad: '<preference for="Magento\Catalog\Model\Product" type="Vendor\Module\Model\Product"/>'
  good: '<type name="Magento\Catalog\Model\Product"><plugin name="vendor_product" type="Vendor\Module\Plugin\ProductPlugin"/></type>'
```

`acqc rules:new` creates a scaffold too, but it writes into the ACQC `config/rules/` folder, not
into your project. Use it when you add a rule to ACQC itself.

## Rule fields

| Field | Required | Allowed values |
|---|---|---|
| `schema_version` | Yes | `"1.0"` |
| `id` | Yes | `PREFIX_CATEGORY_NNN`: 2-5 capital letters, 2-8 capital letters, 3 digits. Example: `AC_DI_001`. |
| `name`, `title`, `description` | Yes | Text. `title` is the finding message. |
| `category` | Yes | Text, used for filtering and grouping. |
| `severity` | Yes | `blocker`, `critical`, `error`, `warning`, `info`, `minor` |
| `tier` | Yes | `1`, `2` or `3` |
| `roles` | Yes | `developer`, `tech_lead`, `architect`, `executive`, `ci` |
| `platforms` | Yes | List. Allowed values include `cli`, `ci`, `sarif`, `junit` and `vscode`. Checked by `rules:validate`; `audit` does not use it. |
| `introduced_in` | Yes | Version text, for example `"1.0.0"` |
| `globs` | Tier 1 and 2 | File patterns. `**` matches any number of folders. A rule without `globs` applies to every file. |
| `check` | Tier 1 and 2 | See [What a rule can check](#what-a-rule-can-check). |
| `examples` | Tier 1 and 2 | `bad` and `good` code samples |
| `recommendation` | Tier 1 and 2 | How to fix |
| `tags`, `references` | No | Lists |
| `deprecated_in`, `replaced_by` | No | A rule with `deprecated_in` does not run. |

## What a rule can check

| `check.type` | Produces findings | How it works |
|---|---|---|
| `regex` | Yes | `pattern` is tested against each line of the file. Every matching line is one finding. |
| `xml_xpath` | Yes | `expression` is an XPath 1.0 query. Every matched node is one finding. With `attribute`, that attribute's value is added to the message. A file that is not valid XML gives one `warning` finding. |
| `guidance` | No | Documentation only. A rule without `check` counts as `guidance`. |
| `php_ast`, `json_path`, `structural`, `composite` | No | Accepted by the validator, but ACQC has no checker for them yet, so these rules never report. |

Regex details:

- The pattern is wrapped as `~pattern~` with no flags. Escape a literal `~` as `\~`. Use `(?i)` at
  the start for case-insensitive matching.
- Each line is tested on its own, so a pattern cannot match across lines.
- An invalid pattern reports nothing. `rules:validate` does not test the pattern, so run the rule
  on a sample file before you commit it.

File selection: `audit` only reads `*.php`, `*.phtml`, `*.graphqls` and `*.xml` files, and skips
folders such as `vendor`, `generated`, `var`, `node_modules` and `pub/static`. A rule whose
`globs` target other file types does not run.

## Policies

`acqc policy:check` is a separate, simpler way to add team checks without the rule schema. See
[Commands](commands.md#policycheck).
