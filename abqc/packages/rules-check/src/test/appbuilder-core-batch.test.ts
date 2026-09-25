import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdir, mkdtemp } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, RepoContext, FileChecker, RepoChecker } from "../types.js";

import * as auth from "../checkers/builtins/appbuilder-auth.js";
import * as deploy from "../checkers/builtins/appbuilder-deploy.js";
import * as events from "../checkers/builtins/appbuilder-events.js";
import * as integration from "../checkers/builtins/appbuilder-integration.js";
import * as misc from "../checkers/builtins/appbuilder-misc.js";

// This test file exercises the new App Builder checkers directly (calling
// `checker.check(ctx)`), rather than going through RuleLoader/parseCheckSpec/
// runTypeCheck like automatable-rules.test.ts does. Those rely on the rule
// markdown `### Check` blocks and the builtin-id registry (type-checkers/
// builtin.ts, builtins/index.ts) being wired up to point at these exports —
// that wiring is owned by a separate, concurrently-edited change and isn't
// present yet, so a registry-based test here would either fail to find the
// rules or silently test nothing. Calling the exported FileChecker/
// RepoChecker objects directly is self-contained and exercises the exact
// same `check()` logic the registry will eventually dispatch to.

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

async function fixtureContent(name: string): Promise<string> {
  return readFile(join(fixtures, name), "utf8");
}

function fileCtx(filePath: string, content: string): CheckContext {
  return {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
    actionEntryFiles: filePath.includes("/actions/") ? new Set([filePath]) : undefined,
  };
}

interface FileCase {
  label: string;
  checker: FileChecker;
  filePath: string;
  bad: string;
  good: string;
  /** Override the file path used for the good fixture (for purely path-keyed rules). */
  goodFilePath?: string;
}

const FILE_CASES: FileCase[] = [
  {
    label: "appbuilder/auth/token-generating-actions-must-be-non-web-actions",
    checker: auth.tokenActionNotWebChecker,
    filePath: "/proj/actions/pay/index.js",
    bad: "bad-token-mint.js",
    good: "good-token-mint.js",
  },
  {
    label: "appbuilder/auth/use-ims-sdk-auto-renewal-jwt-lifetime-is-24h",
    checker: auth.imsAutoRenewalChecker,
    filePath: "/proj/actions/pay/index.js",
    bad: "bad-ims-cache.js",
    good: "good-ims-cache.js",
  },
  {
    label: "appbuilder/auth/bearer-extraction-via-getbearertoken-params-utility",
    checker: auth.bearerExtractionChecker,
    filePath: "/proj/actions/pay/index.js",
    bad: "bad-bearer-raw.js",
    good: "good-bearer-raw.js",
  },
  {
    label: "appbuilder/deploy/disable-download-is-irreversible-set-deliberately",
    checker: deploy.disableDownloadUndocumentedChecker,
    filePath: "/proj/actions.config.yaml",
    bad: "bad-disable-download.yaml",
    good: "good-disable-download.yaml",
  },
  {
    label: "appbuilder/events/implement-challenge-response-for-i-o-events-handler-actions",
    checker: events.eventsChallengeResponseChecker,
    filePath: "/proj/actions/events-handler/index.js",
    bad: "bad-events-challenge.js",
    good: "good-events-challenge.js",
  },
  {
    label: "appbuilder/events/verify-i-o-events-payload-hmac-signature-before-processing",
    checker: events.eventsHmacVerifyChecker,
    filePath: "/proj/actions/events-handler/index.js",
    bad: "bad-events-hmac.js",
    good: "good-events-hmac.js",
  },
  {
    label: "appbuilder/events/prefer-i-o-events-over-polling-actions-on-cron-schedules",
    checker: events.eventsVsPollingChecker,
    filePath: "/proj/actions/sync-job/index.js",
    bad: "bad-events-polling.js",
    good: "good-events-polling.js",
  },
  {
    label: "appbuilder/events/proxy-external-3rd-party-webhooks-through-an-action-that-handles",
    checker: events.webhookProxyChecker,
    filePath: "/proj/actions/webhook-handler/index.js",
    bad: "bad-webhook-proxy.js",
    good: "good-webhook-proxy.js",
  },
  {
    label: "appbuilder/events/i-o-events-handlers-must-be-idempotent-keyed-by-event-id",
    checker: events.eventsIdempotentChecker,
    filePath: "/proj/actions/events-handler/index.js",
    bad: "bad-events-idempotent.js",
    good: "good-events-idempotent.js",
  },
  {
    // Purely path-keyed rule: content is identical, only the path differs
    // (an events-handler path vs. one that also matches /batch|journal/i).
    label: "appbuilder/events/batch-high-volume-event-streams-via-journaling-not-per-event-syn",
    checker: events.eventsBatchVsSyncChecker,
    filePath: "/proj/actions/events-handler/index.js",
    goodFilePath: "/proj/actions/events-batch-handler/index.js",
    bad: "bad-events-batch.js",
    good: "good-events-batch.js",
  },
  {
    label: "appbuilder/integration/default-runtime-is-node-js-other-languages-only-via-docker",
    checker: integration.nonNodeRuntimeChecker,
    filePath: "/proj/app.config.yaml",
    bad: "bad-non-node-runtime.yaml",
    good: "good-non-node-runtime.yaml",
  },
  {
    label: "appbuilder/integration/call-commerce-graphql-with-app-level-credentials-from-secrets-ne",
    checker: integration.commerceGraphqlAppCredsChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-graphql-app-creds.js",
    good: "good-graphql-app-creds.js",
  },
  {
    label: "appbuilder/integration/never-call-commerce-admin-graphql-with-admin-credentials-from-th",
    checker: integration.noCommerceAdminFromSpaChecker,
    filePath: "/proj/web-src/src/index.js",
    bad: "bad-spa-admin-call.js",
    good: "good-spa-admin-call.js",
  },
  {
    label: "appbuilder/integration/pass-store-view-website-context-explicitly-never-inferred-from-h",
    checker: integration.noStoreContextFromHeadersChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-store-from-headers.js",
    good: "good-store-from-headers.js",
  },
  {
    label: "appbuilder/integration/bound-page-sizes-when-calling-commerce-graphql-no-unbounded-page",
    checker: integration.boundGraphqlPagesizeChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-graphql-pagesize.js",
    good: "good-graphql-pagesize.js",
  },
  {
    label: "appbuilder/observability/use-structured-json-logging-and-forward-activation-logs-to-an-ex",
    checker: misc.structuredLoggingChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-structured-logging.js",
    good: "good-structured-logging.js",
  },
  {
    label: "appbuilder/performance/fan-out-large-workloads-split-into-parallel-sub-actions",
    checker: misc.serialAwaitInLoopChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-serial-await.js",
    good: "good-serial-await.js",
  },
  {
    label: "appbuilder/performance/defer-all-i-o-and-heavy-imports-out-of-module-scope-to-avoid-col (condition A: module-scope I/O)",
    checker: misc.noModuleScopeIoChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-module-scope-io.js",
    good: "good-module-scope-io.js",
  },
  {
    label: "appbuilder/performance/defer-all-i-o-and-heavy-imports-out-of-module-scope-to-avoid-col (condition B: client rebuilt inside main)",
    checker: misc.noModuleScopeIoChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-module-scope-client.js",
    good: "good-module-scope-client.js",
  },
  {
    label: "appbuilder/performance/pagination-loops-need-an-explicit-max-pages-safety-limit",
    checker: misc.paginationMaxPagesChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-pagination-max-pages.js",
    good: "good-pagination-max-pages.js",
  },
  {
    label: "appbuilder/performance/for-bulk-exports-write-to-files-sdk-and-return-a-url-don-t-retur",
    checker: misc.bulkExportFilesSdkChecker,
    filePath: "/proj/actions/export-orders/index.js",
    bad: "bad-bulk-export.js",
    good: "good-bulk-export.js",
  },
  {
    label: "appbuilder/runtime/error-response-shape-error-statuscode-body",
    checker: misc.errorResponseShapeChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-error-response-shape.js",
    good: "good-error-response-shape.js",
  },
  {
    label: "appbuilder/runtime/respond-to-429-by-backing-off-runtime-throttles-aggressively",
    checker: misc.backoff429Checker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-429-backoff.js",
    good: "good-429-backoff.js",
  },
  {
    label: "appbuilder/sdk/use-files-sdk-for-blobs-100kb-not-state-sdk",
    checker: misc.stateLargeBlobChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-state-large-blob.js",
    good: "good-state-large-blob.js",
  },
  {
    label: "appbuilder/sdk/state-sdk-is-ttl-bounded-never-the-source-of-truth",
    checker: misc.stateOnlyPersistenceChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-state-only-persistence.js",
    good: "good-state-only-persistence.js",
  },
  {
    label: "appbuilder/sdk/no-built-in-relational-database-use-files-sdk-or-external-db",
    checker: misc.stateSimulatedJoinChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-state-simulated-join.js",
    good: "good-state-simulated-join.js",
  },
  {
    label: "appbuilder/validation/validate-inputs-with-checkmissingrequestinputs-before-sdk-calls",
    checker: misc.checkMissingInputsFirstChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-check-missing-inputs.js",
    good: "good-check-missing-inputs.js",
  },
  {
    label: "appbuilder/validation/treat-params-as-untrusted-never-pass-to-sql-eval-or-shell",
    checker: misc.paramsToSqlEvalShellChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-params-sql-eval.js",
    good: "good-params-sql-eval.js",
  },
  {
    label: "appbuilder/validation/never-construct-action-or-sequence-names-from-user-input",
    checker: misc.noDynamicActionInvokeNameChecker,
    filePath: "/proj/actions/orders/index.js",
    bad: "bad-dynamic-invoke-name.js",
    good: "good-dynamic-invoke-name.js",
  },
  {
    label: "appbuilder/tooling/parameterize-app-config-yaml-inputs-via-env-vars-mirror-them-in-",
    checker: misc.literalConfigInputsChecker,
    filePath: "/proj/app.config.yaml",
    bad: "bad-literal-config-inputs.yaml",
    good: "good-literal-config-inputs.yaml",
  },
  {
    label: "appbuilder/runtime/action-sequences-must-respect-the-60s-edge-cap-design-steps-to-c",
    checker: misc.sequenceBudgetChecker,
    filePath: "/proj/app.config.yaml",
    bad: "bad-sequence-budget.yaml",
    good: "good-sequence-budget.yaml",
  },
];

describe("appbuilder core batch — file-scoped checkers", () => {
  for (const c of FILE_CASES) {
    it(`${c.label} flags bad fixture`, async () => {
      const content = await fixtureContent(c.bad);
      const hits = await c.checker.check(fileCtx(c.filePath, content));
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.label} passes good fixture`, async () => {
      const content = await fixtureContent(c.good);
      const hits = await c.checker.check(fileCtx(c.goodFilePath ?? c.filePath, content));
      assert.equal(hits.length, 0, `expected no findings for ${c.good}, got ${JSON.stringify(hits)}`);
    });
  }
});

interface RepoCase {
  label: string;
  checker: RepoChecker;
  setup: (root: string, kind: "bad" | "good") => Promise<RepoContext>;
}

const REPO_CASES: RepoCase[] = [
  {
    label: "appbuilder/deploy/know-your-license-pack-quotas-design-within-them",
    checker: deploy.licenseQuotaDocChecker,
    setup: async (root, kind) => {
      if (kind === "good") {
        await mkdir(join(root, "docs"), { recursive: true });
        await writeFile(join(root, "docs", "license-quotas.md"), "# License quotas\n");
      }
      return { projectRoot: root, allFiles: [] };
    },
  },
  {
    label: "appbuilder/deploy/api-gateway-provisioning-takes-5-10-minutes-plan-deploys-around-",
    checker: deploy.gatewayProvisioningWindowChecker,
    setup: async (root, kind) => {
      execFileSync("git", ["init", "-q"], { cwd: root });
      execFileSync("git", ["config", "user.email", "a@b.com"], { cwd: root });
      execFileSync("git", ["config", "user.name", "test"], { cwd: root });
      const cfgPath = join(root, "app.config.yaml");
      await writeFile(cfgPath, "application:\n  apis:\n    - commerce\n");
      execFileSync("git", ["add", "."], { cwd: root });
      if (kind === "bad") {
        execFileSync("git", ["commit", "-q", "-m", "add config"], { cwd: root });
      } else {
        // Backdate the commit well outside the 15-minute provisioning window.
        const oldDate = "Mon Jan 1 00:00:00 2020 +0000";
        execFileSync("git", ["commit", "-q", "-m", "add config", "--date", oldDate], {
          cwd: root,
          env: { ...process.env, GIT_AUTHOR_DATE: oldDate, GIT_COMMITTER_DATE: oldDate },
        });
      }
      return { projectRoot: root, allFiles: [cfgPath] };
    },
  },
  {
    label: "appbuilder/integration/aem-cloud-service-6-5-and-commerce-have-native-extension-points-",
    checker: integration.extensionPointEnumChecker,
    setup: async (root, kind) => {
      const cfgPath = join(root, "app.config.yaml");
      if (kind === "bad") {
        await writeFile(cfgPath, "extensions:\n  dx/excshell/1:\n    $include: foo\n  totally/invented/1:\n    $include: bar\n");
      } else {
        await writeFile(cfgPath, "extensions:\n  dx/excshell/1:\n    $include: foo\n");
      }
      return { projectRoot: root, allFiles: [cfgPath] };
    },
  },
  {
    label: "appbuilder/performance/right-size-action-memory-and-concurrency-don-t-ship-everything-a",
    checker: misc.uniformMemoryConcurrencyChecker,
    setup: async (root, kind) => {
      const cfgPath = join(root, "actions.config.yaml");
      if (kind === "bad") {
        await writeFile(
          cfgPath,
          "actions:\n  a1:\n    limits:\n      memory: 512\n      concurrency: 10\n  a2:\n    limits:\n      memory: 512\n      concurrency: 10\n  a3:\n    limits:\n      memory: 512\n      concurrency: 10\n",
        );
      } else {
        await writeFile(
          cfgPath,
          "actions:\n  a1:\n    limits:\n      memory: 512\n      concurrency: 10\n  a2:\n    limits:\n      memory: 256\n      concurrency: 5\n",
        );
      }
      return { projectRoot: root, allFiles: [cfgPath] };
    },
  },
  {
    label: "appbuilder/sdk/define-default-params-at-package-level-when-shared-across-action",
    checker: misc.duplicatePackageInputsChecker,
    setup: async (root, kind) => {
      const cfgPath = join(root, "actions.config.yaml");
      if (kind === "bad") {
        await writeFile(cfgPath, "actions:\n  a1:\n    inputs:\n      LOG_LEVEL: debug\n  a2:\n    inputs:\n      LOG_LEVEL: debug\n");
      } else {
        await writeFile(
          cfgPath,
          "inputs:\n  LOG_LEVEL: debug\nactions:\n  a1:\n    inputs:\n      LOG_LEVEL: debug\n  a2:\n    inputs:\n      LOG_LEVEL: debug\n",
        );
      }
      return { projectRoot: root, allFiles: [cfgPath] };
    },
  },
  {
    label: "appbuilder/secrets/never-edit-auto-generated-aio-secrets-in-env",
    checker: misc.envPartialEditHistoryChecker,
    setup: async (root, kind) => {
      execFileSync("git", ["init", "-q"], { cwd: root });
      execFileSync("git", ["config", "user.email", "a@b.com"], { cwd: root });
      execFileSync("git", ["config", "user.name", "test"], { cwd: root });
      const envPath = join(root, ".env");
      await writeFile(envPath, "AIO_RUNTIME_NAMESPACE=foo\nAIO_RUNTIME_AUTH=bar\nAIO_ims_contexts=baz\n");
      execFileSync("git", ["add", "."], { cwd: root });
      execFileSync("git", ["commit", "-q", "-m", "init"], { cwd: root });
      if (kind === "bad") {
        let content = await readFile(envPath, "utf8");
        content = content.replace("AIO_RUNTIME_AUTH=bar", "AIO_RUNTIME_AUTH=barbar");
        await writeFile(envPath, content);
        execFileSync("git", ["add", "."], { cwd: root });
        execFileSync("git", ["commit", "-q", "-m", "hand edit one var"], { cwd: root });
      }
      return { projectRoot: root, allFiles: [] };
    },
  },
  {
    label: "appbuilder/tooling/typescript-actions-require-webpack-with-ts-loader",
    checker: misc.tsActionNeedsWebpackChecker,
    setup: async (root, kind) => {
      const cfgPath = join(root, "app.config.yaml");
      await writeFile(cfgPath, "actions:\n  a1:\n    function: index.ts\n");
      const files = [cfgPath];
      if (kind === "good") {
        const wpPath = join(root, "webpack.config.js");
        await writeFile(wpPath, "module.exports = { module: { rules: [{ test: /\\.ts$/, use: 'ts-loader' }] } };\n");
        files.push(wpPath);
      }
      return { projectRoot: root, allFiles: files };
    },
  },
  {
    label: "appbuilder/tooling/aio-app-dev-does-not-run-scheduled-events-or-webhook-triggers",
    checker: misc.eventsLocalDevDocChecker,
    setup: async (root, kind) => {
      const handlerPath = join(root, "actions", "events-handler", "index.js");
      await mkdir(dirname(handlerPath), { recursive: true });
      await writeFile(handlerPath, "exports.main = async () => ({});\n");
      const files = [handlerPath];
      if (kind === "good") {
        const readmePath = join(root, "README.md");
        await writeFile(readmePath, "Test locally with `aio runtime action invoke events-handler`.\n");
        files.push(readmePath);
      }
      return { projectRoot: root, allFiles: files };
    },
  },
  {
    label: "appbuilder/tooling/use-aio-runtime-action-invoke-for-cloud-smoke-tests",
    checker: misc.smokeTestCiStepChecker,
    setup: async (root, kind) => {
      const wfPath = join(root, ".github", "workflows", "deploy.yml");
      await mkdir(dirname(wfPath), { recursive: true });
      if (kind === "bad") {
        await writeFile(wfPath, "name: deploy\non: push\njobs:\n  deploy:\n    steps:\n      - run: aio app deploy\n");
      } else {
        await writeFile(
          wfPath,
          "name: deploy\non: push\njobs:\n  deploy:\n    steps:\n      - run: aio app deploy\n      - run: aio runtime action invoke myaction\n",
        );
      }
      return { projectRoot: root, allFiles: [wfPath] };
    },
  },
  {
    label: "appbuilder/tooling/track-the-aio-cli-version-watch-release-notes-for-deprecations",
    checker: misc.aioCliVersionPinnedChecker,
    setup: async (root, kind) => {
      const pkgPath = join(root, "package.json");
      if (kind === "bad") {
        await writeFile(pkgPath, JSON.stringify({ devDependencies: { "@adobe/aio-cli": "^10.0.0" } }));
      } else {
        await writeFile(pkgPath, JSON.stringify({ devDependencies: { "@adobe/aio-cli": "10.0.0" } }));
      }
      return { projectRoot: root, allFiles: [] };
    },
  },
  {
    label: "appbuilder/tooling/remove-all-template-sample-actions-and-placeholder-content-befor",
    checker: misc.scaffoldMarkersChecker,
    setup: async (root, kind) => {
      const cfgPath = join(root, "app.config.yaml");
      if (kind === "bad") {
        await writeFile(cfgPath, "application:\n  id: my-app\nactions:\n  sample:\n    function: index.js\n");
      } else {
        await writeFile(cfgPath, "application:\n  id: real-app-123\nactions:\n  process-orders:\n    function: index.js\n");
      }
      return { projectRoot: root, allFiles: [cfgPath] };
    },
  },
];

describe("appbuilder core batch — repo-scoped checkers", () => {
  for (const c of REPO_CASES) {
    it(`${c.label} flags bad repo layout`, async () => {
      const root = await mkdtemp(join(tmpdir(), "abqc-batch-bad-"));
      const ctx = await c.setup(root, "bad");
      const hits = await c.checker.check(ctx);
      assert.ok(hits.length > 0, `expected findings for bad ${c.label}`);
    });
    it(`${c.label} passes good repo layout`, async () => {
      const root = await mkdtemp(join(tmpdir(), "abqc-batch-good-"));
      const ctx = await c.setup(root, "good");
      const hits = await c.checker.check(ctx);
      assert.equal(hits.length, 0, `expected no findings for good ${c.label}, got ${JSON.stringify(hits)}`);
    });
  }
});
