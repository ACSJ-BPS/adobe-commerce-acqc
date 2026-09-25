import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdir, mkdtemp } from "node:fs/promises";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, FileChecker, RepoContext } from "../types.js";

import {
  coverageThresholdConfigChecker,
  snapshotOfRenderChecker,
  cloudeventsFixtureShapeChecker,
  testLayoutMirrorChecker,
  rawJestCiChecker,
  vagueTestTitleChecker,
  unmockedCoreLoggerChecker,
  missingClearMocksChecker,
  unmockedHttpInUnitTestChecker,
  enzymeImportChecker,
  implementationDetailTestChecker,
  rawSettimeoutInTestChecker,
  missingMainExportTestChecker,
  missing400PathTestChecker,
  missingErrorShapeTestChecker,
  e2etestFolderChecker,
  smokeE2eSeparationChecker,
} from "../checkers/builtins/testing-checks.js";

import {
  missingPreconnectChecker,
  fontDisplayMissingChecker,
  missingEffectCleanupChecker,
  layoutThrashingChecker,
  localeLangSyncChecker,
  mixedContentChecker,
  missingAuthHeaderFetchChecker,
} from "../checkers/builtins/runtime-checks.js";

import { vagueLinkTextChecker } from "../checkers/builtins/a11y-checks.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

async function loadFixture(name: string): Promise<string> {
  return readFile(join(fixtures, name), "utf8");
}

async function runFile(checker: FileChecker, fixtureName: string, filePath: string) {
  const content = await loadFixture(fixtureName);
  const ctx: CheckContext = {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
  };
  return checker.check(ctx);
}

interface FileCase {
  label: string;
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const FILE_CASES: FileCase[] = [
  {
    label: "snapshot-of-render",
    checker: snapshotOfRenderChecker,
    bad: "bad-snapshot-render.test.js",
    good: "good-snapshot-render.test.js",
    filePath: "/proj/src/OrderRow.test.js",
  },
  {
    label: "cloudevents-fixture-shape",
    checker: cloudeventsFixtureShapeChecker,
    bad: "bad-webhook-cloudevents.test.js",
    good: "good-webhook-cloudevents.test.js",
    filePath: "/proj/test/webhook-handler.test.js",
  },
  {
    label: "vague-test-title",
    checker: vagueTestTitleChecker,
    bad: "bad-vague-test-title.test.js",
    good: "good-vague-test-title.test.js",
    filePath: "/proj/src/util.test.js",
  },
  {
    label: "unmocked-core-logger",
    checker: unmockedCoreLoggerChecker,
    bad: "bad-unmocked-core-logger.test.js",
    good: "good-unmocked-core-logger.test.js",
    filePath: "/proj/test/actions/orders.test.js",
  },
  {
    label: "missing-clear-mocks",
    checker: missingClearMocksChecker,
    bad: "bad-missing-clear-mocks.test.js",
    good: "good-missing-clear-mocks.test.js",
    filePath: "/proj/src/mailer.test.js",
  },
  {
    label: "enzyme-import",
    checker: enzymeImportChecker,
    bad: "bad-enzyme-import.test.js",
    good: "good-enzyme-import.test.js",
    filePath: "/proj/src/OrderRow.test.js",
  },
  {
    label: "implementation-detail-test",
    checker: implementationDetailTestChecker,
    bad: "bad-implementation-detail.test.js",
    good: "good-implementation-detail.test.js",
    filePath: "/proj/src/Counter.test.js",
  },
  {
    label: "raw-settimeout-in-test",
    checker: rawSettimeoutInTestChecker,
    bad: "bad-raw-settimeout.test.js",
    good: "good-raw-settimeout.test.js",
    filePath: "/proj/src/Search.test.js",
  },
  {
    label: "missing-main-export-test",
    checker: missingMainExportTestChecker,
    bad: "bad-missing-main-export.test.js",
    good: "good-missing-main-export.test.js",
    filePath: "/proj/test/actions/orders.test.js",
  },
  {
    label: "missing-400-path-test",
    checker: missing400PathTestChecker,
    bad: "bad-missing-400-path.test.js",
    good: "good-missing-400-path.test.js",
    filePath: "/proj/test/actions/orders.test.js",
  },
  {
    label: "missing-error-shape-test",
    checker: missingErrorShapeTestChecker,
    bad: "bad-missing-error-shape.test.js",
    good: "good-missing-error-shape.test.js",
    filePath: "/proj/test/actions/orders.test.js",
  },
  {
    label: "font-display-missing",
    checker: fontDisplayMissingChecker,
    bad: "bad-font-display.css",
    good: "good-font-display.css",
    filePath: "/proj/src/fonts.css",
  },
  {
    label: "missing-effect-cleanup",
    checker: missingEffectCleanupChecker,
    bad: "bad-effect-cleanup.jsx",
    good: "good-effect-cleanup.jsx",
    filePath: "/proj/src/Ticker.jsx",
  },
  {
    label: "layout-thrashing",
    checker: layoutThrashingChecker,
    bad: "bad-layout-thrashing.js",
    good: "good-layout-thrashing.js",
    filePath: "/proj/src/resize.js",
  },
  {
    label: "locale-lang-sync",
    checker: localeLangSyncChecker,
    bad: "bad-locale-lang-sync.jsx",
    good: "good-locale-lang-sync.jsx",
    filePath: "/proj/src/LocaleSwitcher.jsx",
  },
  {
    label: "missing-auth-header-fetch",
    checker: missingAuthHeaderFetchChecker,
    bad: "bad-auth-header-fetch.js",
    good: "good-auth-header-fetch.js",
    filePath: "/proj/src/api/orders.js",
  },
  {
    label: "vague-link-text",
    checker: vagueLinkTextChecker,
    bad: "bad-vague-link-text.jsx",
    good: "good-vague-link-text.jsx",
    filePath: "/proj/src/Card.jsx",
  },
];

describe("testing/runtime/a11y builtins — file-scoped fixtures", () => {
  for (const c of FILE_CASES) {
    it(`${c.label} flags bad fixture`, async () => {
      const hits = await runFile(c.checker, c.bad, c.filePath);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.label} passes good fixture`, async () => {
      const hits = await runFile(c.checker, c.good, c.filePath);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

describe("testing/runtime builtins — repo-scoped fixtures", () => {
  it("coverage-threshold-config flags a below-80 branches threshold", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-coverage-bad-"));
    await writeFile(join(root, "package.json"), await loadFixture("bad-coverage-package.json"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "package.json")] };
    const hits = await coverageThresholdConfigChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("coverage-threshold-config passes when all thresholds are >= 80", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-coverage-good-"));
    await writeFile(join(root, "package.json"), await loadFixture("good-coverage-package.json"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "package.json")] };
    const hits = await coverageThresholdConfigChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("coverage-threshold-config flags a repo with no coverage config at all", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-coverage-none-"));
    await writeFile(join(root, "package.json"), JSON.stringify({ name: "demo" }), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [join(root, "package.json")] };
    const hits = await coverageThresholdConfigChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("raw-jest-ci flags a workflow invoking jest directly with no aio app test", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-rawjest-bad-"));
    const workflowDir = join(root, ".github", "workflows");
    await mkdir(workflowDir, { recursive: true });
    const workflowPath = join(workflowDir, "ci.yml");
    await writeFile(workflowPath, await loadFixture("bad-raw-jest-ci.yml"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [workflowPath] };
    const hits = await rawJestCiChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("raw-jest-ci passes when CI runs aio app test", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-rawjest-good-"));
    const workflowDir = join(root, ".github", "workflows");
    await mkdir(workflowDir, { recursive: true });
    const workflowPath = join(workflowDir, "ci.yml");
    await writeFile(workflowPath, await loadFixture("good-raw-jest-ci.yml"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [workflowPath] };
    const hits = await rawJestCiChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("missing-preconnect flags an index.html with no preconnect hint for a fetched origin", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-preconnect-bad-"));
    const htmlPath = join(root, "index.html");
    const jsPath = join(root, "src", "api.js");
    await mkdir(dirname(jsPath), { recursive: true });
    await writeFile(htmlPath, await loadFixture("bad-preconnect-index.html"), "utf8");
    await writeFile(jsPath, await loadFixture("preconnect-api.js"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [htmlPath, jsPath] };
    const hits = await missingPreconnectChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("missing-preconnect passes when the origin has a preconnect hint", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-preconnect-good-"));
    const htmlPath = join(root, "index.html");
    const jsPath = join(root, "src", "api.js");
    await mkdir(dirname(jsPath), { recursive: true });
    await writeFile(htmlPath, await loadFixture("good-preconnect-index.html"), "utf8");
    await writeFile(jsPath, await loadFixture("preconnect-api.js"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [htmlPath, jsPath] };
    const hits = await missingPreconnectChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("mixed-content flags http:// resource references", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mixed-bad-"));
    const htmlPath = join(root, "index.html");
    await writeFile(htmlPath, await loadFixture("bad-mixed-content.html"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [htmlPath] };
    const hits = await mixedContentChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("mixed-content passes when all resources are https", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-mixed-good-"));
    const htmlPath = join(root, "index.html");
    await writeFile(htmlPath, await loadFixture("good-mixed-content.html"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [htmlPath] };
    const hits = await mixedContentChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("e2etest-folder flags a non-e2eTest test file referencing 2+ actions", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-e2efolder-bad-"));
    const testPath = join(root, "test", "cross.test.js");
    await mkdir(dirname(testPath), { recursive: true });
    await writeFile(testPath, await loadFixture("bad-cross-action.test.js"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [testPath] };
    const hits = await e2etestFolderChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("e2etest-folder passes when a test only touches one action", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-e2efolder-good-"));
    const testPath = join(root, "test", "cross.test.js");
    await mkdir(dirname(testPath), { recursive: true });
    await writeFile(testPath, await loadFixture("good-cross-action.test.js"), "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [testPath] };
    const hits = await e2etestFolderChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("test-layout-mirror flags a co-located test with no mirrored test/actions/ file", async () => {
    const ctx: RepoContext = {
      projectRoot: "/proj",
      allFiles: ["/proj/actions/demo/index.js", "/proj/actions/demo/index.test.js"],
    };
    const hits = await testLayoutMirrorChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("test-layout-mirror passes when the mirrored test/actions/ file exists", async () => {
    const ctx: RepoContext = {
      projectRoot: "/proj",
      allFiles: ["/proj/actions/demo/index.js", "/proj/test/actions/demo.test.js"],
    };
    const hits = await testLayoutMirrorChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("smoke-e2e-separation flags an e2e folder with no separate smoke folder", async () => {
    const ctx: RepoContext = { projectRoot: "/proj", allFiles: ["/proj/e2eTest/flow.test.js"] };
    const hits = await smokeE2eSeparationChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("smoke-e2e-separation passes when smoke and e2e folders are both present", async () => {
    const ctx: RepoContext = {
      projectRoot: "/proj",
      allFiles: ["/proj/e2eTest/flow.test.js", "/proj/smoke/deploy.test.js"],
    };
    const hits = await smokeE2eSeparationChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("unmocked-http-in-unit-test flags a test whose action calls axios with no mock", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-http-bad-"));
    const actionPath = join(root, "actions", "orders", "index.js");
    const testPath = join(root, "test", "actions", "orders.test.js");
    await mkdir(dirname(actionPath), { recursive: true });
    await mkdir(dirname(testPath), { recursive: true });
    await writeFile(actionPath, await loadFixture("unmocked-http-action.js"), "utf8");
    await writeFile(testPath, await loadFixture("bad-unmocked-http.test.js"), "utf8");
    const ctx: CheckContext = {
      filePath: testPath,
      content: await readFile(testPath, "utf8"),
      ext: ".js",
      projectRoot: root,
    };
    const hits = await unmockedHttpInUnitTestChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("unmocked-http-in-unit-test passes when the http client is mocked", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-http-good-"));
    const actionPath = join(root, "actions", "orders", "index.js");
    const testPath = join(root, "test", "actions", "orders.test.js");
    await mkdir(dirname(actionPath), { recursive: true });
    await mkdir(dirname(testPath), { recursive: true });
    await writeFile(actionPath, await loadFixture("unmocked-http-action.js"), "utf8");
    await writeFile(testPath, await loadFixture("good-unmocked-http.test.js"), "utf8");
    const ctx: CheckContext = {
      filePath: testPath,
      content: await readFile(testPath, "utf8"),
      ext: ".js",
      projectRoot: root,
    };
    const hits = await unmockedHttpInUnitTestChecker.check(ctx);
    assert.equal(hits.length, 0);
  });
});
