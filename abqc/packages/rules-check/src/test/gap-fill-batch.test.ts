import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdir, mkdtemp } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { randomBytes } from "node:crypto";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, RepoContext, FileChecker } from "../types.js";
import {
  meshHookTokenCacheChecker,
  meshCacheControlHeaderChecker,
  meshTtlBlanketChecker,
  cachingTopologyDocChecker,
  meshSourceRenameHistoryChecker,
  meshStructuredLogChecker,
  workspaceEnvMappingChecker,
  meshLocalDevDocChecker,
  meshNoRatelimitGuardChecker,
  meshHookSlowCallChecker,
  meshErrorLeakChecker,
  meshRawNamingChecker,
  meshTypeMergingUnboundedChecker,
} from "../checkers/builtins/gap-fill-mesh.js";
import {
  stateWriteConcurrencyChecker,
  chunkCheckpointsChecker,
  swScopeChecker,
  goldenPathRiskFactorsChecker,
  cspUncoveredResourceChecker,
  lighthouseAntiPatternChecklistChecker,
  lcpRiskFactorsChecker,
  clsRiskFactorsChecker,
  bundleSizeChecker,
} from "../checkers/builtins/gap-fill-runtime.js";
import {
  adminUiPublishWorkflowChecker,
  uixVersionPinChecker,
  extensionSandboxAttrChecker,
  dataResidencyUnpinnedChecker,
  releaseApprovalDocChecker,
  booleanPropNamingChecker,
  npmAuditChecker,
  interpretNpmAuditOutput,
} from "../checkers/builtins/gap-fill-misc.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

interface FileCase {
  name: string;
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const FILE_CASES: FileCase[] = [
  { name: "mesh-hook-token-cache", checker: meshHookTokenCacheChecker, bad: "bad-mesh-hook-token-cache.js", good: "good-mesh-hook-token-cache.js", filePath: "/proj/mesh/hooks/auth.js" },
  { name: "mesh-cache-control-header", checker: meshCacheControlHeaderChecker, bad: "bad-mesh-cache-control-header.json", good: "good-mesh-cache-control-header.json", filePath: "/proj/mesh/meshConfig.json" },
  { name: "mesh-ttl-blanket", checker: meshTtlBlanketChecker, bad: "bad-mesh-ttl-blanket.json", good: "good-mesh-ttl-blanket.json", filePath: "/proj/mesh/meshConfig.json" },
  { name: "mesh-structured-log", checker: meshStructuredLogChecker, bad: "bad-mesh-structured-log.js", good: "good-mesh-structured-log.js", filePath: "/proj/resolvers/orders.js" },
  { name: "mesh-no-ratelimit-guard", checker: meshNoRatelimitGuardChecker, bad: "bad-mesh-no-ratelimit-guard.js", good: "good-mesh-no-ratelimit-guard.js", filePath: "/proj/resolvers/products.js" },
  { name: "mesh-hook-slow-call", checker: meshHookSlowCallChecker, bad: "bad-mesh-hook-slow-call.js", good: "good-mesh-hook-slow-call.js", filePath: "/proj/hooks/beforeAll.js" },
  { name: "mesh-error-leak", checker: meshErrorLeakChecker, bad: "bad-mesh-error-leak.js", good: "good-mesh-error-leak.js", filePath: "/proj/resolvers/orders-error.js" },
  { name: "mesh-raw-naming", checker: meshRawNamingChecker, bad: "bad-mesh-raw-naming.json", good: "good-mesh-raw-naming.json", filePath: "/proj/mesh/meshConfig.json" },
  { name: "mesh-typemerging-unbounded", checker: meshTypeMergingUnboundedChecker, bad: "bad-mesh-typemerging-unbounded.json", good: "good-mesh-typemerging-unbounded.json", filePath: "/proj/mesh/meshConfig.json" },
  { name: "state-write-concurrency", checker: stateWriteConcurrencyChecker, bad: "bad-state-write-concurrency.js", good: "good-state-write-concurrency.js", filePath: "/proj/actions/sync/index.js" },
  { name: "chunk-checkpoints", checker: chunkCheckpointsChecker, bad: "bad-chunk-checkpoints.js", good: "good-chunk-checkpoints.js", filePath: "/proj/actions/export/index.js" },
  { name: "sw-scope", checker: swScopeChecker, bad: "bad-sw-scope.js", good: "good-sw-scope.js", filePath: "/proj/web-src/src/register-sw.js" },
  { name: "extension-sandbox-attr", checker: extensionSandboxAttrChecker, bad: "bad-extension-sandbox-attr.js", good: "good-extension-sandbox-attr.js", filePath: "/proj/web-src/src/print.js" },
  { name: "data-residency-unpinned", checker: dataResidencyUnpinnedChecker, bad: "bad-data-residency-unpinned.js", good: "good-data-residency-unpinned.js", filePath: "/proj/actions/save-profile/index.js" },
  { name: "boolean-prop-naming", checker: booleanPropNamingChecker, bad: "bad-boolean-prop-naming.jsx", good: "good-boolean-prop-naming.jsx", filePath: "/proj/web-src/src/OrderModal.jsx" },
];

async function runFile(checker: FileChecker, fixtureName: string, filePath: string) {
  const content = await readFile(join(fixtures, fixtureName), "utf8");
  const ctx: CheckContext = {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
    actionEntryFiles: filePath.includes("/actions/") ? new Set([filePath]) : undefined,
  };
  return checker.check(ctx);
}

describe("gap-fill batch — file-scoped fixtures", () => {
  for (const c of FILE_CASES) {
    it(`${c.name} flags bad fixture (${c.bad})`, async () => {
      const hits = await runFile(c.checker, c.bad, c.filePath);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.name} passes good fixture (${c.good})`, async () => {
      const hits = await runFile(c.checker, c.good, c.filePath);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

async function tempRoot(prefix: string): Promise<string> {
  return mkdtemp(join(tmpdir(), prefix));
}

function initGit(root: string): void {
  execFileSync("git", ["init"], { cwd: root, stdio: "ignore" });
  execFileSync("git", ["config", "user.email", "test@example.com"], { cwd: root, stdio: "ignore" });
  execFileSync("git", ["config", "user.name", "Test"], { cwd: root, stdio: "ignore" });
}

describe("gap-fill batch — repo-scoped: mesh", () => {
  it("caching-topology-doc flags a repo with no docs/caching-topology.md", async () => {
    const root = await tempRoot("abqc-gap-caching-topo-");
    const ctx: RepoContext = { projectRoot: root, allFiles: [] };
    const hits = await cachingTopologyDocChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("caching-topology-doc passes when docs/caching-topology.md exists", async () => {
    const root = await tempRoot("abqc-gap-caching-topo-good-");
    await mkdir(join(root, "docs"), { recursive: true });
    await writeFile(join(root, "docs", "caching-topology.md"), "# Caching topology\n\nmesh -> cdn -> upstream\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "docs", "caching-topology.md")] };
    const hits = await cachingTopologyDocChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("mesh-source-rename-history flags a renamed source found in git history", async () => {
    const root = await tempRoot("abqc-gap-mesh-rename-");
    initGit(root);
    // Pretty-printed (one key per line) so the diff below isolates the "name"
    // line the same way a real, human-edited meshConfig.json would.
    await writeFile(join(root, "meshConfig.json"), JSON.stringify({ sources: [{ name: "Commerce" }] }, null, 2), "utf8");
    execFileSync("git", ["add", "."], { cwd: root });
    execFileSync("git", ["commit", "-m", "init"], { cwd: root, stdio: "ignore" });
    await writeFile(join(root, "meshConfig.json"), JSON.stringify({ sources: [{ name: "Magento" }] }, null, 2), "utf8");
    execFileSync("git", ["add", "."], { cwd: root });
    execFileSync("git", ["commit", "-m", "rename Commerce -> Magento"], { cwd: root, stdio: "ignore" });
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "meshConfig.json")] };
    const hits = await meshSourceRenameHistoryChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("mesh-source-rename-history passes when the source name never changed", async () => {
    const root = await tempRoot("abqc-gap-mesh-rename-good-");
    initGit(root);
    await writeFile(join(root, "meshConfig.json"), JSON.stringify({ sources: [{ name: "Commerce" }] }, null, 2), "utf8");
    execFileSync("git", ["add", "."], { cwd: root });
    execFileSync("git", ["commit", "-m", "init"], { cwd: root, stdio: "ignore" });
    await writeFile(join(root, "meshConfig.json"), JSON.stringify({ sources: [{ name: "Commerce" }], extra: true }, null, 2), "utf8");
    execFileSync("git", ["add", "."], { cwd: root });
    execFileSync("git", ["commit", "-m", "add extra field"], { cwd: root, stdio: "ignore" });
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "meshConfig.json")] };
    const hits = await meshSourceRenameHistoryChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("workspace-env-mapping flags when fewer than 3 dev/stage/prod mesh configs exist", async () => {
    const root = await tempRoot("abqc-gap-mesh-env-");
    const allFiles = [join(root, "dev", "meshConfig.json"), join(root, "meshConfig.json")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await workspaceEnvMappingChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("workspace-env-mapping passes with dev/stage/prod mesh configs", async () => {
    const root = await tempRoot("abqc-gap-mesh-env-good-");
    const allFiles = [
      join(root, "dev", "meshConfig.json"),
      join(root, "stage", "meshConfig.json"),
      join(root, "prod", "meshConfig.json"),
    ];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await workspaceEnvMappingChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("mesh-local-dev-doc flags when no doc mentions aio api-mesh:run", async () => {
    const root = await tempRoot("abqc-gap-mesh-localdev-");
    await writeFile(join(root, "README.md"), "# My App\n\nSee docs.\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "README.md")] };
    const hits = await meshLocalDevDocChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("mesh-local-dev-doc passes when README documents aio api-mesh:run", async () => {
    const root = await tempRoot("abqc-gap-mesh-localdev-good-");
    await writeFile(join(root, "README.md"), "# My App\n\nRun `aio api-mesh:run meshConfig.json` for local dev.\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "README.md")] };
    const hits = await meshLocalDevDocChecker.check(ctx);
    assert.equal(hits.length, 0);
  });
});

describe("gap-fill batch — repo-scoped: runtime", () => {
  it("golden-path-risk-factors flags JSON.parse with no try/catch", async () => {
    const root = await tempRoot("abqc-gap-golden-");
    const file = join(root, "Widget.jsx");
    await writeFile(
      file,
      "export function Widget({ raw }) {\n  const data = JSON.parse(raw);\n  return <div>{data.name}</div>;\n}\n",
      "utf8",
    );
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await goldenPathRiskFactorsChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("golden-path-risk-factors passes when risk patterns are absent", async () => {
    const root = await tempRoot("abqc-gap-golden-good-");
    const file = join(root, "Widget.jsx");
    await writeFile(
      file,
      "export function Widget({ raw }) {\n  let data = {};\n  try {\n    data = JSON.parse(raw);\n  } catch (e) {\n    data = {};\n  }\n  return <div>{data.name}</div>;\n}\n",
      "utf8",
    );
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await goldenPathRiskFactorsChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("csp-uncovered-resource flags a resource host not covered by the CSP", async () => {
    const root = await tempRoot("abqc-gap-csp-");
    const file = join(root, "index.html");
    await writeFile(
      file,
      `<!doctype html><html><head>
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'">
<script src="https://cdn.tracking.com/x.js"></script>
</head><body></body></html>`,
      "utf8",
    );
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await cspUncoveredResourceChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("csp-uncovered-resource passes when the resource host is covered by the CSP", async () => {
    const root = await tempRoot("abqc-gap-csp-good-");
    const file = join(root, "index.html");
    await writeFile(
      file,
      `<!doctype html><html><head>
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.tracking.com">
<script src="https://cdn.tracking.com/x.js"></script>
</head><body></body></html>`,
      "utf8",
    );
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await cspUncoveredResourceChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("lighthouse-anti-pattern-checklist flags font-display/script/img anti-patterns", async () => {
    const root = await tempRoot("abqc-gap-lighthouse-");
    const cssFile = join(root, "fonts.css");
    await writeFile(cssFile, "@font-face { font-family: 'Brand'; src: url(brand.woff2); }\n", "utf8");
    const htmlFile = join(root, "index.html");
    await writeFile(
      htmlFile,
      `<!doctype html><html><head><script src="/bundle.js"></script></head><body><img src="/hero.jpg"></body></html>`,
      "utf8",
    );
    const ctx: RepoContext = { projectRoot: root, allFiles: [cssFile, htmlFile] };
    const hits = await lighthouseAntiPatternChecklistChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("lighthouse-anti-pattern-checklist passes a compliant build", async () => {
    const root = await tempRoot("abqc-gap-lighthouse-good-");
    const cssFile = join(root, "fonts.css");
    await writeFile(cssFile, "@font-face { font-family: 'Brand'; src: url(brand.woff2); font-display: swap; }\n", "utf8");
    const htmlFile = join(root, "index.html");
    await writeFile(
      htmlFile,
      `<!doctype html><html><head><script src="/bundle.js" defer></script></head><body><img src="/hero.jpg" loading="lazy"></body></html>`,
      "utf8",
    );
    const ctx: RepoContext = { projectRoot: root, allFiles: [cssFile, htmlFile] };
    const hits = await lighthouseAntiPatternChecklistChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("lcp-risk-factors flags a hero image with no dimensions and no preload", async () => {
    const root = await tempRoot("abqc-gap-lcp-");
    const file = join(root, "index.html");
    await writeFile(file, `<!doctype html><html><body><img src="/hero.jpg"></body></html>`, "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await lcpRiskFactorsChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("lcp-risk-factors passes when the hero image has explicit dimensions", async () => {
    const root = await tempRoot("abqc-gap-lcp-good-");
    const file = join(root, "index.html");
    await writeFile(file, `<!doctype html><html><body><img src="/hero.jpg" width="1920" height="1080"></body></html>`, "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await lcpRiskFactorsChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("cls-risk-factors flags an img with no width/height", async () => {
    const root = await tempRoot("abqc-gap-cls-");
    const file = join(root, "Banner.jsx");
    await writeFile(file, `export const Banner = () => <img src="/banner.jpg" alt="banner" />;\n`, "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await clsRiskFactorsChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("cls-risk-factors passes when width/height are set", async () => {
    const root = await tempRoot("abqc-gap-cls-good-");
    const file = join(root, "Banner.jsx");
    await writeFile(file, `export const Banner = () => <img src="/banner.jpg" alt="banner" width="300" height="60" />;\n`, "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await clsRiskFactorsChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("bundle-size flags a dist bundle over 200KB gzipped", async () => {
    const root = await tempRoot("abqc-gap-bundle-");
    await mkdir(join(root, "dist"), { recursive: true });
    const file = join(root, "dist", "index.js");
    await writeFile(file, randomBytes(230 * 1024));
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await bundleSizeChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("bundle-size passes a small dist bundle", async () => {
    const root = await tempRoot("abqc-gap-bundle-good-");
    await mkdir(join(root, "dist"), { recursive: true });
    const file = join(root, "dist", "index.js");
    await writeFile(file, "console.log('hello world');\n");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await bundleSizeChecker.check(ctx);
    assert.equal(hits.length, 0);
  });
});

describe("gap-fill batch — repo-scoped: misc", () => {
  it("admin-ui-publish-workflow flags when no workflow publishes to Production", async () => {
    const root = await tempRoot("abqc-gap-publish-");
    await mkdir(join(root, ".github", "workflows"), { recursive: true });
    const file = join(root, ".github", "workflows", "deploy.yml");
    await writeFile(file, "run: aio app deploy\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await adminUiPublishWorkflowChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("admin-ui-publish-workflow passes when a workflow publishes to Production", async () => {
    const root = await tempRoot("abqc-gap-publish-good-");
    await mkdir(join(root, ".github", "workflows"), { recursive: true });
    const file = join(root, ".github", "workflows", "deploy.yml");
    await writeFile(file, "run: aio app use --workspace Production\nrun: aio app deploy\nrun: aio app publish\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await adminUiPublishWorkflowChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("uix-version-pin flags unpinned @adobe/uix-* versions", async () => {
    const root = await tempRoot("abqc-gap-uix-");
    await writeFile(
      join(root, "package.json"),
      JSON.stringify({ dependencies: { "@adobe/uix-core": "^1.0.0", "@adobe/uix-guest": "latest" } }),
      "utf8",
    );
    // Only applicable to actual Admin UI SDK apps — mark this one via the
    // presence of an ext.config.yaml (a plain, non-Admin-UI-SDK App Builder
    // app never has one).
    const extConfig = join(root, "src", "commerce-backend-ui-1", "ext.config.yaml");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "package.json"), extConfig] };
    const hits = await uixVersionPinChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("uix-version-pin passes with exact pinned versions", async () => {
    const root = await tempRoot("abqc-gap-uix-good-");
    await writeFile(
      join(root, "package.json"),
      JSON.stringify({ dependencies: { "@adobe/uix-core": "0.8.3", "@adobe/uix-guest": "0.8.3" } }),
      "utf8",
    );
    const extConfig = join(root, "src", "commerce-backend-ui-1", "ext.config.yaml");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "package.json"), extConfig] };
    const hits = await uixVersionPinChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("uix-version-pin does not apply to a plain (non-Admin-UI-SDK) App Builder app", async () => {
    const root = await tempRoot("abqc-gap-uix-not-admin-ui-sdk-");
    await writeFile(join(root, "package.json"), JSON.stringify({ dependencies: {} }), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "package.json")] };
    const hits = await uixVersionPinChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("release-approval-doc flags when no docs/release-approvals/ file exists", async () => {
    const root = await tempRoot("abqc-gap-approval-");
    const ctx: RepoContext = { projectRoot: root, allFiles: [] };
    const hits = await releaseApprovalDocChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("release-approval-doc passes when a docs/release-approvals/ file exists", async () => {
    const root = await tempRoot("abqc-gap-approval-good-");
    await mkdir(join(root, "docs", "release-approvals"), { recursive: true });
    const file = join(root, "docs", "release-approvals", "2026-04-release.md");
    await writeFile(file, "# Approved\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [file] };
    const hits = await releaseApprovalDocChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("npm-audit is a no-op when there is no package-lock.json", async () => {
    const root = await tempRoot("abqc-gap-npmaudit-");
    const ctx: RepoContext = { projectRoot: root, allFiles: [] };
    const hits = await npmAuditChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("npm-audit is a no-op when a lockfile exists but no offline audit artifact is present", async () => {
    const root = await tempRoot("abqc-gap-npmaudit-nolock-");
    const lock = join(root, "package-lock.json");
    await writeFile(lock, "{}", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [lock] };
    // No network call — with no committed npm-audit.json artifact it stays silent.
    const hits = await npmAuditChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("npm-audit flags high/critical from a committed offline audit artifact (no network)", async () => {
    const root = await tempRoot("abqc-gap-npmaudit-artifact-");
    const lock = join(root, "package-lock.json");
    const artifact = join(root, "npm-audit.json");
    await writeFile(lock, "{}", "utf8");
    await writeFile(
      artifact,
      JSON.stringify({ metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 2, critical: 1 } } }),
      "utf8",
    );
    const ctx: RepoContext = { projectRoot: root, allFiles: [lock, artifact] };
    const hits = await npmAuditChecker.check(ctx);
    assert.equal(hits.length, 1);
    const [hit] = hits;
    assert.ok(hit);
    assert.match(hit.message, /3 high\/critical/);
    assert.equal(hit.path, artifact);
  });

  // The interpretation logic (JSON parsing + high/critical threshold) is
  // exercised directly with a canned payload — the same shape `npm audit
  // --json` produces. The checker itself now reads a pre-generated, committed
  // artifact rather than shelling out to a live `npm audit` (which would make a
  // registry network call — forbidden under the pure static-analysis contract).
  it("interpretNpmAuditOutput flags high/critical vulnerabilities", () => {
    const payload = JSON.stringify({ metadata: { vulnerabilities: { info: 0, low: 2, moderate: 1, high: 1, critical: 1 } } });
    const hits = interpretNpmAuditOutput(payload);
    assert.equal(hits.length, 1);
    const [hit] = hits;
    assert.ok(hit);
    assert.match(hit.message, /2 high\/critical/);
  });

  it("interpretNpmAuditOutput passes clean when high/critical are both zero", () => {
    const payload = JSON.stringify({ metadata: { vulnerabilities: { info: 3, low: 2, moderate: 1, high: 0, critical: 0 } } });
    assert.equal(interpretNpmAuditOutput(payload).length, 0);
  });

  it("interpretNpmAuditOutput is a no-op on malformed JSON or missing output", () => {
    assert.equal(interpretNpmAuditOutput("not json").length, 0);
    assert.equal(interpretNpmAuditOutput(undefined).length, 0);
  });
});
