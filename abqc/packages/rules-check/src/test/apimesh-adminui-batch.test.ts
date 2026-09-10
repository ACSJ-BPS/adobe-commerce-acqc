import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdir, mkdtemp } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, RepoContext, FileChecker } from "../types.js";
import {
  meshHeaderSecretsChecker,
  noImsTokenInResponseChecker,
  meshCachePersonalizedChecker,
  meshLiteralSecretChecker,
  meshConfigSizeChecker,
  meshAdminUnfilteredChecker,
  meshConfigGitCiChecker,
  meshLogForwardingChecker,
  meshHttpDetailsProdChecker,
  meshDevProdEndpointChecker,
  meshDiffBeforeUpdateChecker,
  meshResolverNPlusOneChecker,
  meshResolverPageSizeGuardChecker,
  meshNoDepthGuardChecker,
  meshIntrospectionProdChecker,
  meshSsrfEndpointChecker,
  meshEndpointHttpsChecker,
  meshFilterSchemaMissingChecker,
  meshResolverThinChecker,
} from "../checkers/builtins/apimesh.js";
import {
  productDependenciesChecker,
  noHandRolledPostMessageChecker,
  extensionIdConsistencyChecker,
  oneMenuEntryChecker,
  registrationPayloadPureChecker,
  noLogImsTokenChecker,
  legacyApplicationPatternChecker,
  adminUiCodeLocationChecker,
  applicationBlockLocationChecker,
} from "../checkers/builtins/admin-ui-sdk.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

interface FileCase {
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const FILE_CASES: FileCase[] = [
  { checker: meshHeaderSecretsChecker, bad: "bad-mesh-header-secrets.json", good: "good-mesh-header-secrets.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: noImsTokenInResponseChecker, bad: "bad-ims-token-response.js", good: "good-ims-token-response.js", filePath: "/proj/actions/mesh-proxy/index.js" },
  { checker: meshCachePersonalizedChecker, bad: "bad-mesh-cache-personalized.json", good: "good-mesh-cache-personalized.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: meshLiteralSecretChecker, bad: "bad-mesh-literal-secret.json", good: "good-mesh-literal-secret.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: meshAdminUnfilteredChecker, bad: "bad-mesh-admin-unfiltered.json", good: "good-mesh-admin-unfiltered.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: meshFilterSchemaMissingChecker, bad: "bad-mesh-filterschema-missing.json", good: "good-mesh-filterschema-missing.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: meshLogForwardingChecker, bad: "bad-mesh-log-forwarding.json", good: "good-mesh-log-forwarding.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: meshHttpDetailsProdChecker, bad: "bad-mesh-httpdetails-prod.json", good: "good-mesh-httpdetails-prod.json", filePath: "/proj/mesh/prod/meshConfig.json" },
  { checker: meshResolverNPlusOneChecker, bad: "bad-resolver-n-plus-1.js", good: "good-resolver-n-plus-1.js", filePath: "/proj/resolvers/orders.js" },
  { checker: meshResolverPageSizeGuardChecker, bad: "bad-resolver-pagesize-guard.js", good: "good-resolver-pagesize-guard.js", filePath: "/proj/resolvers/products.js" },
  { checker: meshNoDepthGuardChecker, bad: "bad-no-depth-guard.js", good: "good-no-depth-guard.js", filePath: "/proj/resolvers/tree.js" },
  { checker: meshIntrospectionProdChecker, bad: "bad-mesh-introspection-prod.json", good: "good-mesh-introspection-prod.json", filePath: "/proj/mesh/prod/meshConfig.json" },
  { checker: meshSsrfEndpointChecker, bad: "bad-mesh-ssrf-endpoint.json", good: "good-mesh-ssrf-endpoint.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: meshEndpointHttpsChecker, bad: "bad-mesh-endpoint-https.json", good: "good-mesh-endpoint-https.json", filePath: "/proj/mesh/meshConfig.json" },
  { checker: meshResolverThinChecker, bad: "bad-resolver-thin.js", good: "good-resolver-thin.js", filePath: "/proj/resolvers/discount.js" },
  { checker: productDependenciesChecker, bad: "bad-product-dependencies.yaml", good: "good-product-dependencies.yaml", filePath: "/proj/app.config.yaml" },
  { checker: noHandRolledPostMessageChecker, bad: "bad-no-hand-rolled-postmessage.js", good: "good-no-hand-rolled-postmessage.js", filePath: "/proj/web-src/src/ims-bridge.js" },
  { checker: oneMenuEntryChecker, bad: "bad-one-menu-entry.yaml", good: "good-one-menu-entry.yaml", filePath: "/proj/app.config.yaml" },
  { checker: registrationPayloadPureChecker, bad: "bad-registration-payload-pure.js", good: "good-registration-payload-pure.js", filePath: "/proj/actions/registration/index.js" },
  { checker: noLogImsTokenChecker, bad: "bad-no-log-ims-token.js", good: "good-no-log-ims-token.js", filePath: "/proj/actions/notify/index.js" },
  { checker: legacyApplicationPatternChecker, bad: "bad-legacy-application-pattern.yaml", good: "good-legacy-application-pattern.yaml", filePath: "/proj/app.config.yaml" },
  { checker: applicationBlockLocationChecker, bad: "bad-application-block-location.yaml", good: "good-application-block-location.yaml", filePath: "/proj/app.config.yaml" },
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

describe("api-mesh / admin-ui-sdk builtins — file-scoped fixtures", () => {
  for (const c of FILE_CASES) {
    it(`${c.checker.ruleId} flags bad fixture (${c.bad})`, async () => {
      const hits = await runFile(c.checker, c.bad, c.filePath);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.checker.ruleId} passes good fixture (${c.good})`, async () => {
      const hits = await runFile(c.checker, c.good, c.filePath);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

describe("api-mesh builtins — repo-scoped", () => {
  it("mesh-config-size flags an oversized meshConfig.json", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-size-"));
    const big = JSON.stringify({ meshConfig: { sources: [], padding: "x".repeat(400 * 1024) } });
    await writeFile(join(root, "meshConfig.json"), big, "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "meshConfig.json")] };
    const hits = await meshConfigSizeChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("mesh-config-size passes a small meshConfig.json", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-size-"));
    await writeFile(join(root, "meshConfig.json"), JSON.stringify({ meshConfig: { sources: [] } }), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "meshConfig.json")] };
    const hits = await meshConfigSizeChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("mesh-config-git-ci flags an untracked meshConfig.json with no CI deploy step", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-git-"));
    await writeFile(join(root, "meshConfig.json"), JSON.stringify({ meshConfig: {} }), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "meshConfig.json")] };
    const hits = await meshConfigGitCiChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("mesh-config-git-ci passes when meshConfig.json is git-tracked and CI runs aio api-mesh:update", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-git-good-"));
    execFileSync("git", ["init"], { cwd: root });
    execFileSync("git", ["config", "user.email", "test@example.com"], { cwd: root });
    execFileSync("git", ["config", "user.name", "Test"], { cwd: root });
    await writeFile(join(root, "meshConfig.json"), JSON.stringify({ meshConfig: {} }), "utf8");
    await mkdir(join(root, ".github", "workflows"), { recursive: true });
    await writeFile(join(root, ".github", "workflows", "deploy.yml"), "run: aio api-mesh:update\n", "utf8");
    execFileSync("git", ["add", "."], { cwd: root });
    execFileSync("git", ["commit", "-m", "init"], { cwd: root });
    const allFiles = [join(root, "meshConfig.json"), join(root, ".github", "workflows", "deploy.yml")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await meshConfigGitCiChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("mesh-dev-prod-endpoint flags a dev mesh pointed at a literal production host", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-devprod-"));
    await mkdir(join(root, "mesh", "dev"), { recursive: true });
    await mkdir(join(root, "mesh", "prod"), { recursive: true });
    const prodConfig = { meshConfig: { sources: [{ name: "Commerce", handler: { graphql: { endpoint: "https://prod.commerce.example.com/graphql" } } }] } };
    const devConfig = { meshConfig: { sources: [{ name: "Commerce", handler: { graphql: { endpoint: "https://prod.commerce.example.com/graphql" } } }] } };
    await writeFile(join(root, "mesh", "prod", "meshConfig.json"), JSON.stringify(prodConfig), "utf8");
    await writeFile(join(root, "mesh", "dev", "meshConfig.json"), JSON.stringify(devConfig), "utf8");
    const allFiles = [join(root, "mesh", "prod", "meshConfig.json"), join(root, "mesh", "dev", "meshConfig.json")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await meshDevProdEndpointChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("mesh-dev-prod-endpoint passes when dev and prod endpoints differ", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-devprod-good-"));
    await mkdir(join(root, "mesh", "dev"), { recursive: true });
    await mkdir(join(root, "mesh", "prod"), { recursive: true });
    const prodConfig = { meshConfig: { sources: [{ name: "Commerce", handler: { graphql: { endpoint: "https://prod.commerce.example.com/graphql" } } }] } };
    const devConfig = { meshConfig: { sources: [{ name: "Commerce", handler: { graphql: { endpoint: "{{env.DEV_COMMERCE_ENDPOINT}}" } } }] } };
    await writeFile(join(root, "mesh", "prod", "meshConfig.json"), JSON.stringify(prodConfig), "utf8");
    await writeFile(join(root, "mesh", "dev", "meshConfig.json"), JSON.stringify(devConfig), "utf8");
    const allFiles = [join(root, "mesh", "prod", "meshConfig.json"), join(root, "mesh", "dev", "meshConfig.json")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await meshDevProdEndpointChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("mesh-diff-before-update flags CI that runs mesh:update without mesh:get", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-diff-"));
    await mkdir(join(root, ".github", "workflows"), { recursive: true });
    await writeFile(join(root, ".github", "workflows", "deploy.yml"), "steps:\n  - run: aio api-mesh:update\n", "utf8");
    const allFiles = [join(root, ".github", "workflows", "deploy.yml")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await meshDiffBeforeUpdateChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("mesh-diff-before-update passes when CI diffs before updating", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mesh-diff-good-"));
    await mkdir(join(root, ".github", "workflows"), { recursive: true });
    await writeFile(join(root, ".github", "workflows", "deploy.yml"), "steps:\n  - run: aio api-mesh:get\n  - run: aio api-mesh:update\n", "utf8");
    const allFiles = [join(root, ".github", "workflows", "deploy.yml")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await meshDiffBeforeUpdateChecker.check(ctx);
    assert.equal(hits.length, 0);
  });
});

describe("admin-ui-sdk builtins — repo-scoped", () => {
  it("extension-id-consistency flags mismatched register()/attach() ids", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-extid-"));
    await mkdir(join(root, "web-src", "src"), { recursive: true });
    await writeFile(join(root, "web-src", "src", "a.js"), "register({ id: 'vendor.ext-a' });\n", "utf8");
    await writeFile(join(root, "web-src", "src", "b.js"), "attach({ id: 'vendor.ext-b' });\n", "utf8");
    const allFiles = [join(root, "web-src", "src", "a.js"), join(root, "web-src", "src", "b.js")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await extensionIdConsistencyChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("extension-id-consistency passes when ids match", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-extid-good-"));
    await mkdir(join(root, "web-src", "src"), { recursive: true });
    await writeFile(join(root, "web-src", "src", "a.js"), "register({ id: 'vendor.ext-a' });\n", "utf8");
    await writeFile(join(root, "web-src", "src", "b.js"), "attach({ id: 'vendor.ext-a' });\n", "utf8");
    const allFiles = [join(root, "web-src", "src", "a.js"), join(root, "web-src", "src", "b.js")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await extensionIdConsistencyChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("admin-ui-code-location flags actions/ and web-src/ at repo root when using the extensions pattern", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-adminloc-"));
    await mkdir(join(root, "actions"), { recursive: true });
    await mkdir(join(root, "web-src"), { recursive: true });
    await writeFile(join(root, "app.config.yaml"), "extensions:\n  commerce/backend-ui/1:\n    actions: src/commerce-backend-ui-1/actions\n", "utf8");
    await writeFile(join(root, "actions", "index.js"), "exports.main = async () => ({});\n", "utf8");
    await writeFile(join(root, "web-src", "index.js"), "console.log('hi');\n", "utf8");
    const allFiles = [join(root, "app.config.yaml"), join(root, "actions", "index.js"), join(root, "web-src", "index.js")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await adminUiCodeLocationChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("admin-ui-code-location passes when Admin UI SDK code lives under src/commerce-backend-ui-<N>/", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-adminloc-good-"));
    await mkdir(join(root, "src", "commerce-backend-ui-1", "actions"), { recursive: true });
    await writeFile(join(root, "app.config.yaml"), "extensions:\n  commerce/backend-ui/1:\n    actions: src/commerce-backend-ui-1/actions\n", "utf8");
    await writeFile(join(root, "src", "commerce-backend-ui-1", "actions", "index.js"), "exports.main = async () => ({});\n", "utf8");
    const allFiles = [join(root, "app.config.yaml"), join(root, "src", "commerce-backend-ui-1", "actions", "index.js")];
    const ctx: RepoContext = { projectRoot: root, allFiles };
    const hits = await adminUiCodeLocationChecker.check(ctx);
    assert.equal(hits.length, 0);
  });
});
