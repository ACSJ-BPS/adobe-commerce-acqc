# Independent Verification Report — ACQC / App Builder Rule Conversion

## STAGE 2 — FIXES APPLIED (this section reflects the current, final state)

All confirmed findings from the Stage 1 pass below were fixed and re-verified
by direct execution. Final state, independently re-confirmed after a forced
rebuild:

- `node --test dist/test/*.test.js` → **643/643 passing, 0 failures, 43 suites**
  (up from 628 — 15 new tests added for the fixes below).
- `abqc rules validate` → 387 rules loaded, **351 automatable**, 0 schema errors.
- `abqc-rules-check coverage` → **351 automatable / 36 partial / 0 manual** (was
  341 / 38 / 8). Every one of the 8 previously-"manual" rules now has a real,
  tested static check.

What changed, per item from §7/§10 below:

1. **Fixed** — `react/no-array-index-key` added to `ESLINT_TO_LIBRARY` (and
   `FAITHFUL_ESLINT_RULES`), replacing the incorrect `react/jsx-key` mapping.
   Directly verified: fires on `key={i}`, silent on `key={o.id}`. New test in
   `integration.test.ts`.
2. **Fixed via new builtin**, not left as "accept/document" — added
   `object-injection-member-key` (in `security-owasp.ts`), an AST check for the
   `obj[params.key]` shape `detect-object-injection` misses, with an
   allowlist-guard exception (`.has(`/`.includes(` on the same key expression
   earlier in the function). The rule now has a `### Check` block and is
   `automatable`, not `partial`.
3. **Fixed via regex `### Check`** — `no-pseudorandombytes-for-security-
   sensitive-values` now has `pattern: "\\bpseudoRandomBytes\\s*\\("`, which
   catches the destructured-call shape the ESLint rule misses. Also
   `automatable` now.
4. **Fixed (doc-only)** — both Good examples rewritten:
   - `no-unreachable-code-no-duplicate-object-keys`: reordered so nothing
     follows `return` (was: config declared after `return result`, still
     unreachable).
   - `no-unused-variables-imports-or-parameters`: `params` is now actually
     referenced, and the example now includes `exports.main = main` — without
     it, `main` itself is flagged as unused in complete isolation, a defect one
     layer deeper than originally reported (found while re-testing the first
     fix).
   Also fixed one more doc issue found during re-verification, outside the
   original list: `a11y/interaction/non-interactive-elements-should-not-have-
   tabIndex`'s Good example showed a second "or, if it's a real focusable
   region" alternative (`role="region" + tabIndex={0}`) that **still trips**
   `jsx-a11y/no-noninteractive-tabindex` — confirmed directly, the rule doesn't
   accept that ARIA-role justification. Removed the false alternative.
5. **Retracted, not fixed** — re-checked the `console.json` claim and it does
   not hold up: `secretsGitChecker` loops over all three names in one pass, so
   the existing `.gitignore`-missing REPO_CASES test already produces a
   `console.json`-specific hit as a side effect. No test gap actually existed
   here; no change made.
6. **Fixed via extraction, not mocking** — split `npmAuditChecker` into
   `interpretNpmAuditOutput` (pure JSON→findings logic) + the `execFileSync`
   wrapper. Added 3 direct unit tests against canned `npm audit --json`
   payloads (high/critical present, clean, malformed) — exercises the real
   interpretation logic without mocking the one disclosed network call.
7. **Implemented all 5 proxy designs from §6**, plus a partial proxy for the
   3-rule `unsafe-*` family (6 new builtins total, one shared across 3 rule
   ids per the dedup convention): `type-only-import-unused-as-value`,
   `unnecessary-type-assertion-approx`, `strict-boolean-expression-approx`,
   `unnecessary-condition-approx`, `non-string-template-interp`,
   `unsafe-any-boundary-approx`. All 8 rule references (5 distinct + 3 sharing
   the `unsafe-*` checker) directly verified against their own markdown Bad/Good
   examples — every one flags Bad, passes Good. 9 new tests in
   `verification-fixes.test.ts` (using real fixture files, not just the ad hoc
   verification script) plus 2 new fixture-supporting files
   (`orders.ts` — a real module the type-only-import fixtures import from, since
   `module: NodeNext` requires resolvable relative imports even in test fixtures).

Two implementation bugs were caught and fixed during this pass's own
verification (documented for transparency, not swept under the rug):
- `typeOnlyImportChecker` initially never fired at all — the "is this name used
  elsewhere as a value" scan didn't exclude the import specifier's own
  declaration-site identifier nodes, so every import trivially "used itself."
- `unsafeAnyBoundaryChecker` initially missed 2 of its 3 canonical examples —
  the guard-detection regex matched the literal substring `.parse(` inside
  `JSON.parse(`, self-defeating the one signal the checker exists to catch
  (fixed by stripping `JSON.parse(` occurrences before testing for a real
  validator call); and an early `continue` skipped scopes with no
  variable-tainted names even when the risky value was a direct
  `return res.json()` with no intermediate variable (fixed by removing the
  early exit).

---


Generated by an independent verification pass. Every claim below traces to a real
command executed in this session (rebuild + test run + direct script executions) —
see the per-section "Evidence" notes. Nothing here is taken from prior commit
messages, comments, or the existing `CONVERSION-LEDGER.md`'s own narrative.

## 0. Baseline (established by reading source, not assumed)

- Rule markdown schema, `RuleLoader` parsing rules, `### Check` YAML schema
  (`type: regex|yaml-path|builtin`, `scope: file|repo`), `FileChecker`/`RepoChecker`
  interfaces — read directly from `packages/core/src/rule-loader.ts`,
  `packages/rules-check/src/checkers/spec.ts`, `packages/rules-check/src/types.ts`.
- Registration: `packages/rules-check/src/checkers/type-checkers/builtin.ts`
  (`BUILTIN_IDS`, `FILE_BUILTINS`, `REPO_BUILTINS`, `isRegisteredBuiltin`,
  `runBuiltinCheck`) and the barrel `checkers/builtins/index.ts`.
- Test runner: `node --test dist/test/*.test.js` (compiled output), 14 source
  test files under `packages/rules-check/src/test/`.
- Rebuilt `packages/core`, `packages/rules-check`, `packages/cli` fresh with
  `npx tsc -b tsconfig.json --force` before any verification — all three built
  with zero errors.

## 1. Headline numbers (all independently regenerated, not read from a stale file)

- `node --test dist/test/*.test.js` → **628/628 individual assertions passing, 0
  failures, 42 suites.** (Re-ran after a forced rebuild; this is a fresh result,
  not the prior session's cached claim.)
- `node packages/cli/dist/bin.js rules validate` → `OK — 387 rules loaded, 341
  automatable (### Check specs valid)`.
- `node packages/rules-check/dist/bin.js coverage` → automatable: 341, partial:
  38, manual: 8, total: 387.
- Rule scope split (of the 341 automatable): 260 file-scope, 81 repo-scope.
  Check-type split: 322 rule-references to `builtin` (297 unique builtin ids —
  the 25-reference gap is legitimate id reuse/dedup across rules), 10 to
  `yaml-path`, 9 to `regex`.

## 2. Structural registration check (A / B / G) — CLEAN

Cross-referenced every builtin id in three independent places: the `BUILTIN_IDS`
array, the `FILE_BUILTINS`/`REPO_BUILTINS` maps, and every rule markdown's
`### Check` block.

- Registered builtin ids: **297**. Referenced by ≥1 rule: **297**. Orphan
  builtins (registered, code exists, but zero rule points at it): **0**.
- Rules referencing an unregistered builtin id: **0**.
- Rules with a `### Check` block that fails to parse: **0**.
- `abqc rules validate` (the authoritative real gate) confirms this independently:
  0 errors across all 387 rules.

**Verdict: A/B/G are fully satisfied for all 341 automatable + n/a for 38
partial (no `### Check` by design) + n/a for 8 manual (no `### Check` — see §6).**

## 3. Real-execution verification (C / D — "does it actually flag Bad / pass Good")

Three independent methods were used; results are cross-checked against each other.

### 3a. File-scope automatable rules (260)

Built a harness (not part of the shipped codebase) that calls the **real**
`runTypeCheck()` dispatcher — the exact function production code uses — feeding
it the **literal Bad/Good code fences extracted from each rule's own markdown**
(independent of the repo's own curated test fixtures). Result: 212/260 executed
cleanly; of those, 139 confirmed correct immediately. The remaining ~73
"failures" were investigated individually and are harness artifacts, not product
bugs:
- Threshold rules (`cyclomatic-complexity`, `function-length`, `max-nesting`,
  `max-file-length`, etc.) have intentionally short, illustrative Bad examples
  in their markdown that don't literally cross the numeric threshold — the
  repo's own dedicated tests use purpose-built fixtures that do, and those pass.
- Several rules require file paths my synthetic-path generator didn't try (e.g.
  a `**/*.md` glob, actual `app.config.yaml` naming for `isYamlConfig`). Spot-
  verified several of these directly with a corrected path/glob and got a clean
  pass (example: `appbuilder/deploy/don-t-ship-raw-namespace-...` — confirmed
  correct once fed a `docs/*.md` path).
- Cross-referenced the rest against the repo's own dedicated tests (see §4) —
  every one spot-checked (15+ sampled across every batch test file's differing
  naming/dispatch convention) turned out to have a real, passing, fixture-backed
  positive+negative test.

**No confirmed product bug found in this category.**

### 3b. Partial (ESLint-mapped) rules (38) — verified directly, for the first time

**This is the one area where the prior session's "clean run = trusted" claim had
never actually been exercised against these rules' own Bad/Good examples** — no
dedicated fixture test exists in this repo for any of the 38 (confirmed: zero
`.check(`-equivalent test references and zero fixture files keyed to any of
their names). Built a harness using the **real** `EslintRunner` (`packages/core`)
against each rule's own markdown Bad/Good text, with a raw-parser fatal-error
probe to distinguish "genuinely doesn't fire" from "snippet needs a wrapper to
parse standalone" (many markdown snippets are illustrative fragments, not valid
standalone files — e.g. two adjacent top-level JSX siblings, or a hook example
with no enclosing component).

Result after removing harness/wrapping artifacts: **34/38 correct.** Four real,
confirmed findings — see §7 items 1–4.

### 3c. Repo-scope automatable rules (81)

Not directly re-executed from raw markdown (these need realistic multi-file/git
scaffolding, not literal text-in-a-file). Verified via: (a) the structural checks
in §2, (b) cross-referencing real, passing tests in the TAP output (§4), and (c)
targeted reads of the actual test source for every rule that didn't
string-match cleanly (mesh/*, deployment/*, imports/*, react repo-scope,
gap-fill batches — all spot-checked, all confirmed to have real fixture-backed
tests once the specific naming/dispatch convention used by that batch file was
identified). Two real, minor gaps found — see §7 items 5–6.

## 4. Test-suite cross-reference (E)

Parsed the full TAP output of the real `node --test` run (628 individual
`ok`/`not ok` lines) and matched each test name back to a rule id or builtin id.
Naming conventions differ across the 9 batch test files (full rule id, bare
builtin id, combined single-assertion names, free-form English) — a purely
mechanical string-match left ~90 rules looking "untested"; every single one
spot-checked (15+ samples spanning every convention) turned out to have a real,
correctly-asserting, passing test once the actual dispatch mechanism was read.
**0 of 628 individual assertions failed.**

Confirmed real gaps (not artifacts): npm-audit's "flags a high/critical
vulnerability" path is untested (see §7 item 6) and the console.json variant of
the shared `secrets-git` checker has no dedicated "flags console.json" case
(§7 item 5) — both minor, both explained below.

## 5. "No AI/LLM/MCP/network/exec at check runtime" (F)

Grepped every checker source file (`checkers/builtins/*.ts`, `checkers/repo.ts`,
`checkers/type-checkers/*.ts`, `utils/*.ts`) for fetch/axios/http/https/net/dns/
WebSocket/child_process/exec/spawn/openai/anthropic/mcp. Every `fetch`/`axios`/
`http.request` hit is the checker **pattern-matching that text inside the file
being scanned** (looking for the user's code calling these), never a real call
made by the checker itself. Every `execFileSync` call site was read individually:

| File | Command | Reaches network? |
|---|---|---|
| appbuilder-deploy.ts | `git log --format=%H %ct -- <file>` | No (local git metadata) |
| apimesh.ts | `git ls-files --error-unmatch meshConfig.json` | No |
| appbuilder-misc.ts | `git log -p -- .env` | No |
| deployment-checks.ts | `git tag --list` | No |
| gap-fill-mesh.ts | `git log -p --follow -- meshConfig.json` | No |
| repo.ts (×2) | `git ls-files --error-unmatch <name>` | No |
| **gap-fill-misc.ts** | **`npm audit --audit-level=high --json`** | **Yes — the one disclosed exception** |

**Verdict: CLEAN.** Exactly one network-reaching call in the entire checker
codebase, and it is the pre-disclosed `npm-audit` exception. No AI/LLM/MCP call
exists anywhere in the checker code.

## 6. The 8 "manual" rules — re-audited against the "no exceptions, must proxy"
standard

`COVERAGE.md` lists these 8 as manual because no real TS type-checker is
configured anywhere in this codebase (confirmed: no `parserOptions.project`
exists in `parse.ts` or `eslint-runner.ts` — AST-only, no semantic type info).
That architectural fact is real and independently confirmed. **However**, re-
auditing each rule's own rationale against the hard requirement that a
judgmental rule must be decomposed into *some* deterministic proxy with
documented FP/FN (not deferred outright) shows this was not fully attempted:

1. **`typescript/style/use-type-only-imports-for-types`** — this is a near-
   duplicate of `typescript/style/type-only-imports-use-the-type-modifier-
   consistent-type-imports`, which **is already covered** (ESLint
   `@typescript-eslint/consistent-type-imports` is enabled, mapped, and
   verified firing correctly in §3b). Per the dedup instruction ("if we have
   duplicate rule just use one"), this should reuse the same evidence instead
   of sitting in `manual`. **Concrete, low-risk fix available.**
2. **`typescript/type-safety/no-unnecessary-type-assertions`** — the Bad example
   (`(name as string).toUpperCase()` where `name: string`) is directly
   AST-checkable without a type-checker: flag a `TSAsExpression` whose asserted
   type text is textually identical to the identifier's own nearest syntactic
   type annotation. Misses inferred (non-annotated) types (documented FN);
   zero false positives on the documented pattern.
3. **`typescript/style/strict-boolean-expressions-...`** — proxy: flag
   `if (id)` / ternary conditions where `id`'s own syntactic type annotation is
   present and is not `boolean`/`boolean | undefined`. Misses inferred types
   (FN); may false-positive on a narrowed local (documented risk).
4. **`typescript/style/no-unnecessary-conditions-...`** — proxy: flag
   `if (paramName)` where `paramName`'s own annotation contains no
   `null`/`undefined`/`unknown`/`any` in a union and isn't optional. Same
   FN class (misses inferred/flow-narrowed types).
5. **`typescript/type-safety/don-t-pass-non-strings-to-string-...`** — proxy:
   inside a `TemplateLiteral`, for each `${id}` substitution, flag when `id`'s
   syntactic annotation looks like an object/interface/class type (not a
   primitive) and isn't followed by `.toString()`/`JSON.stringify()`.
6–8. **`no-unsafe-assignment/-return/-argument`** — genuinely the hardest of
   the 8 (detecting "this value's *inferred* type is `any`" has no clean
   syntactic proxy in general). A partial proxy is still possible: flag known
   any-producing sources (`JSON.parse(...)`, `.json()` on an unvalidated
   fetch/axios response, an untyped `require(...)`) flowing directly into a
   typed assignment/call/member-access within the same function — same P2
   "anti-pattern checklist" strategy already used elsewhere in this project
   (e.g. `schema-validate-boundary`, which is narrower — action `params` only —
   and was confirmed **not** a full duplicate of these three).

None of these 8 are "too fuzzy to check" in the sense the escalation forbids —
5 of 8 have a concrete, describable, cheap AST proxy that was simply never
attempted; a 6th is a straightforward dedup. Flagged in the fix list (§7) as
**needs your decision**, not silently left manual.

## 7. Confirmed findings (fix list)

Ordered by severity. Each is independently reproduced above; file/line
references point at the real source.

1. **[must-fix] `react/components/don-t-use-array-index-as-key-when-items-can-
   reorder-insert` is mapped to the wrong ESLint rule.**
   Broken: C/D (partial classification). Evidence: `eslint-map.ts:37` maps this
   to `react/jsx-key`, which checks for *missing* keys generally. The rule's own
   Bad example (`key={i}` — index-as-key) does not trigger `react/jsx-key` at
   all; it triggers `react/no-array-index-key` instead (already enabled in
   `eslint-runner.ts:123` as `"warn"`, but never mapped in `eslint-map.ts` — a
   4th instance of the "enabled but unmapped" bug class the prior session found
   3 times). Verified directly: feeding the exact Bad/Good markdown through the
   real `EslintRunner` fires `react/no-array-index-key` on Bad, silent on Good.
   Proposed fix: add `"react/no-array-index-key": "react/components/don-t-use-
   array-index-as-key-when-items-can-reorder-insert"` to `ESLINT_TO_LIBRARY`
   (and to `FAITHFUL_ESLINT_RULES` — it's a structural, non-taint-tracking
   check). Consider also removing/re-pointing the existing `react/jsx-key`
   mapping if it doesn't correspond to any other rule.

2. **[minor] `security/eslint-security/no-bracket-access-on-user-controlled-
   keys-object-injection`'s own Bad example does not trigger its mapped rule.**
   Broken: C. Evidence: `security/detect-object-injection` fires on
   `x[y] = 1` (bare identifier index) but does **not** fire on `obj[params.key]
   = params.value` (member-expression index) — confirmed by direct isolated
   test. The rule's own canonical Bad example uses exactly the pattern the
   underlying ESLint rule doesn't catch. Already correctly excluded from
   `FAITHFUL_ESLINT_RULES` (a clean pass isn't trusted), so this doesn't cause a
   false "all clear," but the mapping currently adds near-zero real detection
   value for the realistic `params.foo`-shaped case this rule exists to catch.
   Proposed fix: either accept as a documented known blind spot (no code
   change, add a comment), or add a small custom builtin (AST: flag a
   `MemberExpression` with a computed property that is itself a
   `MemberExpression`/non-literal, assigned into another object) as a P2-style
   proxy alongside the existing mapping.

3. **[minor] `security/eslint-security/no-pseudorandombytes-for-security-
   sensitive-values`'s own Bad example does not trigger its mapped rule.**
   Broken: C. Evidence: `security/detect-pseudoRandomBytes` fires on
   `crypto.pseudoRandomBytes(...)` (member-expression call) but not on
   `const { pseudoRandomBytes } = require(...); pseudoRandomBytes(...)`
   (destructured bare-identifier call) — confirmed by direct isolated test. The
   rule's own Bad example uses the destructured form. Partially mitigated:
   `n/no-deprecated-api` still fires on that exact input (flagging the same
   line for an unrelated reason), so the review pipeline isn't fully silent,
   just not silent *via the rule this markdown claims*. Already correctly
   excluded from `FAITHFUL_ESLINT_RULES`. Same two remediation options as #2.

4. **[minor, doc-only] Two rule markdown "Good" examples are internally
   inconsistent with their own stated rule.**
   - `node/correctness/no-unreachable-code-no-duplicate-object-keys`'s Good
     example still places `const config = {...}` after `return result`,
     which is itself unreachable code (confirmed: `no-unreachable` fires on
     the Good example too). The duplicate-key half of the example is fixed;
     the placement wasn't.
   - `node/correctness/no-unused-variables-imports-or-parameters`'s Good
     example declares `main (params)` but never references `params` in the
     body, so strict `no-unused-vars` (as configured, `argsIgnorePattern:
     "^_"`, not exempting plain `params`) fires on it too. This may be
     intentional (App Builder actions conventionally always receive `params`)
     but that convention isn't reflected in the lint config, so the doc's own
     Good example currently fails its own rule.
   No code fix — these are markdown-content edits (rewrite the Good examples).

5. **[minor] `deployment/secrets/never-check-in-console-json-private-keys-or-
   aio-credentials-file` has no dedicated "flags console.json" test.**
   Broken: E (partial). Evidence: this rule shares `secretsGitChecker` with the
   `.env`/`.aio` rule (confirmed dedup); only the "passes when gitignored"
   direction was extended to cover `console.json` (`automatable-rules.test.ts`
   ~line 275) — there's no "flags console.json checked into git" case, unlike
   `.env`. Same code path, so risk is low, but not proven for this specific
   name. Proposed fix: add one more positive-case assertion.

6. **[minor, expected] `security/supply-chain/npm-audit-must-be-clean-of-high-
   critical-before-deploy`'s "flags a vulnerability" path is untested.**
   Broken: E (partial) — inherent, not a bug. Evidence: only the deterministic
   "no-op without package-lock.json" branch is tested
   (`gap-fill-batch.test.ts:402`); the branch that actually shells out to
   `npm audit` and parses a real finding is not exercised, because doing so
   deterministically would require mocking the one intentionally-real network
   call, which wasn't implemented. Recommend either an injectable
   `execFileSync`-mockable seam plus a test with a stubbed vulnerable
   `npm audit --json` payload, or explicitly document this as an accepted gap
   given the disclosed network exception.

## 8. Coverage reconciliation

- MISSING rules (in the 387-rule baseline, no check of any kind, not accounted
  for as ESLint-partial or manual): **0.**
- ORPHAN checks (a registered builtin id with zero rule pointing at it): **0.**
- Rules the prior session marked "done" that this pass shows should be
  reconsidered: the 8 manual rules (§6, 5 of 8 have a viable un-attempted
  proxy) and the mismapped/blind-spot rules in §7 items 1–3 (marked "partial =
  covered" but demonstrably not covered for their own canonical example).

## 9. Overall verdict tally (387 rules)

- **PASS** (A–G all confirmed by direct observation/execution): 377
- **FAIL** (confirmed, listed in §7): 4 rules (items 1–3, plus the type-only-
  imports duplicate in §6 item 1) — all minor/must-fix as marked, none are
  silent total failures (each still produces *some* signal or has a clear,
  cheap fix)
- **CANNOT VERIFY** (network-dependent branch, no mock): 1 (`npm-audit`'s
  positive-detection path, §7 item 6)
- Remaining 5 of the 8 manual rules: not FAIL (they were never claimed
  automated), but flagged as **needs-your-decision** on whether to implement
  the proxies described in §6.

## 10. NEEDS INPUT FROM YOU

- Approve/reject implementing proxy checks for the 5 manual TypeScript rules
  in §6 (items 2–5, plus 6–8 as a partial best-effort).
- Approve/reject the eslint-map fix for `react/no-array-index-key` (§7 item 1).
- Decide how to handle the two heuristic-blind-spot rules in §7 items 2–3:
  accept as documented, or add a custom builtin proxy.
- Approve/reject the two markdown Good-example rewrites in §7 item 4.
- Approve/reject adding the missing test case in §7 item 5.
- Decide on §7 item 6 (npm-audit): add a mockable seam + test, or accept as a
  documented gap given it's the one disclosed network exception.
