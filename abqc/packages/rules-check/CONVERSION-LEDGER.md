# Static-check conversion ledger

## FINAL STATUS v2 (post independent-verification-pass fixes — supersedes v1 below)

An independent verification pass (see `VERIFICATION-REPORT.md`) re-checked every
claim in the original FINAL STATUS by direct execution rather than trusting this
ledger's own narrative, found several real gaps (including all 8 "manual" rules
turning out to be convertible after all), and fixed them. Current, re-verified
state:

| Status | Count | % |
|---|---|---|
| Automated — native `### Check` (regex / yaml-path / builtin) | 351 | 90.7% |
| Automated — ESLint-mapped (`eslint-map.ts`) | 36 | 9.3% |
| **Total automated** | **387** | **100%** |
| Manual | 0 | 0% |

`node --test dist/test/*.test.js` → 643/643 passing. `abqc rules validate` → 0
schema errors. See `VERIFICATION-REPORT.md` for the full independent-verification
methodology and the "Stage 2 — Fixes Applied" section for exactly what changed
(6 new builtins, 1 eslint-map mismapping fix, 1 new regex check, 2 doc-example
fixes, 1 npm-audit test-seam extraction, 15 new tests).

## FINAL STATUS v1 (superseded — kept for history)

| Status | Count | % |
|---|---|---|
| Automated — native `### Check` (regex / yaml-path / builtin) | 341 | 88.1% |
| Automated — ESLint-mapped (`eslint-map.ts`, incl. 3 mapping-gap bugs found & fixed) | 38 | 9.8% |
| **Total automated** | **379** | **97.9%** |
| Manual (documented, unavoidable) | 8 | 2.1% |
| **Total rules** | **387** | **100%** |

**The 8 "manual" rules from v1 — every one turned out to be convertible (see VERIFICATION-REPORT.md §6 for the proxy design, §"Stage 2" for the fix):**
1. `typescript/style/use-type-only-imports-for-types` — was treated as a duplicate of `typescript/style/type-only-imports-use-the-type-modifier-consistent-type-imports` and left unautomated; re-examined during verification and given its own AST proxy builtin (`type-only-import-unused-as-value`) instead, since "duplicate in spirit" isn't the same as "actually covered."
2–8. The seven TypeScript rules that need real type info in general (`no-unsafe-assignment/-call/-member-access`, `no-unsafe-return`, `no-unsafe-argument`, `no-unnecessary-type-assertions`, `no-unnecessary-conditions`, `strict-boolean-expressions`, `don't-pass-non-strings-to-string-concat`) were originally left manual on the reasoning that no honest syntax-only proxy exists. The verification pass concluded this doesn't hold up against the "must decompose into a deterministic proxy, document residual FP/FN" standard: all 7 got a real AST-based proxy (declared-annotation text matching, not real type inference), each with documented false-positive/false-negative behavior, and each verified firing correctly against its own rule's canonical Bad/Good example.

Real bugs found and fixed along the way (not just new code written):
- `security/detect-non-literal-fs-filename` and `security/detect-pseudoRandomBytes` were mapped in `eslint-map.ts` but never enabled in `eslint-runner.ts` — silently non-functional despite being counted as "partial" in `COVERAGE.md`.
- `promise/param-names` was enabled in `eslint-runner.ts` but never mapped in `eslint-map.ts` — same silent-gap pattern, third instance.
- `jsx-a11y/aria-props`/`aria-role`/`aria-proptypes` were enabled but unmapped (rows counted as "manual" despite ESLint already catching them).
- A test's fixture path pointed at `dist/test/fixtures` instead of `src/test/fixtures` (45 false failures).
- A test's temp-dir setup was missing `mkdir(..., {recursive:true})` before a nested `writeFile` (2 false failures).
- `missing-400-path-test`'s gate regex required a dotted `action.main(` call and missed destructured `const { main } = require(...)` — silently vacuous on its own fixture.
- `missing-400-path-test`'s pass condition didn't recognize the Jest `.toBe(400)` assertion idiom (only `=== 400`) — flagged its own "good" fixture.
- `mesh-dev-prod-endpoint` (and the shared `isProdPath` helper) classified files by a substring test on the full path, so a temp directory named e.g. `abqc-mesh-devprod-good-X` (containing both "dev" and "prod" as substrings) misclassified the prod file as also being a dev file — fixed to match whole path segments only.

## Original per-rule design ledger below

Tracks all 387 ACQC rules through the Stage 2 conversion (see chat history for the
full Stage 1 design per rule — this file is the live status tracker, not the design
doc). Status values below reflect the PRE-IMPLEMENTATION plan; see the FINAL STATUS
table above for the actual outcome — nearly every "TO-IMPLEMENT" row below is now DONE.

- `SHIPPED` — already has a working `### Check` (native or ESLint-mapped) before this effort.
- `BUG-FIXED` — was mapped in `eslint-map.ts` but never actually enabled in `eslint-runner.ts`; fixed in this effort.
- `MAPPING-ADDED` — ESLint rule was already loaded by the plugin but missing its `eslint-map.ts`/`eslint-runner.ts` wiring; wiring added.
- `MERGED` — duplicate of another rule id; implemented once, both rule ids point at the same builtin `id`.
- `TO-IMPLEMENT` — needs new code, not yet started.
- `IN-PROGRESS` — a batch agent is working on it.
- `DONE` — new checker implemented, fixtures added, test passing.
- `DONE-APPROX` — implemented as a documented best-effort proxy (see Stage 1 "Known limits"); not a full solution.

Last full regeneration: see `COVERAGE.md` (auto-generated, do not hand-edit) for the
authoritative automatable/partial/manual counts once Stage 2 completes.

## A. App Builder — core (1–61)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 1 | appbuilder/config/use-include-directive-for-large-app-config-yaml-files | MECHANICAL | SHIPPED | yaml-path:include-large | |
| 2 | appbuilder/config/action-file-paths-must-be-relative-to-the-config-file | MECHANICAL | SHIPPED | yaml-path:relative-path | |
| 3 | appbuilder/runtime/action-handler-signature-main-params-exports-main-main | MECHANICAL | SHIPPED | builtin:main-signature | |
| 4 | appbuilder/runtime/web-action-timeout-60s-blocking-30s-through-cdn | MECHANICAL | SHIPPED | yaml-path:web-timeout | |
| 5 | appbuilder/runtime/memory-only-256-512-1024-mb-hit-prewarmed-containers | MECHANICAL | SHIPPED | yaml-path:memory-tier | |
| 6 | appbuilder/runtime/concurrency-cap-is-500-per-container-design-for-it | MECHANICAL | SHIPPED | yaml-path:concurrency | |
| 7 | appbuilder/runtime/use-a-supported-node-runtime-nodejs-18-and-older-are-eol | MECHANICAL | SHIPPED | yaml-path:runtime-version | |
| 8 | appbuilder/runtime/wrap-entire-main-body-in-try-catch-unhandled-async-kills-the-con | MECHANICAL | SHIPPED | builtin:try-catch-main | |
| 9 | appbuilder/runtime/mark-every-action-as-final-true-prevent-sequence-injection | MECHANICAL | SHIPPED | yaml-path:final | |
| 10 | appbuilder/runtime/restrict-web-action-invocation-with-allowedhosts | MECHANICAL | SHIPPED | yaml-path:allowed-hosts | |
| 11 | appbuilder/secrets/never-commit-env-or-aio-to-git | MECHANICAL | SHIPPED | builtin:secrets-git | |
| 12 | appbuilder/auth/token-generating-actions-must-be-non-web-actions | JUDGMENTAL | TO-IMPLEMENT | builtin:token-action-not-web | |
| 13 | appbuilder/auth/use-ims-sdk-auto-renewal-jwt-lifetime-is-24h | JUDGMENTAL | TO-IMPLEMENT | builtin:ims-auto-renewal | |
| 14 | appbuilder/auth/bearer-extraction-via-getbearertoken-params-utility | JUDGMENTAL | TO-IMPLEMENT | builtin:bearer-extraction | |
| 15 | appbuilder/auth/migrate-from-jwt-service-accounts-to-oauth-server-to-server | MECHANICAL | TO-IMPLEMENT | regex | |
| 16 | appbuilder/deploy/api-gateway-provisioning-takes-5-10-minutes-plan-deploys-around- | JUDGMENTAL (P4) | TO-IMPLEMENT | builtin:gateway-provisioning-window | |
| 17 | appbuilder/deploy/disable-download-is-irreversible-set-deliberately | JUDGMENTAL (P1) | TO-IMPLEMENT | regex+comment-adjacency | |
| 18 | appbuilder/deploy/know-your-license-pack-quotas-design-within-them | JUDGMENTAL (P1+P5) | TO-IMPLEMENT | builtin:license-quota-doc | |
| 19 | appbuilder/deploy/don-t-ship-raw-namespace-adobeioruntime-net-urls-as-customer-fac | MECHANICAL | TO-IMPLEMENT | regex | |
| 20 | appbuilder/deploy/source-runtime-base-urls-from-config-never-hard-code-them-in-fro | MECHANICAL | TO-IMPLEMENT | regex | |
| 21 | appbuilder/events/implement-challenge-response-for-i-o-events-handler-actions | JUDGMENTAL | TO-IMPLEMENT | builtin:events-challenge-response | |
| 22 | appbuilder/events/verify-i-o-events-payload-hmac-signature-before-processing | JUDGMENTAL | TO-IMPLEMENT | builtin:events-hmac-verify | |
| 23 | appbuilder/events/prefer-i-o-events-over-polling-actions-on-cron-schedules | JUDGMENTAL | TO-IMPLEMENT | builtin:events-vs-polling | |
| 24 | appbuilder/events/proxy-external-3rd-party-webhooks-through-an-action-that-handles | JUDGMENTAL | TO-IMPLEMENT | builtin:webhook-proxy | |
| 25 | appbuilder/events/i-o-events-handlers-must-be-idempotent-keyed-by-event-id | JUDGMENTAL | TO-IMPLEMENT | builtin:events-idempotent | |
| 26 | appbuilder/events/batch-high-volume-event-streams-via-journaling-not-per-event-syn | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:events-batch-vs-sync | |
| 27 | appbuilder/integration/aem-cloud-service-6-5-and-commerce-have-native-extension-points- | JUDGMENTAL | TO-IMPLEMENT | yaml-path:extension-point-enum | |
| 28 | appbuilder/integration/default-runtime-is-node-js-other-languages-only-via-docker | MECHANICAL | TO-IMPLEMENT | yaml-path:non-node-runtime | |
| 29 | appbuilder/integration/call-commerce-graphql-with-app-level-credentials-from-secrets-ne | JUDGMENTAL | TO-IMPLEMENT | builtin:commerce-graphql-app-creds | |
| 30 | appbuilder/integration/never-call-commerce-admin-graphql-with-admin-credentials-from-th | MECHANICAL | TO-IMPLEMENT | builtin:no-commerce-admin-from-spa | |
| 31 | appbuilder/integration/pass-store-view-website-context-explicitly-never-inferred-from-h | MECHANICAL | TO-IMPLEMENT | builtin:no-store-context-from-headers | |
| 32 | appbuilder/integration/bound-page-sizes-when-calling-commerce-graphql-no-unbounded-page | JUDGMENTAL | TO-IMPLEMENT | builtin:bound-graphql-pagesize | |
| 33 | appbuilder/observability/use-structured-json-logging-and-forward-activation-logs-to-an-ex | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:structured-logging+doc | |
| 34 | appbuilder/performance/fan-out-large-workloads-split-into-parallel-sub-actions | JUDGMENTAL | TO-IMPLEMENT | builtin:serial-await-in-loop | |
| 35 | appbuilder/performance/defer-all-i-o-and-heavy-imports-out-of-module-scope-to-avoid-col | MECHANICAL | TO-IMPLEMENT | builtin:no-module-scope-io | |
| 36 | appbuilder/performance/cap-concurrency-on-aio-lib-state-writes-and-plan-ioext-quota-inc | JUDGMENTAL | TO-IMPLEMENT | builtin:state-write-concurrency | |
| 37 | appbuilder/performance/pagination-loops-need-an-explicit-max-pages-safety-limit | MECHANICAL | TO-IMPLEMENT | builtin:pagination-max-pages | |
| 38 | appbuilder/performance/chunk-large-jobs-across-invocations-with-state-checkpoints | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:chunk-checkpoints | |
| 39 | appbuilder/performance/for-bulk-exports-write-to-files-sdk-and-return-a-url-don-t-retur | MECHANICAL | TO-IMPLEMENT | builtin:bulk-export-files-sdk | |
| 40 | appbuilder/performance/right-size-action-memory-and-concurrency-don-t-ship-everything-a | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:uniform-memory-concurrency (repo) | |
| 41 | appbuilder/performance/prefer-batch-processing-over-per-event-sync-http-for-high-volume | JUDGMENTAL (P2) | MERGED into #26 | builtin:events-batch-vs-sync | shares detector with #26 |
| 42 | appbuilder/runtime/error-response-shape-error-statuscode-body | MECHANICAL | TO-IMPLEMENT | builtin:error-response-shape | |
| 43 | appbuilder/runtime/respond-to-429-by-backing-off-runtime-throttles-aggressively | MECHANICAL | TO-IMPLEMENT | builtin:429-backoff | |
| 44 | appbuilder/runtime/action-sequences-must-respect-the-60s-edge-cap-design-steps-to-c | JUDGMENTAL (P5) | TO-IMPLEMENT | yaml-path:sequence-budget | |
| 45 | appbuilder/runtime/wrap-external-http-calls-in-sequences-with-timeouts-retries-with | MECHANICAL | MERGED into #132 | builtin:http-timeout-retry | shares detector with node #132/#156 |
| 46 | appbuilder/sdk/logger-pattern-core-logger-with-params-log-level | MECHANICAL | TO-IMPLEMENT | builtin:core-logger-pattern | |
| 47 | appbuilder/sdk/use-files-sdk-for-blobs-100kb-not-state-sdk | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:state-large-blob | |
| 48 | appbuilder/sdk/state-sdk-is-ttl-bounded-never-the-source-of-truth | JUDGMENTAL | TO-IMPLEMENT | builtin:state-only-persistence | |
| 49 | appbuilder/sdk/define-default-params-at-package-level-when-shared-across-action | MECHANICAL | TO-IMPLEMENT | builtin:duplicate-package-inputs (repo) | |
| 50 | appbuilder/sdk/no-built-in-relational-database-use-files-sdk-or-external-db | JUDGMENTAL | TO-IMPLEMENT | builtin:state-simulated-join | |
| 51 | appbuilder/secrets/never-edit-auto-generated-aio-secrets-in-env | JUDGMENTAL (P4) | TO-IMPLEMENT | builtin:env-partial-edit-history (repo) | |
| 52 | appbuilder/tooling/typescript-actions-require-webpack-with-ts-loader | MECHANICAL | TO-IMPLEMENT | builtin:ts-action-needs-webpack (repo) | |
| 53 | appbuilder/tooling/aio-app-dev-does-not-run-scheduled-events-or-webhook-triggers | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:events-local-dev-doc (repo) | |
| 54 | appbuilder/tooling/use-aio-runtime-action-invoke-for-cloud-smoke-tests | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:smoke-test-ci-step (repo) | |
| 55 | appbuilder/tooling/track-the-aio-cli-version-watch-release-notes-for-deprecations | MECHANICAL | TO-IMPLEMENT | builtin:aio-cli-version-pinned (repo) | |
| 56 | appbuilder/tooling/remove-all-template-sample-actions-and-placeholder-content-befor | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:scaffold-markers (repo) | |
| 57 | appbuilder/tooling/parameterize-app-config-yaml-inputs-via-env-vars-mirror-them-in-  | MECHANICAL | TO-IMPLEMENT | yaml-path:literal-inputs | |
| 58 | appbuilder/validation/validate-inputs-with-checkmissingrequestinputs-before-sdk-calls | MECHANICAL | TO-IMPLEMENT | builtin:check-missing-inputs-first | |
| 59 | appbuilder/validation/treat-params-as-untrusted-never-pass-to-sql-eval-or-shell | MECHANICAL | TO-IMPLEMENT | builtin:params-to-sql-eval-shell | |
| 60 | appbuilder/validation/do-not-read-params-body-for-input-validator-overwrites-it | MECHANICAL | TO-IMPLEMENT | regex | |
| 61 | appbuilder/validation/never-construct-action-or-sequence-names-from-user-input | MECHANICAL | TO-IMPLEMENT | builtin:no-dynamic-action-invoke-name | |

## B. App Builder — API Mesh (62–94)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 62 | appbuilder/api-mesh/auth/use-operationheaders-schemaheaders-for-upstream-credentials-sour | MECHANICAL | TO-IMPLEMENT | builtin:mesh-header-secrets | |
| 63 | appbuilder/api-mesh/auth/cache-short-lived-oauth-ims-tokens-in-a-runtime-action-don-t-ref | JUDGMENTAL | TO-IMPLEMENT | builtin:mesh-hook-token-cache | |
| 64 | appbuilder/api-mesh/auth/never-expose-ims-tokens-to-the-browser-mesh-or-runtime-must-hold | JUDGMENTAL | TO-IMPLEMENT | builtin:no-ims-token-in-response | |
| 65 | appbuilder/api-mesh/caching/cache-only-static-reference-data-never-personalized-responses | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:mesh-cache-personalized | |
| 66 | appbuilder/api-mesh/caching/set-explicit-cache-control-headers-matching-response-semantics | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:mesh-cache-control-header | |
| 67 | appbuilder/api-mesh/caching/align-field-level-cache-ttls-with-data-volatility-30s-3600s-typi | JUDGMENTAL | TO-IMPLEMENT | builtin:mesh-ttl-blanket | |
| 68 | appbuilder/api-mesh/caching/understand-the-three-caching-layers-mesh-field-http-cdn-upstream | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:caching-topology-doc (repo) | |
| 69 | appbuilder/api-mesh/config/reference-secrets-via-env-vars-never-literal-api-keys-in-meshcon | MECHANICAL | TO-IMPLEMENT | builtin:mesh-literal-secret | |
| 70 | appbuilder/api-mesh/config/keep-meshconfig-json-under-375-kb-pre-filter-large-openapi-schem | MECHANICAL | TO-IMPLEMENT | builtin:mesh-config-size (repo) | |
| 71 | appbuilder/api-mesh/config/source-names-are-public-schema-choose-stable-names-never-rename- | JUDGMENTAL (P4) | TO-IMPLEMENT | builtin:mesh-source-rename-history (repo) | |
| 72 | appbuilder/api-mesh/config/separate-public-core-mesh-from-internal-backoffice-mesh | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:mesh-admin-unfiltered | |
| 73 | appbuilder/api-mesh/config/treat-meshconfig-json-as-code-git-versioned-pr-reviewed-ci-deplo | JUDGMENTAL (P4+P1) | TO-IMPLEMENT | builtin:mesh-config-git-ci (repo) | |
| 74 | appbuilder/api-mesh/observability/log-correlation-id-source-name-operation-name-latency-status-per | JUDGMENTAL | TO-IMPLEMENT | builtin:mesh-structured-log | |
| 75 | appbuilder/api-mesh/observability/set-up-log-forwarding-to-new-relic-or-your-siem-before-go-live | JUDGMENTAL (P5) | TO-IMPLEMENT | builtin:mesh-log-forwarding | |
| 76 | appbuilder/api-mesh/observability/use-httpdetails-only-in-non-prod-exposes-payloads-with-potential | MECHANICAL (P5) | TO-IMPLEMENT | builtin:mesh-httpdetails-prod | |
| 77 | appbuilder/api-mesh/operations/map-dev-console-workspaces-to-environments-dev-stage-prod | JUDGMENTAL (P5) | TO-IMPLEMENT | builtin:workspace-env-mapping (repo) | |
| 78 | appbuilder/api-mesh/operations/never-point-a-dev-mesh-at-production-commerce-or-aem-endpoints | MECHANICAL | TO-IMPLEMENT | builtin:mesh-dev-prod-endpoint (repo) | |
| 79 | appbuilder/api-mesh/operations/use-aio-api-mesh-get-to-diff-deployed-config-vs-local-before-upd | MECHANICAL | TO-IMPLEMENT | builtin:mesh-diff-before-update (repo) | |
| 80 | appbuilder/api-mesh/operations/use-aio-api-mesh-run-for-local-dev-with-graphiql-and-httpdetails | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:mesh-local-dev-doc (repo) | |
| 81 | appbuilder/api-mesh/performance/avoid-n-1-in-additionalresolvers-batch-upstream-calls-or-add-bul | MECHANICAL | TO-IMPLEMENT | builtin:mesh-resolver-n-plus-1 | |
| 82 | appbuilder/api-mesh/performance/validate-args-in-resolvers-reject-extreme-pagesize-limit | JUDGMENTAL | TO-IMPLEMENT | builtin:mesh-resolver-pagesize-guard | |
| 83 | appbuilder/api-mesh/performance/design-queries-to-complete-well-under-the-60s-edge-timeout | JUDGMENTAL (P5) | MERGED into #44 | yaml-path:sequence-budget | shares avgDurationMs annotation convention |
| 84 | appbuilder/api-mesh/performance/don-t-rely-on-mesh-for-query-depth-or-cost-limits-they-aren-t-en | JUDGMENTAL | TO-IMPLEMENT | builtin:mesh-no-depth-guard | |
| 85 | appbuilder/api-mesh/performance/don-t-rely-on-mesh-for-per-tenant-rate-limiting-enforce-upstream | JUDGMENTAL | TO-IMPLEMENT | builtin:mesh-no-ratelimit-guard | |
| 86 | appbuilder/api-mesh/performance/hooks-run-per-request-keep-fast-and-deterministic-no-slow-extern | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:mesh-hook-slow-call | |
| 87 | appbuilder/api-mesh/security/disable-graphql-introspection-and-field-suggestions-in-productio | MECHANICAL | TO-IMPLEMENT | builtin:mesh-introspection-prod | |
| 88 | appbuilder/api-mesh/security/no-user-controlled-hostnames-or-path-segments-in-source-endpoint | MECHANICAL | TO-IMPLEMENT | builtin:mesh-ssrf-endpoint | |
| 89 | appbuilder/api-mesh/security/mesh-sources-must-be-public-https-with-valid-ssl-no-local-or-sel | MECHANICAL | TO-IMPLEMENT | builtin:mesh-endpoint-https | |
| 90 | appbuilder/api-mesh/security/normalize-error-messages-in-production-don-t-leak-schema-interna | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:mesh-error-leak | |
| 91 | appbuilder/api-mesh/transforms/use-filterschema-to-remove-admin-or-debug-operations-from-the-pu | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:mesh-filterschema-missing | |
| 92 | appbuilder/api-mesh/transforms/use-prefix-and-rename-for-a-coherent-frontend-friendly-schema | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:mesh-raw-naming | |
| 93 | appbuilder/api-mesh/transforms/keep-additionalresolvers-thin-orchestrate-don-t-implement-busine | JUDGMENTAL | TO-IMPLEMENT | builtin:mesh-resolver-thin | |
| 94 | appbuilder/api-mesh/transforms/use-federation-or-type-merging-only-with-clear-data-relationship | JUDGMENTAL (P2/P3) | TO-IMPLEMENT | builtin:mesh-typemerging-unbounded | |

## C. App Builder — Admin UI SDK (95–110)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 95 | appbuilder/admin-ui-sdk/deploy/app-must-be-published-in-a-production-workspace-for-accs-to-rend | JUDGMENTAL (P5) | TO-IMPLEMENT | builtin:admin-ui-publish-workflow (repo) | |
| 96 | appbuilder/admin-ui-sdk/deploy/pin-adobe-uix-core-and-adobe-uix-guest-versions-to-the-app-revie | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:uix-version-pin (repo) | |
| 97 | appbuilder/admin-ui-sdk/deploy/declare-productdependencies-with-explicit-commerce-min-version-2 | MECHANICAL | TO-IMPLEMENT | yaml-path or builtin:product-dependencies | |
| 98 | appbuilder/admin-ui-sdk/integration/use-adobe-uix-guest-register-attach-never-hand-roll-postmessage- | MECHANICAL | TO-IMPLEMENT | builtin:no-hand-rolled-postmessage | |
| 99 | appbuilder/admin-ui-sdk/integration/set-explicit-sandbox-attributes-on-extension-points-that-need-br | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:extension-sandbox-attr | |
| 100 | appbuilder/admin-ui-sdk/integration/call-commerce-rest-graphql-from-backend-runtime-actions-not-dire | MECHANICAL | MERGED into #30 | builtin:no-commerce-admin-from-spa | |
| 101 | appbuilder/admin-ui-sdk/registration/use-a-vendor-prefixed-extension-id-consistently-across-register- | MECHANICAL | TO-IMPLEMENT | builtin:extension-id-consistency (repo) | |
| 102 | appbuilder/admin-ui-sdk/registration/one-menu-entry-per-app-use-spa-internal-routing-for-multiple-scr | MECHANICAL | TO-IMPLEMENT | builtin:one-menu-entry | |
| 103 | appbuilder/admin-ui-sdk/registration/keep-the-registration-payload-small-and-stable-commerce-caches-i | MECHANICAL | TO-IMPLEMENT | builtin:registration-payload-pure | |
| 104 | appbuilder/admin-ui-sdk/security/set-require-adobe-auth-true-on-every-admin-ui-sdk-runtime-action | MECHANICAL | MERGED into yaml-path:require-auth | yaml-path:require-auth | scoped to admin-ui-sdk actions |
| 105 | appbuilder/admin-ui-sdk/security/never-log-ims-tokens-authorization-headers-or-oauth-secrets | JUDGMENTAL | TO-IMPLEMENT | builtin:no-log-ims-token | |
| 106 | appbuilder/admin-ui-sdk/security/never-use-dangerouslysetinnerhtml-in-admin-ui-sdk-spas | MECHANICAL | MERGED into #178 | builtin:sanitize-dangerously-set-inner-html | |
| 107 | appbuilder/admin-ui-sdk/security/store-secrets-in-app-management-secret-fields-or-runtime-secrets | MECHANICAL | TO-IMPLEMENT | regex (plain-text, cross-language) | |
| 108 | appbuilder/admin-ui-sdk/structure/use-the-extensions-pattern-in-app-config-yaml-not-actions-applic | MECHANICAL | TO-IMPLEMENT | yaml-path:legacy-application-pattern | |
| 109 | appbuilder/admin-ui-sdk/structure/place-all-admin-ui-sdk-code-under-src-commerce-backend-ui-1-not- | MECHANICAL | TO-IMPLEMENT | builtin:admin-ui-code-location (repo) | |
| 110 | appbuilder/admin-ui-sdk/structure/application-block-belongs-in-ext-config-yaml-not-app-config-yaml | MECHANICAL | TO-IMPLEMENT | yaml-path:application-block-location | |

## D. Node.js (111–156)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 111 | node/bug-classes/use-crypto-randomuuid-not-math-random-for-ids-and-tokens | MECHANICAL | SHIPPED | builtin:math-random-ids | |
| 112 | node/bug-classes/buffer-alloc-not-new-buffer | MECHANICAL | SHIPPED | regex | |
| 113 | node/complexity/cyclomatic-complexity-10-per-function | MECHANICAL | SHIPPED | builtin:cyclomatic-complexity | |
| 114 | node/complexity/function-length-50-lines | MECHANICAL | SHIPPED | builtin:function-length | |
| 115 | node/complexity/max-4-parameters-per-function-use-an-options-object | MECHANICAL | SHIPPED | builtin:max-params | |
| 116 | node/complexity/max-nesting-depth-4 | MECHANICAL | SHIPPED | builtin:max-nesting | |
| 117 | node/complexity/max-file-length-300-lines-excluding-comments-and-blanks | MECHANICAL | SHIPPED | builtin:max-file-length | |
| 118 | node/correctness/strict-equality-only-eqeqeq | MECHANICAL | SHIPPED | regex | |
| 119 | node/modern-apis/use-the-node-prefix-for-all-built-in-modules | MECHANICAL | SHIPPED | builtin:node-prefix | |
| 120 | node/bug-classes/no-eval-new-function-or-vm-runinthiscontext | MECHANICAL | SHIPPED (ESLint) | eslint:no-eval+no-implied-eval+no-new-func+detect-eval-with-expression | extend for vm.runInThisContext separately, see #121-adjacent note |
| 121 | node/bug-classes/validate-regex-for-redos-no-nested-quantifiers-on-user-input | JUDGMENTAL | TO-IMPLEMENT | builtin:redos-nested-quantifier | extends eslint detect-unsafe-regex |
| 122 | node/correctness/no-unreachable-code-no-duplicate-object-keys | MECHANICAL | SHIPPED (ESLint) | eslint:no-unreachable+no-dupe-keys | |
| 123 | node/correctness/prefer-const-no-var | MECHANICAL | SHIPPED (ESLint) | eslint:prefer-const+no-var | |
| 124 | node/correctness/no-unused-variables-imports-or-parameters | MECHANICAL | SHIPPED (ESLint) | eslint:no-unused-vars | |
| 125 | node/errors/don-t-process-exit-let-the-runtime-finish-gracefully | MECHANICAL | SHIPPED (ESLint) | eslint:n/no-process-exit | |
| 126 | node/errors/error-first-callback-functions-only-invoke-with-an-error-or-null | MECHANICAL | SHIPPED (ESLint) | eslint:n/no-callback-literal | |
| 127 | node/async/no-top-level-await-of-network-calls-inside-actions | MECHANICAL | TO-IMPLEMENT | builtin:top-level-await-network | |
| 128 | node/async/async-functions-must-contain-await-require-await | MECHANICAL | TO-IMPLEMENT | builtin:require-await | |
| 129 | node/bug-classes/no-child-process-with-shell-true-or-unsanitized-args | MECHANICAL | TO-IMPLEMENT | builtin:child-process-shell | |
| 130 | node/bug-classes/no-sync-fs-apis-in-the-request-path | MECHANICAL | TO-IMPLEMENT | builtin:sync-fs-in-handler | |
| 131 | node/bug-classes/no-cpu-bound-sync-work-in-handlers-fan-out-or-use-workers | JUDGMENTAL | TO-IMPLEMENT | builtin:cpu-bound-sync-handler | |
| 132 | node/bug-classes/set-explicit-timeouts-on-all-outbound-http-no-infinite-hangs | MECHANICAL | TO-IMPLEMENT | builtin:http-timeout-retry | shared with appbuilder #45, node #156 |
| 133 | node/bug-classes/validate-inputs-at-the-boundary-with-a-schema-library | JUDGMENTAL | TO-IMPLEMENT | builtin:schema-validate-boundary | |
| 134 | node/correctness/use-isnan-and-valid-typeof-comparisons | MECHANICAL | TO-IMPLEMENT | builtin:isnan-typeof | |
| 135 | node/correctness/array-callbacks-must-return-array-callback-return | MECHANICAL | TO-IMPLEMENT | builtin:array-callback-return | |
| 136 | node/correctness/all-requires-imports-at-the-top-of-the-file | MECHANICAL | MERGED into #358 | builtin:imports-top | reuse existing builtin |
| 137 | node/correctness/no-variable-shadowing-across-scopes | MECHANICAL | TO-IMPLEMENT | builtin:no-shadow | |
| 138 | node/errors/always-handle-promise-rejections-unhandled-container-death | MECHANICAL/JUDGMENTAL | TO-IMPLEMENT (canonical) | builtin:floating-promise | merged with #149 |
| 139 | node/errors/no-unnecessary-catch-and-rethrow | MECHANICAL | TO-IMPLEMENT | builtin:pure-rethrow-catch | shared with #278 |
| 140 | node/errors/handle-the-err-parameter-in-error-first-callbacks | MECHANICAL | TO-IMPLEMENT | builtin:unused-err-param | |
| 141 | node/errors/use-typed-domain-error-classes-with-http-status-and-error-code | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:typed-error-classes | |
| 142 | node/errors/register-unhandledrejection-and-uncaughtexception-handlers-that- | MECHANICAL/JUDGMENTAL | TO-IMPLEMENT | builtin:process-crash-handlers (repo) | |
| 143 | node/modern-apis/prefer-node-fs-promises-over-callback-fs | MECHANICAL | TO-IMPLEMENT | builtin:callback-fs | |
| 144 | node/modern-apis/no-deprecated-node-js-apis | MECHANICAL (P3) | TO-IMPLEMENT | builtin:deprecated-node-api | |
| 145 | node/modern-apis/prefer-global-url-buffer-process-don-t-import-from-node | MECHANICAL | TO-IMPLEMENT | builtin:redundant-global-import | |
| 146 | node/naming/naming-camelcase-locals-pascalcase-classes-upper-snake-constants | MECHANICAL (proxy) | TO-IMPLEMENT | builtin:naming-convention | |
| 147 | node/naming/prefer-named-exports-for-utilities-default-exports-for-pages-com | JUDGMENTAL | TO-IMPLEMENT | builtin:export-style-by-path | shared w/ #365 |
| 148 | node/promises/then-callbacks-must-always-return-a-value | MECHANICAL | TO-IMPLEMENT | builtin:then-must-return | |
| 149 | node/promises/promises-must-be-returned-awaited-or-catch-d | MECHANICAL/JUDGMENTAL | MERGED into #138 | builtin:floating-promise | |
| 150 | node/promises/no-nested-then-flatten-with-chaining-or-async-await | MECHANICAL | TO-IMPLEMENT | builtin:nested-then | |
| 151 | node/promises/no-return-wrapping-don-t-wrap-a-promise-in-promise-resolve | MECHANICAL | TO-IMPLEMENT | builtin:promise-resolve-wrap | |
| 152 | node/promises/don-t-return-from-a-finally-block | MECHANICAL | TO-IMPLEMENT | builtin:return-in-finally | |
| 153 | node/promises/prefer-await-over-then-in-async-functions | MECHANICAL | TO-IMPLEMENT | builtin:then-in-async-fn | |
| 154 | node/promises/promise-constructor-param-names-must-be-resolve-reject | MECHANICAL | TO-IMPLEMENT | builtin:promise-param-names | eslint promise/param-names already SHIPPED — verify overlap before implementing |
| 155 | node/reliability/cap-concurrency-on-batched-external-calls-using-p-map-or-equival | JUDGMENTAL | TO-IMPLEMENT | builtin:unbounded-promise-all-map | |
| 156 | node/reliability/apply-circuit-breakers-on-calls-to-external-dependencies | JUDGMENTAL | TO-IMPLEMENT | builtin:no-circuit-breaker | shared w/ #132 family |

## E. React (157–193)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 157 | react/architecture/check-quarry-for-higher-order-experience-cloud-layout-components | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:hand-rolled-shell-layout | |
| 158 | react/architecture/avoid-prop-drilling-beyond-two-levels-use-context-or-a-state-lib | JUDGMENTAL | TO-IMPLEMENT | builtin:prop-drilling | |
| 159 | react/components/don-t-use-array-index-as-key-when-items-can-reorder-insert | MECHANICAL | SHIPPED (ESLint) | eslint:react/jsx-key | |
| 160 | react/components/components-are-pascalcase-files-match-component-name | MECHANICAL | TO-IMPLEMENT | builtin:component-pascalcase | |
| 161 | react/components/boolean-props-are-isx-hasx-shouldx-never-just-x | JUDGMENTAL | TO-IMPLEMENT | builtin:boolean-prop-naming | |
| 162 | react/components/no-leaked-render-short-circuit-booleans-numbers-in-jsx | MECHANICAL | TO-IMPLEMENT | builtin:leaked-render | |
| 163 | react/components/no-components-defined-inside-other-components | MECHANICAL | TO-IMPLEMENT | builtin:nested-component-def | |
| 164 | react/components/pass-children-as-jsx-not-as-a-prop-named-children | MECHANICAL | TO-IMPLEMENT | builtin:children-prop | |
| 165 | react/components/no-finddomnode-it-s-deprecated-use-refs | MECHANICAL | TO-IMPLEMENT | regex | |
| 166 | react/components/write-new-components-as-function-components-avoid-class-componen | MECHANICAL | TO-IMPLEMENT | builtin:class-component | |
| 167 | react/hooks/hooks-rules-only-at-top-level-only-from-react-functions | MECHANICAL | SHIPPED (ESLint) | eslint:react-hooks/rules-of-hooks | |
| 168 | react/hooks/exhaustive-deps-every-used-value-goes-in-the-dep-array | MECHANICAL | SHIPPED (ESLint) | eslint:react-hooks/exhaustive-deps | |
| 169 | react/hooks/no-inline-object-array-literals-as-props-on-memoed-children | JUDGMENTAL | TO-IMPLEMENT | builtin:inline-prop-on-memoed (repo) | |
| 170 | react/hooks/stable-handlers-via-usecallback-when-passed-to-memoed-children | JUDGMENTAL | MERGED into #169 | builtin:inline-prop-on-memoed (repo) | |
| 171 | react/hooks/usememo-only-for-measurably-expensive-computation | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:trivial-usememo | |
| 172 | react/hooks/useid-for-ssr-safe-and-a11y-ids-never-math-random | MECHANICAL | MERGED into #111 | builtin:math-random-ids | |
| 173 | react/hooks/no-fetching-in-render-use-useeffect-suspense-or-a-query-lib | MECHANICAL | TO-IMPLEMENT | builtin:fetch-in-render | |
| 174 | react/hooks/code-split-with-lazy-suspense-for-routes-and-heavy-components | JUDGMENTAL | TO-IMPLEMENT | builtin:no-lazy-route | |
| 175 | react/hooks/virtualize-long-lists-100-items | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:unvirtualized-list | |
| 176 | react/hooks/no-bind-in-jsx-use-usecallback-or-class-methods | MECHANICAL | TO-IMPLEMENT | builtin:bind-in-jsx | |
| 177 | react/i18n/never-hardcode-ui-strings-all-visible-text-must-be-externalized- | JUDGMENTAL | TO-IMPLEMENT | builtin:hardcoded-ui-string | |
| 178 | react/jsx/sanitize-before-dangerouslysetinnerhtml-or-skip-it-entirely | MECHANICAL | TO-IMPLEMENT (canonical) | builtin:sanitize-dangerously-set-inner-html | merged with #106 |
| 179 | react/jsx/prefer-fragment-shorthand-over-wrapping-div-when-no-styling-need | JUDGMENTAL | TO-IMPLEMENT | builtin:unnecessary-wrapping-div | |
| 180 | react/jsx/target-blank-must-include-rel-noopener-noreferrer | MECHANICAL | TO-IMPLEMENT | builtin:target-blank-rel | |
| 181 | react/jsx/no-useless-fragments-with-one-child | MECHANICAL | TO-IMPLEMENT | builtin:single-child-fragment | |
| 182 | react/jsx/self-close-tags-with-no-children | MECHANICAL | TO-IMPLEMENT | builtin:non-self-closing-empty-tag | |
| 183 | react/jsx/every-button-needs-an-explicit-type-attribute | MECHANICAL | TO-IMPLEMENT | builtin:button-missing-type | |
| 184 | react/jsx/never-mutate-state-directly-use-the-setter | JUDGMENTAL | TO-IMPLEMENT | builtin:direct-state-mutation | |
| 185 | react/jsx/void-dom-elements-img-br-input-cannot-have-children | MECHANICAL | TO-IMPLEMENT | builtin:void-element-children | |
| 186 | react/react19/use-the-react-19-use-hook-for-promises-and-context | JUDGMENTAL | TO-IMPLEMENT | builtin:manual-promise-to-state | |
| 187 | react/safety/wrap-routes-in-error-boundaries-uncaught-errors-blank-the-page | MECHANICAL | TO-IMPLEMENT | builtin:route-no-error-boundary | |
| 188 | react/safety/iframes-must-specify-a-sandbox-attribute | MECHANICAL | TO-IMPLEMENT | builtin:iframe-no-sandbox | |
| 189 | react/setup/wrap-the-app-in-a-spectrum-provider-with-theme-locale | MECHANICAL | TO-IMPLEMENT | builtin:no-spectrum-provider (repo) | |
| 190 | react/setup/prefer-react-spectrum-components-over-hand-rolled-ui | JUDGMENTAL (P3) | TO-IMPLEMENT | builtin:hand-rolled-ui-idiom | |
| 191 | react/setup/build-custom-components-with-react-aria-react-stately-hooks | JUDGMENTAL (P3) | MERGED into #190 | builtin:hand-rolled-ui-idiom | |
| 192 | react/setup/handle-unified-shell-iframe-constraints-resize-csp-and-shell-api | MECHANICAL | TO-IMPLEMENT | builtin:no-exc-app-import | |
| 193 | react/setup/no-native-extension-points-for-frame-io-acrobat-etc-call-apis-an | JUDGMENTAL | MERGED into #27 | yaml-path:extension-point-enum | |

## F. Security (194–229)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 194 | security/adobe/every-web-action-must-declare-require-adobe-auth | MECHANICAL | SHIPPED | yaml-path:require-auth | |
| 195 | security/supply-chain/no-hard-coded-api-keys-in-source-params-git-history | MECHANICAL | SHIPPED (extend for history) | builtin:inline-secret (+P4 git-log extension) | |
| 196 | security/supply-chain/pin-exact-versions-for-security-sensitive-packages-no-semver-ran | MECHANICAL | SHIPPED | builtin:pin-versions | |
| 197 | security/eslint-security/no-bracket-access-on-user-controlled-keys-object-injection | MECHANICAL | SHIPPED (ESLint) | eslint:security/detect-object-injection | |
| 198 | security/eslint-security/no-dynamic-require-non-literal-require | MECHANICAL | SHIPPED (ESLint) | eslint:security/detect-non-literal-require | |
| 199 | security/eslint-security/no-dynamic-regex-construction-without-anchoring | MECHANICAL | SHIPPED (ESLint) | eslint:security/detect-non-literal-regexp | |
| 200 | security/eslint-security/no-dynamic-fs-paths-from-user-input-non-literal-fs-filename | MECHANICAL | BUG-FIXED | eslint:security/detect-non-literal-fs-filename | was mapped but never enabled — fixed in eslint-runner.ts |
| 201 | security/eslint-security/no-pseudorandombytes-for-security-sensitive-values | MECHANICAL | BUG-FIXED | eslint:security/detect-pseudoRandomBytes | was mapped but never enabled — fixed in eslint-runner.ts |
| 202 | security/eslint-security/use-crypto-timingsafeequal-for-token-hmac-comparison | JUDGMENTAL | TO-IMPLEMENT | builtin:timing-unsafe-compare | widen beyond ESLint's name-only heuristic |
| 203 | security/adobe/do-not-disable-require-adobe-auth-without-a-documented-review | JUDGMENTAL (P1) | TO-IMPLEMENT | yaml-path or builtin:require-auth-false-undocumented | |
| 204 | security/adobe/never-store-secrets-in-state-sdk-use-secret-vault | MECHANICAL | TO-IMPLEMENT | builtin:secret-in-state-put | |
| 205 | security/adobe/default-params-are-encrypted-at-rest-never-log-them | MECHANICAL | TO-IMPLEMENT | builtin:log-raw-params | |
| 206 | security/adobe/add-i-o-management-api-service-for-read-organizations-scope | MECHANICAL | TO-IMPLEMENT | builtin:missing-io-mgmt-scope (repo) | |
| 207 | security/adobe/validate-data-residency-for-stored-files-especially-sensitive-da | JUDGMENTAL (P1+P5) | TO-IMPLEMENT | builtin:data-residency-unpinned | |
| 208 | security/adobe/cors-access-control-allow-origin-must-not-be-on-authenticated-ac | MECHANICAL | TO-IMPLEMENT | builtin:cors-wildcard-authenticated | |
| 209 | security/adobe/validate-x-gw-ims-org-id-for-tenant-isolation-in-multi-org-deplo | JUDGMENTAL | TO-IMPLEMENT (canonical) | builtin:missing-tenant-isolation | merged with #215 |
| 210 | security/adobe/request-only-the-minimum-ims-oauth-scopes-needed | JUDGMENTAL (P5) | TO-IMPLEMENT | builtin:unused-oauth-scope (repo) | |
| 211 | security/adobe/browser-spas-use-authorization-code-pkce-never-the-implicit-flow | MECHANICAL | TO-IMPLEMENT | builtin:implicit-flow-or-no-pkce | |
| 212 | security/adobe/never-put-client-secret-technical-account-private-keys-or-s2s-cr | MECHANICAL | TO-IMPLEMENT | builtin:inline-secret (scoped to web-src) | reuse inline-secret |
| 213 | security/adobe/tokens-stored-in-memory-or-bff-managed-cookies-never-localstorag | MECHANICAL | TO-IMPLEMENT | builtin:token-in-localstorage | |
| 214 | security/eslint-security/reject-unicode-bidi-characters-in-source-trojan-source-attack | MECHANICAL | TO-IMPLEMENT | builtin:bidi-chars (byte scan) | eslint-plugin-security has detect-bidi-characters — verify vs. reuse before implementing |
| 215 | security/owasp-api/verify-resource-ownership-before-returning-it-idor-defense | JUDGMENTAL | MERGED into #209 | builtin:missing-tenant-isolation | |
| 216 | security/owasp-api/no-deep-merge-of-user-input-into-plain-objects-prototype-polluti | MECHANICAL | TO-IMPLEMENT | builtin:unvalidated-deep-merge | |
| 217 | security/owasp-api/ssrf-defense-never-make-outbound-http-to-user-supplied-urls | MECHANICAL | TO-IMPLEMENT | builtin:ssrf-user-url | |
| 218 | security/owasp-api/never-spread-params-directly-into-a-database-write-mass-assignme | MECHANICAL | TO-IMPLEMENT | builtin:mass-assignment | |
| 219 | security/owasp-web/force-https-for-all-third-party-integrations | MECHANICAL | TO-IMPLEMENT | builtin:http-literal-url | |
| 220 | security/owasp-web/sanitize-all-user-controlled-html-before-render | MECHANICAL | MERGED into #178 | builtin:sanitize-dangerously-set-inner-html (+innerHTML regex variant) | |
| 221 | security/owasp-web/use-parameterized-queries-prepared-statements-only | MECHANICAL | TO-IMPLEMENT | builtin:sql-string-concat | |
| 222 | security/owasp-web/hash-passwords-with-bcrypt-or-argon2-never-sha-md5 | MECHANICAL | TO-IMPLEMENT | builtin:weak-password-hash | |
| 223 | security/owasp-web/set-content-security-policy-on-web-responses | MECHANICAL | TO-IMPLEMENT | builtin:missing-csp-header | |
| 224 | security/owasp-web/tls-certificate-verification-must-never-be-disabled | MECHANICAL | TO-IMPLEMENT | builtin:tls-verification-disabled | |
| 225 | security/supply-chain/npm-audit-must-be-clean-of-high-critical-before-deploy | MECHANICAL | DEFERRED — needs your answer on network boundary (see Stage 1 NEEDS INPUT #5) | builtin:npm-audit (repo) | |
| 226 | security/supply-chain/app-builder-apps-require-admin-approval-before-wide-release | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:release-approval-doc (repo) | |
| 227 | security/supply-chain/document-what-data-your-app-accesses-or-stores-review-ready-data | JUDGMENTAL (P1) | TO-IMPLEMENT (canonical) | builtin:data-access-doc (repo) | merged with #384 |
| 228 | security/supply-chain/log-security-relevant-events-authentication-outcomes-and-authori | MECHANICAL | TO-IMPLEMENT | builtin:unlogged-auth-denial | |
| 229 | security/supply-chain/never-put-api-keys-or-tokens-in-url-query-parameters | MECHANICAL | TO-IMPLEMENT | builtin:secret-in-url-query | |

## G. TypeScript (230–265)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 230 | typescript/type-safety/no-explicit-any | MECHANICAL | SHIPPED (ESLint) | eslint:@typescript-eslint/no-explicit-any | |
| 231 | typescript/type-safety/avoid-non-null-assertion | MECHANICAL | SHIPPED (ESLint) | eslint:@typescript-eslint/no-non-null-assertion | |
| 232 | typescript/style/type-only-imports-use-the-type-modifier-consistent-type-imports | MECHANICAL | SHIPPED (ESLint) | eslint:@typescript-eslint/consistent-type-imports | canonical for dedupe w/ #245 |
| 233 | typescript/async/no-floating-promises-every-promise-must-be-handled | MECHANICAL | DONE-APPROX (syntax-only, no type info) | builtin:ts-floating-promise-approx | typed linting not wired (no parserOptions.project) |
| 234 | typescript/async/no-misused-promises-in-conditionals-or-loops | MECHANICAL | TO-IMPLEMENT (syntax-only approx) | builtin:ts-misused-promise-approx | |
| 235 | typescript/async/await-thenable-values-not-non-promises | MECHANICAL | TO-IMPLEMENT (syntax-only approx) | builtin:ts-await-non-promise-approx | |
| 236 | typescript/async/functions-that-return-promises-should-be-marked-async | MECHANICAL | TO-IMPLEMENT (syntax-only approx) | builtin:ts-unmarked-async-approx | |
| 237 | typescript/async/type-async-function-return-as-promise-t-not-just-t | MECHANICAL | TO-IMPLEMENT | builtin:async-return-type-not-wrapped | |
| 238 | typescript/modern/prefer-interface-over-type-for-object-shapes | MECHANICAL | TO-IMPLEMENT | builtin:type-alias-object-shape | |
| 239 | typescript/modern/mark-class-fields-readonly-when-not-reassigned | MECHANICAL | TO-IMPLEMENT | builtin:non-readonly-field | |
| 240 | typescript/modern/naming-pascalcase-types-camelcase-values-upper-for-enum-constant | MECHANICAL | MERGED into #146 | builtin:naming-convention | |
| 241 | typescript/modern/avoid-enum-prefer-const-asserted-union | MECHANICAL | TO-IMPLEMENT | builtin:ts-enum-declaration | |
| 242 | typescript/modern/prefer-satisfies-over-type-annotation-for-literal-config | MECHANICAL | TO-IMPLEMENT (syntax-only approx) | builtin:prefer-satisfies-approx | |
| 243 | typescript/modern/use-unified-function-signatures-avoid-overload-duplication | MECHANICAL | TO-IMPLEMENT | builtin:overload-duplication | |
| 244 | typescript/modern/switch-statements-must-be-exhaustive-over-discriminated-unions | MECHANICAL | TO-IMPLEMENT (syntax-only approx) | builtin:switch-exhaustiveness-approx | |
| 245 | typescript/style/use-type-only-imports-for-types | MECHANICAL | MERGED into #232 | eslint:@typescript-eslint/consistent-type-imports | per your dedupe instruction |
| 246 | typescript/style/don-t-disable-typescript-eslint-with-ts-ignore-use-ts-expect-err | MECHANICAL | TO-IMPLEMENT | regex | |
| 247 | typescript/style/no-confusing-void-expressions | MECHANICAL | TO-IMPLEMENT (syntax-only approx) | builtin:confusing-void-approx | |
| 248 | typescript/style/no-meaningless-void-operator | MECHANICAL | TO-IMPLEMENT | builtin:meaningless-void | |
| 249 | typescript/style/no-unnecessary-conditions-typescript-proved-them-already | MECHANICAL | SKIPPED — needs real type info | — | documented as architecture gap, not implemented this pass |
| 250 | typescript/style/prefer-string-startswith-endswith-over-slice-indexof | MECHANICAL | TO-IMPLEMENT | builtin:indexof-prefix-suffix | |
| 251 | typescript/style/prefer-array-includes-over-indexof | MECHANICAL | TO-IMPLEMENT | builtin:indexof-membership | |
| 252 | typescript/style/prefer-array-find-over-filter-0 | MECHANICAL | TO-IMPLEMENT | builtin:filter-zero-index | |
| 253 | typescript/style/strict-boolean-expressions-no-implicit-coercion-in-conditions | MECHANICAL | SKIPPED — needs real type info | — | documented as architecture gap |
| 254 | typescript/tsconfig/tsconfig-enable-strict-mode-no-exceptions | MECHANICAL | TO-IMPLEMENT | builtin:tsconfig-strict (repo) | |
| 255 | typescript/tsconfig/tsconfig-enable-nouncheckedindexedaccess | MECHANICAL | TO-IMPLEMENT | builtin:tsconfig-unchecked-indexed-access (repo) | |
| 256 | typescript/type-safety/no-unsafe-assignment-unsafe-call-unsafe-member-access | MECHANICAL | SKIPPED — needs real type info | — | documented as architecture gap |
| 257 | typescript/type-safety/no-unsafe-return | MECHANICAL | SKIPPED — needs real type info | — | documented as architecture gap |
| 258 | typescript/type-safety/no-unsafe-argument | MECHANICAL | SKIPPED — needs real type info | — | documented as architecture gap |
| 259 | typescript/type-safety/no-non-null-assertion-on-optional-chain | MECHANICAL | TO-IMPLEMENT | builtin:non-null-on-optional-chain | |
| 260 | typescript/type-safety/prefer-nullish-coalescing-over-logical-or | MECHANICAL | TO-IMPLEMENT (syntax-only approx) | builtin:logical-or-default-approx | |
| 261 | typescript/type-safety/prefer-optional-chaining-over-manual-null-guards | MECHANICAL | TO-IMPLEMENT | builtin:manual-null-guard-chain | |
| 262 | typescript/type-safety/no-unnecessary-type-assertions | MECHANICAL | SKIPPED — needs real type info | — | documented as architecture gap |
| 263 | typescript/type-safety/prefer-record-string-t-or-map-over-index-signatures-for-unknown- | MECHANICAL | TO-IMPLEMENT | builtin:index-signature-dict | |
| 264 | typescript/type-safety/no-redundant-or-duplicate-union-intersection-members | MECHANICAL | TO-IMPLEMENT | builtin:duplicate-union-member | |
| 265 | typescript/type-safety/don-t-pass-non-strings-to-string-concatenation-without-explicit- | MECHANICAL | SKIPPED — needs real type info | — | documented as architecture gap |

## H. Stylistic (266–290)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 266 | stylistic/numbers/parseint-requires-a-radix | MECHANICAL | SHIPPED (ESLint) | eslint:radix | |
| 267 | stylistic/control-flow/no-nested-ternaries-use-if-else-or-a-lookup | MECHANICAL | TO-IMPLEMENT | builtin:nested-ternary | |
| 268 | stylistic/control-flow/no-unneeded-ternaries | MECHANICAL | TO-IMPLEMENT | builtin:unneeded-ternary | |
| 269 | stylistic/control-flow/no-empty-blocks-at-least-a-comment-explaining-why | MECHANICAL | TO-IMPLEMENT | builtin:empty-block-no-comment | |
| 270 | stylistic/control-flow/no-useless-return-statements | MECHANICAL | TO-IMPLEMENT | builtin:useless-return | |
| 271 | stylistic/control-flow/don-t-use-else-after-return-flat-is-better-than-nested | MECHANICAL | TO-IMPLEMENT | builtin:else-after-return | |
| 272 | stylistic/destructuring/prefer-destructuring-for-object-array-access | MECHANICAL | TO-IMPLEMENT | builtin:repeated-member-access | |
| 273 | stylistic/destructuring/prefer-spread-over-function-prototype-apply | MECHANICAL | TO-IMPLEMENT | builtin:function-apply | |
| 274 | stylistic/destructuring/use-object-shorthand-syntax | MECHANICAL | TO-IMPLEMENT | builtin:object-shorthand | |
| 275 | stylistic/functions/don-t-reassign-function-parameters | MECHANICAL | TO-IMPLEMENT | builtin:param-reassign | |
| 276 | stylistic/functions/prefer-arrow-callbacks-for-short-anonymous-functions | MECHANICAL | TO-IMPLEMENT | builtin:anonymous-function-callback | |
| 277 | stylistic/functions/no-return-await-return-the-promise-directly | MECHANICAL | TO-IMPLEMENT | builtin:return-await-outside-try | |
| 278 | stylistic/functions/no-useless-catch-that-just-rethrows | MECHANICAL | MERGED into #139 | builtin:pure-rethrow-catch | |
| 279 | stylistic/functions/no-restricted-syntax-codify-project-conventions | MECHANICAL | DEFERRED — needs selector list (Stage 1 NEEDS INPUT #3) | regex/builtin:configurable | |
| 280 | stylistic/numbers/no-magic-numbers-extract-named-constants | MECHANICAL | DEFERRED — needs ignore-list (Stage 1 NEEDS INPUT #3) | builtin:magic-number | |
| 281 | stylistic/numbers/prefer-numeric-separators-on-long-literals | MECHANICAL | TO-IMPLEMENT | builtin:numeric-separator | |
| 282 | stylistic/operators/throw-error-objects-not-strings | MECHANICAL | TO-IMPLEMENT | builtin:throw-non-error | |
| 283 | stylistic/operators/don-t-use-implicit-coercion-x-x-x | MECHANICAL | TO-IMPLEMENT | builtin:implicit-coercion | |
| 284 | stylistic/operators/avoid-mixed-operators-without-parentheses | MECHANICAL | TO-IMPLEMENT | builtin:mixed-operators | |
| 285 | stylistic/operators/no-yoda-conditions-variable-on-the-left | MECHANICAL | TO-IMPLEMENT | builtin:yoda-condition | |
| 286 | stylistic/operators/operator-assignment-shorthand-x-1-not-x-x-1 | MECHANICAL | TO-IMPLEMENT | builtin:operator-assignment | |
| 287 | stylistic/operators/use-dot-notation-when-the-property-name-is-a-valid-identifier | MECHANICAL | TO-IMPLEMENT | builtin:bracket-notation-valid-ident | |
| 288 | stylistic/operators/no-unused-expressions-they-re-dead-code | MECHANICAL | TO-IMPLEMENT | builtin:unused-expression | |
| 289 | stylistic/strings/prefer-template-literals-over-string-concatenation | MECHANICAL | TO-IMPLEMENT | builtin:string-concat | |
| 290 | stylistic/strings/no-useless-concatenation-of-string-literals | MECHANICAL | TO-IMPLEMENT | builtin:literal-concat | |

## I. a11y (291–308)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 291 | a11y/content/every-img-has-a-meaningful-alt-empty-alt-for-decorative | MECHANICAL | SHIPPED (ESLint) | eslint:jsx-a11y/alt-text | |
| 292 | a11y/aria/all-aria-props-must-be-valid-aria-attributes | MECHANICAL | SHIPPED (ESLint) | eslint:jsx-a11y/aria-props | already enabled |
| 293 | a11y/aria/aria-role-attribute-must-be-a-valid-role | MECHANICAL | SHIPPED (ESLint) | eslint:jsx-a11y/aria-role | already enabled |
| 294 | a11y/aria/aria-properties-must-match-the-role-s-required-and-supported-set | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/role-has-required-aria-props+role-supports-aria-props | |
| 295 | a11y/aria/form-labels-must-associate-with-their-control | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/label-has-associated-control | |
| 296 | a11y/aria/don-t-put-aria-hidden-on-focusable-elements | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/no-aria-hidden-on-focusable | |
| 297 | a11y/content/anchors-must-have-href-and-meaningful-text | JUDGMENTAL | MAPPING-ADDED (structural) + TO-IMPLEMENT (text denylist) | eslint:jsx-a11y/anchor-is-valid + builtin:vague-link-text | |
| 298 | a11y/content/html-must-have-a-lang-attribute | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/html-has-lang | |
| 299 | a11y/content/no-redundant-alt-text-image-of-picture-of | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/img-redundant-alt | |
| 300 | a11y/interaction/click-events-on-non-interactive-elements-need-keyboard-equivalen | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/click-events-have-key-events | |
| 301 | a11y/interaction/don-t-use-autofocus-disorients-keyboard-and-screen-reader-users | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/no-autofocus | |
| 302 | a11y/interaction/tabindex-must-be-0-or-1-never-positive | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/tabindex-no-positive | |
| 303 | a11y/interaction/static-elements-with-click-handlers-need-role-keyboard | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/no-static-element-interactions | |
| 304 | a11y/interaction/non-interactive-elements-should-not-have-tabindex | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/no-noninteractive-tabindex | |
| 305 | a11y/interaction/interactive-elements-must-support-focus | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/interactive-supports-focus | |
| 306 | a11y/media/media-elements-need-captions-or-controls | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/media-has-caption | |
| 307 | a11y/structure/no-distracting-elements-marquee-blink | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/no-distracting-elements | |
| 308 | a11y/structure/iframes-must-have-a-title-attribute | MECHANICAL | MAPPING-ADDED | eslint:jsx-a11y/iframe-has-title | |

## J. Testing (309–325)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 309 | testing/coverage/cover-at-least-80-of-branches-in-actions | JUDGMENTAL | TO-IMPLEMENT | builtin:coverage-threshold-config (repo) | |
| 310 | testing/coverage/snapshot-tests-for-stable-json-shapes-only | JUDGMENTAL | TO-IMPLEMENT | builtin:snapshot-of-render | |
| 311 | testing/fixtures/test-against-the-cloudevents-spec-for-i-o-events-handler-payloads | MECHANICAL | TO-IMPLEMENT | builtin:cloudevents-fixture-shape | |
| 312 | testing/layout/test-files-mirror-source-structure-under-test-actions | MECHANICAL | TO-IMPLEMENT | builtin:test-layout-mirror (repo) | |
| 313 | testing/layout/use-aio-app-test-not-raw-jest | MECHANICAL | TO-IMPLEMENT | builtin:raw-jest-ci (repo) | shared w/ #345 |
| 314 | testing/layout/test-descriptions-describe-behavior-not-implementation | JUDGMENTAL | TO-IMPLEMENT | builtin:vague-test-title | |
| 315 | testing/mocking/mock-adobe-aio-sdk-core-logger-as-a-jest-fn | MECHANICAL | TO-IMPLEMENT | builtin:unmocked-core-logger | |
| 316 | testing/mocking/reset-mocks-in-beforeeach-never-let-state-leak-between-tests | MECHANICAL | TO-IMPLEMENT | builtin:missing-clear-mocks | |
| 317 | testing/mocking/mock-external-http-never-hit-real-apis-in-unit-tests | JUDGMENTAL | TO-IMPLEMENT | builtin:unmocked-http-in-unit-test | |
| 318 | testing/rtl/react-tests-use-testing-library-queries-never-enzyme-or-raw-dom | MECHANICAL | TO-IMPLEMENT | builtin:enzyme-import | |
| 319 | testing/rtl/test-user-behavior-not-implementation-details | JUDGMENTAL | TO-IMPLEMENT | builtin:implementation-detail-test | |
| 320 | testing/rtl/use-waitfor-findby-for-async-ui-never-raw-settimeout | MECHANICAL | TO-IMPLEMENT | builtin:raw-settimeout-in-test | |
| 321 | testing/strategy/every-action-test-must-verify-main-is-exported | MECHANICAL | TO-IMPLEMENT | builtin:missing-main-export-test | |
| 322 | testing/strategy/test-the-missing-input-400-path | JUDGMENTAL | TO-IMPLEMENT | builtin:missing-400-path-test | |
| 323 | testing/strategy/test-the-error-response-shape-error-statuscode-body | MECHANICAL | TO-IMPLEMENT | builtin:missing-error-shape-test | |
| 324 | testing/strategy/e2etest-folder-for-cross-action-and-frontend-to-action-flows | MECHANICAL | TO-IMPLEMENT | builtin:e2etest-folder (repo) | |
| 325 | testing/strategy/separate-smoke-tests-from-e2e-smoke-verifies-deploy-e2e-verifies | MECHANICAL | TO-IMPLEMENT | builtin:smoke-e2e-separation (repo) | |

## K. Runtime (326–340)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 326 | runtime/loading/resource-hints-link-rel-preconnect-for-known-third-party-origins | MECHANICAL | TO-IMPLEMENT | builtin:missing-preconnect (repo) | |
| 327 | runtime/loading/service-worker-registers-with-valid-scope-pwa-only | JUDGMENTAL | TO-IMPLEMENT | builtin:sw-scope | |
| 328 | runtime/performance/no-foit-fonts-must-render-with-font-display-swap-or-optional | MECHANICAL | TO-IMPLEMENT | builtin:font-display-missing | CSS parse |
| 329 | runtime/performance/no-detached-dom-nodes-after-navigation | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:missing-effect-cleanup | |
| 330 | runtime/performance/no-layout-thrashing-purple-recalculate-layout-blocks-under-50ms- | MECHANICAL | TO-IMPLEMENT | builtin:layout-thrashing | |
| 331 | runtime/performance/first-paint-js-bundle-200kb-gzipped | MECHANICAL | DEFERRED — needs your answer on build-artifact boundary (Stage 1 NEEDS INPUT #6) | builtin:bundle-size (repo) | |
| 332 | runtime/quality/html-lang-matches-the-user-facing-locale-at-runtime | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:locale-lang-sync | |
| 333 | runtime/quality/zero-console-error-calls-on-the-golden-path-traversal | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:golden-path-risk-factors (repo) | |
| 334 | runtime/security/zero-refused-to-load-csp-violations-in-the-browser-console | MECHANICAL | TO-IMPLEMENT | builtin:csp-uncovered-resource (repo) | |
| 335 | runtime/security/zero-mixed-content-warnings-on-production-https-pages | MECHANICAL | TO-IMPLEMENT | builtin:mixed-content (repo) | |
| 336 | runtime/security/authenticated-routes-carry-the-expected-authorization-header-at- | MECHANICAL | TO-IMPLEMENT | builtin:missing-auth-header-fetch | |
| 337 | runtime/vitals/lighthouse-performance-score-90-on-the-production-build | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:lighthouse-anti-pattern-checklist (repo) | |
| 338 | runtime/vitals/largest-contentful-paint-2-5s-on-slow-3g | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:lcp-risk-factors (repo) | |
| 339 | runtime/vitals/cumulative-layout-shift-0-1-across-all-top-routes | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:cls-risk-factors (repo) | |
| 340 | runtime/vitals/interaction-to-next-paint-200ms-on-primary-interactions | JUDGMENTAL (P2) | MERGED into #131 | builtin:cpu-bound-sync-handler | scoped to event handlers |

## L. Deployment (341–357)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 341 | deployment/environments/deploy-to-a-stage-workspace-before-production-never-straight-to- | MECHANICAL | TO-IMPLEMENT | builtin:no-stage-before-prod (repo) | |
| 342 | deployment/environments/use-environment-specific-workspaces-not-branches-for-env-separat | JUDGMENTAL (P5) | TO-IMPLEMENT | builtin:env-branching-logic | |
| 343 | deployment/pipeline/pre-deploy-gate-code-review-tests-security-scan-perf-check-docs | MECHANICAL | TO-IMPLEMENT | builtin:missing-predeploy-gate (repo) | |
| 344 | deployment/pipeline/use-deployment-hooks-post-app-deploy-js-for-verification-not-for | MECHANICAL | TO-IMPLEMENT | builtin:deploy-hook-mutates-state | |
| 345 | deployment/pipeline/ci-must-run-aio-app-test-not-just-jest | MECHANICAL | MERGED into #313 | builtin:raw-jest-ci (repo) | |
| 346 | deployment/pipeline/use-aio-app-deploy-no-build-when-the-build-runs-in-a-separate-ci | MECHANICAL | TO-IMPLEMENT | builtin:missing-no-build-flag (repo) | |
| 347 | deployment/pipeline/each-git-branch-or-environment-maps-to-specific-aio-project-and- | MECHANICAL | TO-IMPLEMENT | builtin:ungated-shared-secrets (repo) | |
| 348 | deployment/releases/don-t-undeploy-a-production-action-version-it-instead | MECHANICAL | TO-IMPLEMENT | builtin:undeploy-prod-action (repo) | |
| 349 | deployment/releases/tag-and-document-every-prod-release-single-source-of-truth | JUDGMENTAL (P4) | TO-IMPLEMENT | builtin:untagged-prod-deploy (repo) | |
| 350 | deployment/rollback/monitor-activation-logs-after-deploy-error-rate-p95-throttling | MECHANICAL | TO-IMPLEMENT | builtin:missing-post-deploy-monitoring (repo) | |
| 351 | deployment/rollback/have-a-one-command-rollback-never-redeploy-the-previous-commit-u | MECHANICAL | TO-IMPLEMENT | builtin:missing-rollback-script (repo) | |
| 352 | deployment/secrets/enforce-secrets-scanning-in-pre-commit-hooks-block-secrets-befor | MECHANICAL | TO-IMPLEMENT | builtin:missing-precommit-secrets-scan (repo) | |
| 353 | deployment/secrets/generate-oauth-tokens-in-ci-via-aio-apps-action-oauth-sts-don-t- | MECHANICAL | TO-IMPLEMENT | builtin:static-ci-token (repo) | |
| 354 | deployment/secrets/never-check-in-console-json-private-keys-or-aio-credentials-file | MECHANICAL | TO-IMPLEMENT | builtin:secrets-git (extend pattern list) | reuse secrets-git builtin, add file patterns |
| 355 | deployment/slos/action-response-time-slo-p95-500ms | JUDGMENTAL (P2) | TO-IMPLEMENT | builtin:latency-risk-action | |
| 356 | deployment/slos/event-processing-time-slo-p95-1s | JUDGMENTAL (P2) | MERGED into #26 | builtin:events-batch-vs-sync | |
| 357 | deployment/slos/reuse-module-scope-http-clients-and-sdk-instances-across-warm-in | MECHANICAL | MERGED into #35 | builtin:no-module-scope-io (inverse condition) | |

## M. Imports (358–369)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 358 | imports/ordering/imports-go-at-the-top-of-the-file-first | MECHANICAL | SHIPPED | builtin:imports-top | canonical for #136 |
| 359 | imports/discipline/file-can-t-import-itself-no-self-import | MECHANICAL | SHIPPED (ESLint) | eslint:import/no-self-import | |
| 360 | imports/discipline/no-duplicate-imports-merge-them | MECHANICAL | SHIPPED (ESLint) | eslint:import/no-duplicates | |
| 361 | imports/dependencies/imported-modules-must-be-in-package-json-no-extraneous-deps | MECHANICAL | TO-IMPLEMENT | builtin:extraneous-dependency (repo) | |
| 362 | imports/dependencies/imported-modules-must-resolve-no-unresolved | MECHANICAL | TO-IMPLEMENT | builtin:unresolved-import (repo) | |
| 363 | imports/discipline/no-circular-imports-break-the-cycle | MECHANICAL | TO-IMPLEMENT | builtin:circular-import (repo) | |
| 364 | imports/exports/default-exports-must-be-named-at-the-export-site-no-anonymous-de | MECHANICAL | TO-IMPLEMENT | builtin:anonymous-default-export | |
| 365 | imports/exports/prefer-named-exports-for-utilities-default-exports-for-the-one-t | JUDGMENTAL | MERGED into #147 | builtin:export-style-by-path | |
| 366 | imports/exports/avoid-namespace-imports-import-as | MECHANICAL | TO-IMPLEMENT | builtin:namespace-import | |
| 367 | imports/ordering/blank-line-after-the-import-block-newline-after-import | MECHANICAL | TO-IMPLEMENT | builtin:no-blank-line-after-imports | |
| 368 | imports/ordering/group-imports-builtin-external-internal-parent-sibling | MECHANICAL | TO-IMPLEMENT | builtin:import-group-order | |
| 369 | imports/aliases/prefer-absolute-aliased-imports-over-deep-relative-paths | MECHANICAL | TO-IMPLEMENT | builtin:deep-relative-import | |

## N. Process (370–381)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 370 | process/compliance/data-residency-confirmed-for-all-pii-and-regulated-data | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:data-residency-doc (repo) | |
| 371 | process/compliance/dpia-filed-when-handling-eu-pii-or-regulated-data | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:dpia-doc (repo) | |
| 372 | process/discovery/document-business-kpis-and-seo-goals-before-kickoff | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:kpi-doc (repo) | |
| 373 | process/discovery/configure-real-user-monitoring-before-ga | MECHANICAL | TO-IMPLEMENT | builtin:missing-rum | |
| 374 | process/governance/rbac-roles-and-access-tiers-signed-off-by-security-before-ga | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:rbac-doc (repo) | |
| 375 | process/governance/genai-usage-policy-no-proprietary-code-or-customer-data-submitte | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:genai-policy-doc (repo) | |
| 376 | process/governance/vendor-and-saas-dependency-list-reviewed-by-procurement-and-secu | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:vendor-list-doc (repo) | |
| 377 | process/operations/incident-response-runbook-with-named-on-call-rotation | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:runbook-doc (repo) | |
| 378 | process/operations/post-go-live-monitoring-dashboards-reviewed-in-week-1-and-month- | JUDGMENTAL (P1+P4) | TO-IMPLEMENT | builtin:review-log-doc (repo) | |
| 379 | process/quality/screen-reader-qa-sign-off-nvda-jaws-voiceover-before-ga | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:a11y-signoff-doc (repo) | |
| 380 | process/quality/author-and-admin-training-docs-delivered-before-content-team-han | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:training-docs-dir (repo) | |
| 381 | process/quality/pre-go-live-load-test-against-expected-peak-traffic | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:load-test-doc (repo) | |

## O. Documentation (382–387)

| # | Rule ID | Class | Status | Target check id | Notes |
|---|---|---|---|---|---|
| 382 | documentation/code/jsdoc-on-every-exported-function-params-returns-throws | MECHANICAL | SHIPPED | builtin:jsdoc-export | |
| 383 | documentation/project/every-action-has-a-readme-purpose-inputs-outputs-examples | MECHANICAL | SHIPPED | builtin:action-readme | |
| 384 | documentation/project/docs-data-access-md-single-source-of-truth-for-what-data-the-app | JUDGMENTAL (P1) | MERGED into #227 | builtin:data-access-doc (repo) | per your dedupe instruction |
| 385 | documentation/releases/maintain-changelog-md-every-release-entry-semver | MECHANICAL | TO-IMPLEMENT | builtin:changelog-format (repo) | |
| 386 | documentation/releases/document-action-contracts-schema-for-inputs-outputs-errors | MECHANICAL | TO-IMPLEMENT | builtin:action-no-schema | |
| 387 | documentation/releases/sample-request-response-in-every-api-doc-copy-pasteable | JUDGMENTAL (P1) | TO-IMPLEMENT | builtin:api-doc-missing-sample (repo) | |
