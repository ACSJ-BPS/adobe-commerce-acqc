import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdir, mkdtemp } from "node:fs/promises";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import type { CheckContext, RepoContext, FileChecker } from "../types.js";

import {
  noStageBeforeProdChecker,
  envBranchingLogicChecker,
  missingPredeployGateChecker,
  deployHookMutatesStateChecker,
  missingNoBuildFlagChecker,
  ungatedSharedSecretsChecker,
  undeployProdActionChecker,
  untaggedProdDeployChecker,
  missingPostDeployMonitoringChecker,
  missingRollbackScriptChecker,
  missingPrecommitSecretsScanChecker,
  staticCiTokenChecker,
  latencyRiskActionChecker,
} from "../checkers/builtins/deployment-checks.js";

import {
  extraneousDependencyChecker,
  circularImportChecker,
  anonymousDefaultExportChecker,
  namespaceImportChecker,
  noBlankLineAfterImportsChecker,
  importGroupOrderChecker,
  deepRelativeImportChecker,
} from "../checkers/builtins/imports-checks.js";

import {
  dataResidencyDocChecker,
  dpiaDocChecker,
  kpiDocChecker,
  missingRumChecker,
  rbacDocChecker,
  genaiPolicyDocChecker,
  vendorListDocChecker,
  runbookDocChecker,
  reviewLogDocChecker,
  a11ySignoffDocChecker,
  trainingDocsDirChecker,
  loadTestDocChecker,
  dataAccessDocChecker,
  changelogFormatChecker,
  actionNoSchemaChecker,
  apiDocMissingSampleChecker,
} from "../checkers/builtins/process-doc-checks.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function makeCtx(filePath: string, content: string): CheckContext {
  return {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
  };
}

async function makeRepo(): Promise<string> {
  return mkdtemp(join(tmpdir(), "abqc-batch-"));
}

async function writeWorkflow(root: string, name: string, content: string): Promise<string> {
  const dir = join(root, ".github", "workflows");
  await mkdir(dir, { recursive: true });
  const p = join(dir, name);
  await writeFile(p, content, "utf8");
  return p;
}

function repoCtx(root: string, allFiles: string[] = []): RepoContext {
  return { projectRoot: root, allFiles, actionEntryFiles: new Set() };
}

// ---------------------------------------------------------------------------
// File-scoped fixtures
// ---------------------------------------------------------------------------

interface FileFixtureCase {
  name: string;
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const FILE_CASES: FileFixtureCase[] = [
  {
    name: "env-branching-logic",
    checker: envBranchingLogicChecker,
    bad: "bad-env-branching.js",
    good: "good-env-branching.js",
    filePath: "/proj/actions/demo/index.js",
  },
  {
    name: "deploy-hook-mutates-state",
    checker: deployHookMutatesStateChecker,
    bad: "bad-post-app-deploy.js",
    good: "good-post-app-deploy.js",
    filePath: "/proj/hooks/post-app-deploy.js",
  },
  {
    name: "latency-risk-action",
    checker: latencyRiskActionChecker,
    bad: "bad-latency-risk-action.js",
    good: "good-latency-risk-action.js",
    filePath: "/proj/actions/demo/index.js",
  },
  {
    name: "anonymous-default-export",
    checker: anonymousDefaultExportChecker,
    bad: "bad-anonymous-default-export.js",
    good: "good-anonymous-default-export.js",
    filePath: "/proj/src/formatters.js",
  },
  {
    name: "namespace-import",
    checker: namespaceImportChecker,
    bad: "bad-namespace-import.js",
    good: "good-namespace-import.js",
    filePath: "/proj/src/index.js",
  },
  {
    name: "no-blank-line-after-imports",
    checker: noBlankLineAfterImportsChecker,
    bad: "bad-no-blank-line-after-imports.js",
    good: "good-no-blank-line-after-imports.js",
    filePath: "/proj/src/index.js",
  },
  {
    name: "import-group-order",
    checker: importGroupOrderChecker,
    bad: "bad-import-group-order.js",
    good: "good-import-group-order.js",
    filePath: "/proj/src/index.js",
  },
  {
    name: "deep-relative-import",
    checker: deepRelativeImportChecker,
    bad: "bad-deep-relative-import.js",
    good: "good-deep-relative-import.js",
    filePath: "/proj/src/index.js",
  },
  {
    name: "action-no-schema",
    checker: actionNoSchemaChecker,
    bad: "bad-action-no-schema.js",
    good: "good-action-no-schema.js",
    filePath: "/proj/actions/demo/index.js",
  },
];

describe("deployment/imports/process batch — file-scoped fixtures", () => {
  for (const c of FILE_CASES) {
    it(`${c.name} flags bad fixture`, async () => {
      const content = await readFile(join(fixtures, c.bad), "utf8");
      const hits = await c.checker.check(makeCtx(c.filePath, content));
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.name} passes good fixture`, async () => {
      const content = await readFile(join(fixtures, c.good), "utf8");
      const hits = await c.checker.check(makeCtx(c.filePath, content));
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

// ---------------------------------------------------------------------------
// Deployment — repo-scoped checks
// ---------------------------------------------------------------------------

describe("deployment — repo-scoped checks", () => {
  it("no-stage-before-prod flags a workflow that deploys straight to Production", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "deploy.yml",
      "jobs:\n  deploy:\n    steps:\n      - run: aio app use --workspace Production\n      - run: aio app deploy\n",
    );
    const hits = await noStageBeforeProdChecker.check(repoCtx(root, [wf]));
    assert.ok(hits.length > 0);
  });

  it("no-stage-before-prod passes when Stage precedes Production", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "deploy.yml",
      "jobs:\n  deploy:\n    steps:\n      - run: aio app use --workspace Stage\n      - run: aio app deploy\n      - run: aio app use --workspace Production\n      - run: aio app deploy\n",
    );
    const hits = await noStageBeforeProdChecker.check(repoCtx(root, [wf]));
    assert.equal(hits.length, 0);
  });

  it("missing-predeploy-gate flags a deploy job with no needs", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(root, "deploy.yml", "jobs:\n  deploy:\n    steps:\n      - run: aio app deploy\n");
    const hits = await missingPredeployGateChecker.check(repoCtx(root, [wf]));
    assert.ok(hits.length > 0);
  });

  it("missing-predeploy-gate passes when deploy needs a test/gate job", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "deploy.yml",
      "jobs:\n  gate:\n    steps:\n      - run: aio app test\n  deploy:\n    needs: gate\n    steps:\n      - run: aio app deploy\n",
    );
    const hits = await missingPredeployGateChecker.check(repoCtx(root, [wf]));
    assert.equal(hits.length, 0);
  });

  it("missing-no-build-flag flags build+deploy without --no-build", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "ci.yml",
      "jobs:\n  ci:\n    steps:\n      - run: aio app build\n      - run: aio app deploy\n",
    );
    const hits = await missingNoBuildFlagChecker.check(repoCtx(root, [wf]));
    assert.ok(hits.length > 0);
  });

  it("missing-no-build-flag passes when deploy uses --no-build", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "ci.yml",
      "jobs:\n  ci:\n    steps:\n      - run: aio app build\n      - run: aio app deploy --no-build\n",
    );
    const hits = await missingNoBuildFlagChecker.check(repoCtx(root, [wf]));
    assert.equal(hits.length, 0);
  });

  it("ungated-shared-secrets flags the sole deploy job having no if: condition", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(root, "deploy.yml", "jobs:\n  deploy:\n    steps:\n      - run: aio app deploy\n");
    const hits = await ungatedSharedSecretsChecker.check(repoCtx(root, [wf]));
    assert.ok(hits.length > 0);
  });

  it("ungated-shared-secrets passes when the deploy job is gated by ref", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "deploy.yml",
      "jobs:\n  deploy:\n    if: startsWith(github.ref, 'refs/tags/v')\n    steps:\n      - run: aio app deploy\n",
    );
    const hits = await ungatedSharedSecretsChecker.check(repoCtx(root, [wf]));
    assert.equal(hits.length, 0);
  });

  it("undeploy-prod-action flags 'aio app undeploy' referencing Production", async () => {
    const root = await makeRepo();
    const p = join(root, "scripts", "cleanup.sh");
    await mkdir(dirname(p), { recursive: true });
    await writeFile(p, "aio app undeploy --action process-order # Production cleanup\n", "utf8");
    const hits = await undeployProdActionChecker.check(repoCtx(root, [p]));
    assert.ok(hits.length > 0);
  });

  it("undeploy-prod-action passes when undeploy doesn't reference prod", async () => {
    const root = await makeRepo();
    const p = join(root, "scripts", "cleanup.sh");
    await mkdir(dirname(p), { recursive: true });
    await writeFile(p, "aio app undeploy --action stale-dev-action\n", "utf8");
    const hits = await undeployProdActionChecker.check(repoCtx(root, [p]));
    assert.equal(hits.length, 0);
  });

  it("untagged-prod-deploy flags a repo with no prod- git tags", async () => {
    const root = await makeRepo();
    execFileSync("git", ["init"], { cwd: root });
    const hits = await untaggedProdDeployChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("untagged-prod-deploy passes when a prod- tag exists", async () => {
    const root = await makeRepo();
    execFileSync("git", ["init"], { cwd: root });
    execFileSync("git", ["config", "user.email", "test@example.com"], { cwd: root });
    execFileSync("git", ["config", "user.name", "Test"], { cwd: root });
    await writeFile(join(root, "README.md"), "x", "utf8");
    execFileSync("git", ["add", "."], { cwd: root });
    execFileSync("git", ["commit", "-m", "init"], { cwd: root });
    execFileSync("git", ["tag", "prod-2026-01-01-0000"], { cwd: root });
    const hits = await untaggedProdDeployChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("missing-post-deploy-monitoring flags a deploy job with nothing monitoring it afterward", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(root, "deploy.yml", "jobs:\n  deploy:\n    steps:\n      - run: aio app deploy\n");
    const hits = await missingPostDeployMonitoringChecker.check(repoCtx(root, [wf]));
    assert.ok(hits.length > 0);
  });

  it("missing-post-deploy-monitoring passes when a later job checks activation logs", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "deploy.yml",
      "jobs:\n  deploy:\n    steps:\n      - run: aio app deploy\n  monitor:\n    needs: deploy\n    steps:\n      - run: ./scripts/check-activation-logs.sh\n",
    );
    const hits = await missingPostDeployMonitoringChecker.check(repoCtx(root, [wf]));
    assert.equal(hits.length, 0);
  });

  it("missing-rollback-script flags a repo with no rollback script or workflow mention", async () => {
    const root = await makeRepo();
    const hits = await missingRollbackScriptChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("missing-rollback-script passes when scripts/rollback.sh exists", async () => {
    const root = await makeRepo();
    const p = join(root, "scripts", "rollback.sh");
    await mkdir(dirname(p), { recursive: true });
    await writeFile(p, "#!/bin/bash\necho rollback\n", "utf8");
    const hits = await missingRollbackScriptChecker.check(repoCtx(root, [p]));
    assert.equal(hits.length, 0);
  });

  it("missing-precommit-secrets-scan flags a repo with no pre-commit config", async () => {
    const root = await makeRepo();
    const hits = await missingPrecommitSecretsScanChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("missing-precommit-secrets-scan passes when gitleaks is configured", async () => {
    const root = await makeRepo();
    await writeFile(
      join(root, ".pre-commit-config.yaml"),
      "repos:\n  - repo: https://github.com/gitleaks/gitleaks\n    hooks:\n      - id: gitleaks\n",
      "utf8",
    );
    const hits = await missingPrecommitSecretsScanChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("static-ci-token flags a static AIO_RUNTIME_AUTH secret", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "deploy.yml",
      "jobs:\n  deploy:\n    steps:\n      - run: aio app deploy\n        env:\n          AIO_RUNTIME_AUTH: ${{ secrets.AIO_AUTH }}\n",
    );
    const hits = await staticCiTokenChecker.check(repoCtx(root, [wf]));
    assert.ok(hits.length > 0);
  });

  it("static-ci-token passes when oauth_sts via aio-apps-action is used", async () => {
    const root = await makeRepo();
    const wf = await writeWorkflow(
      root,
      "deploy.yml",
      "jobs:\n  deploy:\n    steps:\n      - uses: adobe/aio-apps-action@v3\n        with:\n          command: 'oauth_sts'\n      - run: aio app deploy\n        env:\n          AIO_RUNTIME_AUTH: ${{ secrets.AIO_AUTH }}\n",
    );
    const hits = await staticCiTokenChecker.check(repoCtx(root, [wf]));
    assert.equal(hits.length, 0);
  });
});

// ---------------------------------------------------------------------------
// Imports — repo-scoped checks
// ---------------------------------------------------------------------------

describe("imports — repo-scoped checks", () => {
  it("extraneous-dependency flags an undeclared import", async () => {
    const root = await makeRepo();
    await writeFile(
      join(root, "package.json"),
      JSON.stringify({ dependencies: { "@adobe/aio-sdk": "^4.0.0" } }),
      "utf8",
    );
    const srcPath = join(root, "actions", "demo", "index.js");
    await mkdir(dirname(srcPath), { recursive: true });
    await writeFile(srcPath, "const _ = require('lodash')\nexports.main = async () => ({})\n", "utf8");
    const hits = await extraneousDependencyChecker.check(repoCtx(root, [srcPath]));
    assert.ok(hits.length > 0);
  });

  it("extraneous-dependency passes when the dependency is declared", async () => {
    const root = await makeRepo();
    await writeFile(join(root, "package.json"), JSON.stringify({ dependencies: { lodash: "^4.17.21" } }), "utf8");
    const srcPath = join(root, "actions", "demo", "index.js");
    await mkdir(dirname(srcPath), { recursive: true });
    await writeFile(srcPath, "const _ = require('lodash')\nexports.main = async () => ({})\n", "utf8");
    const hits = await extraneousDependencyChecker.check(repoCtx(root, [srcPath]));
    assert.equal(hits.length, 0);
  });

  it("circular-import flags a two-file cycle", async () => {
    const root = await makeRepo();
    const a = join(root, "orders.js");
    const b = join(root, "pricing.js");
    await writeFile(a, "const { formatPrice } = require('./pricing')\nexports.processOrder = (o) => formatPrice(o.total)\n", "utf8");
    await writeFile(b, "const { processOrder } = require('./orders')\nexports.formatPrice = (n) => `$${n}`\n", "utf8");
    const hits = await circularImportChecker.check(repoCtx(root, [a, b]));
    assert.ok(hits.length > 0);
  });

  it("circular-import passes when there's no cycle", async () => {
    const root = await makeRepo();
    const a = join(root, "orders.js");
    const b = join(root, "formatters.js");
    await writeFile(a, "const { formatPrice } = require('./formatters')\nexports.processOrder = (o) => formatPrice(o.total)\n", "utf8");
    await writeFile(b, "exports.formatPrice = (n) => `$${n}`\n", "utf8");
    const hits = await circularImportChecker.check(repoCtx(root, [a, b]));
    assert.equal(hits.length, 0);
  });
});

// ---------------------------------------------------------------------------
// Process / documentation — P1 doc-artifact repo-scoped checks
// ---------------------------------------------------------------------------

describe("process/documentation — doc-artifact checks", () => {
  it("data-residency-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await dataResidencyDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("data-residency-doc passes with a real doc", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "compliance");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "data-residency.md"),
      "# Data Residency\n\nEU customer data stored in eu-central-1 region with KMS encryption and a 7-year retention policy, signed off by Legal.\n",
      "utf8",
    );
    const hits = await dataResidencyDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("dpia-doc flags a missing DPIA", async () => {
    const root = await makeRepo();
    const hits = await dpiaDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("dpia-doc passes when a dpia-*.md file has status/DPO sign-off content", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "compliance");
    await mkdir(dir, { recursive: true });
    const p = join(dir, "dpia-2026-04.md");
    await writeFile(
      p,
      "# DPIA\n\nStatus: filed. DPO sign-off: A. Muller, 2026-04-10. Legal sign-off recorded for this processing activity.\n",
      "utf8",
    );
    const hits = await dpiaDocChecker.check(repoCtx(root, [p]));
    assert.equal(hits.length, 0);
  });

  it("kpi-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await kpiDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("kpi-doc passes with a real KPI table", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "discovery");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "kpis.md"),
      "# Project KPIs\n\n| KPI | Baseline | Target |\n| --- | --- | --- |\n| DAU | 12000 | 18000 |\n",
      "utf8",
    );
    const hits = await kpiDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("missing-rum flags an SPA repo with no web-vitals import anywhere", async () => {
    const root = await makeRepo();
    const p = join(root, "web-src", "src", "App.jsx");
    await mkdir(dirname(p), { recursive: true });
    await writeFile(p, "export default function App() { return null }\n", "utf8");
    const hits = await missingRumChecker.check(repoCtx(root, [p]));
    assert.ok(hits.length > 0);
  });

  it("missing-rum passes when a SPA file imports web-vitals", async () => {
    const root = await makeRepo();
    const p = join(root, "web-src", "src", "index.js");
    await mkdir(dirname(p), { recursive: true });
    await writeFile(p, "import { onLCP } from 'web-vitals'\nonLCP(() => {})\n", "utf8");
    const hits = await missingRumChecker.check(repoCtx(root, [p]));
    assert.equal(hits.length, 0);
  });

  it("missing-rum returns no hits for an action-only repo (no SPA files)", async () => {
    const root = await makeRepo();
    const p = join(root, "actions", "demo", "index.js");
    await mkdir(dirname(p), { recursive: true });
    await writeFile(p, "exports.main = async () => ({})\n", "utf8");
    const hits = await missingRumChecker.check(repoCtx(root, [p]));
    assert.equal(hits.length, 0);
  });

  it("rbac-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await rbacDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("rbac-doc passes with roles/scopes and a security sign-off", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "governance");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "rbac.md"),
      "# RBAC Matrix\n\n| Role | Scope | Access |\n| --- | --- | --- |\n| Admin | admin_console_access | all |\n\nSecurity sign-off: M. Chen, 2026-04-25.\n",
      "utf8",
    );
    const hits = await rbacDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("genai-policy-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await genaiPolicyDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("genai-policy-doc passes with real policy content", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "governance");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "genai-policy.md"),
      "# GenAI Usage Policy\n\nApproved tools: GitHub Copilot Enterprise, Claude in claude.ai with Adobe SSO.\nProhibited: public ChatGPT free tier. No customer PII may be pasted into any external tool.\n",
      "utf8",
    );
    const hits = await genaiPolicyDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("vendor-list-doc flags a missing vendor list", async () => {
    const root = await makeRepo();
    const hits = await vendorListDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("vendor-list-doc passes with a recently-reviewed table", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "governance");
    await mkdir(dir, { recursive: true });
    const content = [
      "# Third-Party Dependencies",
      "",
      "| Vendor | last_reviewed |",
      "| --- | --- |",
      "| Stripe | 2026-08-01 |",
    ].join("\n");
    await writeFile(join(dir, "vendors.md"), content, "utf8");
    const hits = await vendorListDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("runbook-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await runbookDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("runbook-doc passes with an on-call rotation reference", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "operations");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "runbook.md"),
      "# Incident Response Runbook\n\nOn-call rotation (PagerDuty schedule 'appbuilder-prod'): primary rotates weekly.\n",
      "utf8",
    );
    const hits = await runbookDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("review-log-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await reviewLogDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("review-log-doc passes with non-trivial content", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "operations");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "review-log.md"),
      "# Post-GA Monitoring Cadence\n\nWeek 1: daily standup reviewing p95 latency, error rate, and 429 rate. Month 1: weekly review with eng lead and on-call.\n",
      "utf8",
    );
    const hits = await reviewLogDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("a11y-signoff-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await a11ySignoffDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("a11y-signoff-doc passes with NVDA/JAWS/VoiceOver sign-off", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "quality");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "a11y-signoff.md"),
      "# Screen Reader QA\n\nCoverage: NVDA + Chrome, JAWS + Edge, VoiceOver + Safari — all flows pass.\nSign-off: A. Rodriguez (a11y lead), 2026-04-25.\n",
      "utf8",
    );
    const hits = await a11ySignoffDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("training-docs-dir flags a missing docs/training directory", async () => {
    const root = await makeRepo();
    const hits = await trainingDocsDirChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("training-docs-dir passes when docs/training has at least one file", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "training");
    await mkdir(dir, { recursive: true });
    await writeFile(join(dir, "README.md"), "# Training\n", "utf8");
    const hits = await trainingDocsDirChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("load-test-doc flags a missing doc", async () => {
    const root = await makeRepo();
    const hits = await loadTestDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("load-test-doc passes with RPS/latency/p95/sign-off content", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "quality");
    await mkdir(dir, { recursive: true });
    await writeFile(
      join(dir, "load-test-report.md"),
      "# Pre-GA Load Test\n\nTested at 3,600 req/s (rps). p95 latency: 412ms (SLO <500ms).\nSign-off: Eng Director, 2026-04-29.\n",
      "utf8",
    );
    const hits = await loadTestDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("data-access-doc flags a missing docs/data-access.md", async () => {
    const root = await makeRepo();
    const hits = await dataAccessDocChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("data-access-doc passes with a real data-access table", async () => {
    const root = await makeRepo();
    await mkdir(join(root, "docs"), { recursive: true });
    const content = [
      "# Data Access",
      "",
      "| Source | Data read | Retention |",
      "| --- | --- | --- |",
      "| IMS profile | email, org id | n/a |",
    ].join("\n");
    await writeFile(join(root, "docs", "data-access.md"), content, "utf8");
    const hits = await dataAccessDocChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("changelog-format flags CHANGELOG.md with no version heading", async () => {
    const root = await makeRepo();
    await writeFile(join(root, "CHANGELOG.md"), "# Changelog\n\nStuff changed at some point.\n", "utf8");
    const hits = await changelogFormatChecker.check(repoCtx(root, []));
    assert.ok(hits.length > 0);
  });

  it("changelog-format passes with a Keep-a-Changelog version heading", async () => {
    const root = await makeRepo();
    await writeFile(
      join(root, "CHANGELOG.md"),
      "# Changelog\n\n## [1.5.0] - 2026-04-29\n\n### Added\n- Stuff\n",
      "utf8",
    );
    const hits = await changelogFormatChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("changelog-format returns no hits when CHANGELOG.md doesn't exist", async () => {
    const root = await makeRepo();
    const hits = await changelogFormatChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });

  it("api-doc-missing-sample flags an API doc with no code sample", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "api");
    await mkdir(dir, { recursive: true });
    const p = join(dir, "orders.md");
    await writeFile(p, "# POST /v1/orders/process\n\nProcesses an order.\n", "utf8");
    const hits = await apiDocMissingSampleChecker.check(repoCtx(root, [p]));
    assert.ok(hits.length > 0);
  });

  it("api-doc-missing-sample passes when a json sample is present", async () => {
    const root = await makeRepo();
    const dir = join(root, "docs", "api");
    await mkdir(dir, { recursive: true });
    const p = join(dir, "orders.md");
    await writeFile(p, '# POST /v1/orders/process\n\n```json\n{ "orderId": "abc" }\n```\n', "utf8");
    const hits = await apiDocMissingSampleChecker.check(repoCtx(root, [p]));
    assert.equal(hits.length, 0);
  });

  it("api-doc-missing-sample returns no hits when there's no docs/api directory", async () => {
    const root = await makeRepo();
    const hits = await apiDocMissingSampleChecker.check(repoCtx(root, []));
    assert.equal(hits.length, 0);
  });
});
