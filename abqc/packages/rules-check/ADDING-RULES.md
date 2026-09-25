# Adding automatable rules

This guide explains how to add a **deterministic, non-AI** rule to the App Builder Quality Check library. Automatable rules are enforced by `@abqc/rules-check` and run automatically in `abqc review` (Phase 1).

## Quick reference

| Check type | When to use | Code changes needed |
|---|---|---|
| `regex` | Pattern in source text | None — markdown only |
| `yaml-path` | App Builder YAML config conditions | Extend condition enum only for new YAML semantics |
| `builtin` | AST logic, repo scans | Implement checker + register id |

## Step 1 — Add the rule markdown

Edit the appropriate file under `rules/<domain>/<topic>.md`:

```markdown
## My rule title [error] [tag1, tag2]
One-paragraph rationale explaining why this matters.

### Check
```yaml
type: regex
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
pattern: "yourPattern"
strip: [strings, comments]
ignore: ["allowed exception"]
```

### Bad
\`\`\`js
// counterexample
\`\`\`

### Good
\`\`\`js
// preferred pattern
\`\`\`
```

Run validation:

```bash
npm run build
node packages/cli/dist/bin.js rules validate
```

## Step 2 — Choose a check type

### Regex (`type: regex`)

Best for simple text patterns (eqeqeq, deprecated APIs).

```yaml
type: regex
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
pattern: "(?<![=!])==(?!=)|!=(?!=)"
ignore: ["== null", "!= null"]
strip: [strings, comments]
```

### YAML path (`type: yaml-path`)

Best for App Builder config files. Built-in conditions:

| `condition` | Checks |
|---|---|
| `web-timeout` | Web actions with timeout > 60s |
| `memory-tier` | Memory not in 256/512/1024 MB |
| `final` | Missing `annotations.final: true` |
| `relative-path` | Absolute action `function:` paths |
| `include-large` | `app.config.yaml` over line limit without `$include` |

```yaml
type: yaml-path
condition: final
files: ["**/*.{config.yaml,yaml}", "**/actions.config.yaml"]
```

To add a **new condition**, extend `YamlPathCondition` in `packages/rules-check/src/checkers/spec.ts` and add handling in `type-checkers/yaml-path.ts`.

### Builtin (`type: builtin`)

Best for AST analysis or repo-level scans.

```yaml
type: builtin
id: my-checker-id
files: ["**/actions/**/*.{js,cjs,mjs,ts}"]
scope: file   # or repo
```

1. Implement in `packages/rules-check/src/checkers/builtins/` (group by domain).
2. Register in `packages/rules-check/src/checkers/type-checkers/builtin.ts` (`FILE_BUILTINS` or `REPO_BUILTINS`).
3. Add the id to `BUILTIN_IDS`.

## Step 3 — Add Good/Bad fixtures

Every automatable rule **must** have behavioral tests in:

```
packages/rules-check/src/test/fixtures/
  bad-<topic>.js|yaml
  good-<topic>.js|yaml
```

Add cases to `packages/rules-check/src/test/automatable-rules.test.ts`.

Run tests:

```bash
npm run test --workspace=@abqc/rules-check
```

## Step 4 — Regenerate coverage

```bash
node packages/rules-check/dist/bin.js coverage
```

The rule should appear under **automatable** in `packages/rules-check/COVERAGE.md`.

## Architecture

```
rules/*.md (### Check YAML)
  → RuleLoader.checkSpec (@abqc/core)
  → parseCheckSpec + TypeRegistry (@abqc/rules-check)
      ├── regex        → type-checkers/regex.ts
      ├── yaml-path    → type-checkers/yaml-path.ts
      └── builtin      → checkers/builtins/* + type-checkers/builtin.ts
  → ESLint layer + dedup
  → findings (source: mechanical | eslint)
```

## Commands

| Command | Purpose |
|---|---|
| `abqc review <paths>` | Mechanical + ESLint + optional `--ai` LLM judgment |
| `abqc review --no-mechanical` | Skip declarative checks (ESLint-only legacy path) |
| `abqc rules validate` | Validate all `### Check` specs |
| `abqc-rules-check check <paths>` | Mechanical-only channel (no LLM) |
| `abqc-rules-check coverage` | Regenerate COVERAGE.md |

## ESLint overlap

When ESLint already enforces a rule (eqeqeq, no-new-buffer), map the ESLint rule id in `packages/rules-check/src/eslint-map.ts`. Mechanical findings on the same rule+line are deduplicated in favor of ESLint.
