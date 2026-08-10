# App Builder Best Practices — Index

A two-layer review system: **384 markdown judgment rules** in this directory + **~800 mechanical ESLint rules** documented in [eslint-config.md](eslint-config.md). Combined, the MCP server delivers **~1,180+ effective review rules** per `review_code` call (architecture: `../app-builder-code-review-mcp.pdf`, section 3 "Cross-Tool Aggregation").

Modeled after PHPCS (style/conventions) + PHPMD (complexity/smells) + PHPSTAN (correctness/type safety) for the JavaScript/TypeScript stack — but split between two layers so each does what it's good at:

- **Static analysis layer** (ESLint, in-process via `Linter.verify()`) — runs ~800 mechanical rules from 9 plugins, deterministic, fast, no LLM context cost.
- **Markdown judgment layer** (this directory) — 342 rules that need reasoning, Adobe-specific knowledge, or domain context the LLM applies on each review. Every domain is a subfolder of small, sub-topic-scoped files (~95 sub-files across 13 top-level domains (App Builder additionally nests 2 sub-product subfolders for API Mesh and Admin UI SDK)) so each file stays scannable.

The two layers merge into one `ReviewResult` per the architecture; the LLM never sees raw ESLint output.

## Command reference — running these rules

These rules are consumed by the engine in [`../packages/core/`](../packages/) and exposed through three channels. All commands take one or more `<path>` arguments and accept both `--flag=value` and `--flag value`. Shared exit codes: **0** clean · **1** warn-only · **2** error findings (or usage error) · **3** engine error.

### `abqc` — full review CLI (`../packages/cli/dist/bin.js`)

| Command | Description |
|---|---|
| `abqc review <path...>` | Review files/dirs. Mechanical (declarative `### Check`) + ESLint layers by default; LLM only with `--ai`. |
| `abqc rules list` | List all loaded rules. |
| `abqc rules get <ruleId>` | Print one rule's full text (rationale + Bad + Good). |
| `abqc rules validate` | Validate every `### Check` YAML spec; report automatable rule count. |
| `abqc --version` / `--help` | Version / help. |

**`abqc review` flags**

| Flag | Values / default | Description |
|---|---|---|
| `--ai`, `--llm` | off | Enable the markdown-judgment LLM layer (needs a provider / API key). |
| `--provider=<p>` | `claude-cli` \| `anthropic` \| `openai` \| `stub` | Pick the LLM provider; implies `--ai`. |
| `--static-only` | — | Force-disable the LLM layer even if a provider env var is set. |
| `--mechanical` / `--no-mechanical` | on | Toggle declarative mechanical checks. `--no-mechanical` = ESLint-only mode. |
| `--no-eslint` | ESLint on | Skip the ESLint layer (declarative checkers only). |
| `--diff=<ref>` | — | Only review files changed vs a git ref (e.g. `main`, `HEAD~1`). |
| `--format=<fmt>` | `terminal` (default) \| `json` \| `sarif` \| `github` | Output format. |
| `--severity=<lvl>` | `info` (default) \| `warn` \| `error` | Minimum severity to report. |

**`abqc rules list` flags** — `--domain=<d>` (exact domain, e.g. `appbuilder`), `--severity=<error\|warn\|info>`, `-v` / `--verbose` (also print title + tags).

### `abqc-rules-check` — mechanical-only checker (`../packages/rules-check/dist/bin.js`)

Runs the automatable rule subset (`### Check` + ESLint) with **zero LLM calls**.

| Command | Description |
|---|---|
| `abqc-rules-check check <path...>` | Run mechanical + ESLint (non-AI) checks. |
| `abqc-rules-check coverage [--out=PATH]` | Write rule coverage report (defaults to `../packages/rules-check/COVERAGE.md`). |
| `abqc-rules-check --version` / `--help` | Version / help. |

**`check` flags** — `--format=<terminal\|json\|sarif>` (default `terminal`), `--severity=<info\|warn\|error>` (default `info`), `--cwd=<dir>` (project root for relative/repo-scoped checks), `--eslint` / `--no-eslint`.

### MCP server tools (`../packages/mcp/dist/server.js`)

| Tool | Params | Description |
|---|---|---|
| `abqc_review_code` | `paths: string[]` (required), `diff?: string`, `include_contents?: boolean` | Run both deterministic layers; returns SARIF findings + the applicable rule subset per file. |
| `abqc_list_rules` | `domain?: string`, `severity?: "error"\|"warn"\|"info"` | List rules (domain prefix match, e.g. `appbuilder/api-mesh`). |
| `abqc_get_rule` | `id: string` (required) | Fetch one rule's full JSON (rationale + Bad + Good). |

### Environment variables

| Variable | Used by | Purpose |
|---|---|---|
| `ABQC_PROVIDER` | CLI | `anthropic` \| `openai` \| `claude-cli` \| `stub` — enables the LLM layer + picks the provider. |
| `ABQC_MODEL` | CLI | Model override for Anthropic / Claude-CLI (e.g. `claude-sonnet-4-6`). |
| `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` | CLI | Enable the respective provider automatically. |
| `ABQC_PROJECT_ROOT` / `MCP_ABQC_PROJECT_ROOT` | MCP | Repo root to scope reviews to when the host cwd differs. |
| `ABQC_RULES_DIR` / `MCP_ABQC_RULES_DIR` | core | Override the rules directory (deploying outside the monorepo). |

## How to use this collection

| If you are a... | Read |
|---|---|
| Developer writing an App Builder action | [appbuilder/](./appbuilder/) + [node/](./node/) + [security/](./security/) |
| Developer writing an API Mesh config | [appbuilder/api-mesh/](./appbuilder/api-mesh/) + [security/](./security/) |
| Developer writing a Commerce Admin UI SDK extension | [appbuilder/admin-ui-sdk/](./appbuilder/admin-ui-sdk/) + [react/](./react/) + [security/](./security/) |
| Developer writing the SPA frontend | [react/](./react/) + [a11y/](./a11y/) + [typescript/](./typescript/) |
| Developer using TypeScript anywhere | [typescript/](./typescript/) |
| Developer writing tests | [testing/](./testing/) |
| Owner of the CI/CD pipeline | [deployment/](./deployment/) |
| Tech writer / docs reviewer | [documentation/](./documentation/) |
| Reviewer auditing an existing action | All domains, filtered to `[error]` rules |
| Security auditor doing a security-only sweep | [security/](./security/) and `[security]`-tagged rules elsewhere |
| Configuring the MCP server's ESLint layer | [eslint-config.md](./eslint-config.md) |

## Rule format (per architecture PDF section 6)

```markdown
## <Title> [<severity>] [<tag>, <tag>]
<one-paragraph rationale>
### Bad
<counterexample>
### Good
<preferred pattern>
```

**Severities** — `error` (block merge), `warn` (surface in PR), `info` (educational). The MCP server's `strict` profile auto-promotes warns to errors.

## File catalog (markdown layer — 387 rules across 13 top-level domains)

Every top-level domain is a subfolder of small, sub-topic-scoped files. App Builder additionally contains two nested sub-product subfolders for first-class App Builder products (API Mesh, Commerce Admin UI SDK).

| Domain | Rules | Files | Coverage |
|---|---|---|---|
| [appbuilder/](./appbuilder/) | **110** | 12 + 2 sub-products | App Builder & I/O Runtime — actions, quotas, IMS auth, S2S OAuth, I/O Events (polling vs Events, idempotency), manifest, deployment (vanity URLs), tooling (template hygiene), Commerce GraphQL patterns, bulk ops/pagination, cost optimization. Includes nested [api-mesh/](./appbuilder/api-mesh/) (33 rules) and [admin-ui-sdk/](./appbuilder/admin-ui-sdk/) (16 rules). |
| [node/](./node/) | **46** | 9 | Node.js — bug classes, modern APIs, ESLint correctness, PHPMD complexity, naming, promises, typed errors, concurrency, circuit breakers |
| [react/](./react/) | **37** | 8 | React 19 & React Spectrum — Provider, Unified Shell iframe, hooks, components, JSX, architecture (Quarry-first, prop-drilling), i18n, error boundaries |
| [security/](./security/) | **36** | 5 | Adobe security (incl. PKCE/BFF for SPAs), eslint-plugin-security, OWASP Web Top 10, OWASP API Top 10, supply-chain |
| [typescript/](./typescript/) | **36** | 5 | tsconfig strict-mode, type safety, async correctness, modern features, stylistic judgment |
| [stylistic/](./stylistic/) | **25** | 6 | ESLint judgment — strings, numbers, destructuring, control flow, functions, operators |
| [a11y/](./a11y/) | **18** | 5 | WAI-ARIA correctness — content, ARIA props/roles, interaction, structure, media |
| [testing/](./testing/) | **17** | 6 | Jest, RTL, mocking (aio-sdk Logger, nock/msw), coverage, fixtures, smoke vs e2e |
| [runtime/](./runtime/) | **15** | 5 | Browser-verified — Core Web Vitals, runtime security (CSP), perf, quality gates, loading hints |
| [deployment/](./deployment/) | **17** | 6 | Pipeline (incl. --no-build, branch→workspace mapping), SLOs, environments, releases, rollback, secrets (incl. OAuth S2S, no console.json in git) |
| [imports/](./imports/) | **12** | 5 | Discipline (no-cycle), dependencies, ordering, exports, aliases |
| [process/](./process/) | **12** | 5 | Governance — discovery, governance, quality, operations, compliance (DPIA) |
| [documentation/](./documentation/) | **6** | 3 | JSDoc, project README + data-access, releases (changelog, contracts) |
| **Total** | **387** | **95** (across 13 top-level domains + 2 nested sub-products) | |

## ESLint configuration (mechanical layer — ~800 rules)

[eslint-config.md](eslint-config.md) documents which ESLint plugins, configs, and rules the MCP server enables on every `Linter.verify()` call. Coverage:

| Plugin / config | Rules | Purpose |
|---|---|---|
| `@eslint/js` recommended | ~70 | Baseline correctness — no-undef, no-unused-vars, no-unreachable, etc. |
| `typescript-eslint` strict-type-checked + stylistic-type-checked | ~155 | Full type-aware static analysis |
| `eslint-plugin-react` recommended | ~25 | React-specific bug class |
| `eslint-plugin-react-hooks` recommended | 5 | Hooks correctness |
| `eslint-plugin-jsx-a11y` recommended | ~36 | Mechanical a11y enforcement |
| `eslint-plugin-security` recommended | 14 | Security pattern detection |
| `eslint-plugin-n` recommended | ~50 | Node.js-specific |
| `eslint-plugin-import` recommended | ~20 | Import discipline |
| `eslint-plugin-promise` recommended | ~14 | Promise patterns |
| **Mechanical total** | **~800+** | |

**Combined: ~298 + ~800 = ~1,100+ effective review rules** through one `review_code` tool call, with no per-rule LLM context cost (the LLM calls one tool, gets one merged `ReviewResult`).

## Why the split — reasoning

Three architectural reasons the rules are split between markdown (judgment) and ESLint (mechanical):

1. **LLM context cost.** Loading 1,000 markdown files into the LLM's context per review would dominate token cost and dilute attention. Mechanical rules don't need reasoning — they're deterministic regex/AST checks that ESLint runs with zero LLM cost. Markdown rules earn their place when they need a Bad/Good example or domain reasoning.

2. **Speed of update.** Adding a new ESLint plugin rule requires a server rebuild (per architecture PDF section 4). Adding a new markdown rule is a file edit, hot-reloaded on the next review (PDF section 6). Split by *change frequency*: ESLint config is stable, practice markdown evolves weekly.

3. **Adobe-specific knowledge.** ~50 rules in this directory have **no ESLint equivalent** — the validator overwriting `params.body`, the 60s/30s web-action cap split, license-pack quotas, `disable-download` irreversibility, IMS auth governance. These are the rules that justify the markdown system existing.

## Severity totals (markdown layer only)

| Severity | Approx count | Behavior in `standard` profile | Behavior in `strict` profile |
|---|---|---|---|
| error | ~129 | block merge | block merge |
| warn | ~117 | surface in PR | block merge (auto-promoted) |
| info | ~63 | educational note | educational note |
| **Total** | **309** | | |

## Where these files live

- **`docs/best-practices/`** — canonical, human-readable, the single source of truth.

Every domain uses the same subfolder layout: `<domain>/<subtopic>.md` for rule files plus a `<domain>/README.md` that indexes the sub-files and consolidates Sources.

## Adding a new rule

1. Decide the layer first:
   - Mechanical, deterministic, no Bad/Good example needed → enable it via [eslint-config.md](./eslint-config.md), no markdown rule.
   - Needs reasoning, Adobe-specific, or has interesting Bad/Good cases → markdown rule.
2. Drop the rule into the matching `<domain>/<subtopic>.md` file. If no existing sub-topic fits, add a new sub-file under the right domain and link it from that domain's `README.md`.
3. Pick a severity (errors block, warns surface, infos educate).
4. Write title + rationale + `### Bad` + `### Good`. Keep rationale to one paragraph.
5. Bump the rule count in the domain's `README.md` and in the catalog above. Update Sources if you cite a new doc.

## Sources

See the **Sources** section at the bottom of each file for citations. Headline references:

- [Adobe App Builder docs](https://developer.adobe.com/app-builder/docs/overview/)
- [Adobe I/O Runtime System Settings](https://developer.adobe.com/runtime/docs/guides/using/system_settings/)
- [Adobe React Spectrum](https://react-spectrum.adobe.com/)
- [WAI-ARIA Authoring Practices](https://www.w3.org/WAI/ARIA/apg/) / [WCAG 2.2](https://www.w3.org/TR/WCAG22/)
- [ESLint core rules](https://eslint.org/docs/latest/rules/)
- [@typescript-eslint rules](https://typescript-eslint.io/rules/)
- [eslint-plugin-security](https://github.com/eslint-community/eslint-plugin-security)
- [eslint-plugin-n](https://github.com/eslint-community/eslint-plugin-n)
- [eslint-plugin-import](https://github.com/import-js/eslint-plugin-import)
- [eslint-plugin-promise](https://github.com/eslint-community/eslint-plugin-promise)
- [OWASP Cheat Sheets](https://cheatsheetseries.owasp.org/)
- [Keep a Changelog](https://keepachangelog.com/) / [Semantic Versioning](https://semver.org/)
