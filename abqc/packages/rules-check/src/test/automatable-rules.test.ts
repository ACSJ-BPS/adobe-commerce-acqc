import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdir, mkdtemp } from "node:fs/promises";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import { RuleLoader } from "@abqc/core";
import { defaultRulesDir } from "../rules-dir.js";
import { parseCheckSpec } from "../checkers/spec.js";
import { runTypeCheck } from "../checkers/type-registry.js";
import type { CheckContext, RepoContext } from "../types.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

interface FixtureCase {
  ruleId: string;
  bad: string;
  good: string;
  /** Virtual absolute path passed to checkers (must match rule `files` globs). */
  filePath: string;
  scope?: "file" | "repo";
  repoSetup?: (root: string) => Promise<void>;
  // Repo-scoped checkers that key off resolved action ENTRY files (e.g. the
  // README checker) need this seeded, or their scan set is empty and the
  // checker vacuously reports zero hits regardless of the fixture's content.
  actionEntryFiles?: (root: string) => Set<string>;
}

const FILE_CASES: FixtureCase[] = [
  {
    ruleId: "node/correctness/strict-equality-only-eqeqeq",
    bad: "bad-eqeq.js",
    good: "good-eqeq.js",
    filePath: "/proj/src/index.js",
  },
  {
    ruleId: "node/bug-classes/buffer-alloc-not-new-buffer",
    bad: "bad-new-buffer.js",
    good: "good-new-buffer.js",
    filePath: "/proj/src/index.js",
  },
  {
    ruleId: "appbuilder/runtime/action-handler-signature-main-params-exports-main-main",
    bad: "bad-main.js",
    good: "good-main.js",
    filePath: "/proj/actions/demo/index.js",
  },
  {
    ruleId: "appbuilder/runtime/wrap-entire-main-body-in-try-catch-unhandled-async-kills-the-con",
    bad: "bad-try-catch.js",
    good: "good-try-catch.js",
    filePath: "/proj/actions/demo/index.js",
  },
  {
    ruleId: "appbuilder/runtime/web-action-timeout-60s-blocking-30s-through-cdn",
    bad: "bad-web-timeout.yaml",
    good: "good-web-timeout.yaml",
    filePath: "/proj/actions/webhook/actions.config.yaml",
  },
  {
    ruleId: "appbuilder/runtime/memory-only-256-512-1024-mb-hit-prewarmed-containers",
    bad: "bad-memory-tier.yaml",
    good: "good-memory-tier.yaml",
    filePath: "/proj/actions/webhook/actions.config.yaml",
  },
  {
    ruleId: "appbuilder/runtime/mark-every-action-as-final-true-prevent-sequence-injection",
    bad: "bad-no-final.yaml",
    good: "good-no-final.yaml",
    filePath: "/proj/actions/webhook/actions.config.yaml",
  },
  {
    ruleId: "appbuilder/config/action-file-paths-must-be-relative-to-the-config-file",
    bad: "bad-abs-path.yaml",
    good: "good-abs-path.yaml",
    filePath: "/proj/actions.config.yaml",
  },
  {
    ruleId: "appbuilder/config/use-include-directive-for-large-app-config-yaml-files",
    bad: "bad-large-app.config.yaml",
    good: "good-large-app.config.yaml",
    filePath: "/proj/app.config.yaml",
  },
  {
    ruleId: "documentation/code/jsdoc-on-every-exported-function-params-returns-throws",
    bad: "bad-jsdoc.js",
    good: "good-jsdoc.js",
    filePath: "/proj/src/helper.js",
  },
  {
    ruleId: "imports/ordering/imports-go-at-the-top-of-the-file-first",
    bad: "bad-imports.js",
    good: "good-imports.js",
    filePath: "/proj/actions/demo/index.js",
  },
  {
    ruleId: "node/bug-classes/use-crypto-randomuuid-not-math-random-for-ids-and-tokens",
    bad: "bad-math-random.js",
    good: "good-math-random.js",
    filePath: "/proj/src/token.js",
  },
  {
    ruleId: "node/modern-apis/use-the-node-prefix-for-all-built-in-modules",
    bad: "bad-node-prefix.js",
    good: "good-node-prefix.js",
    filePath: "/proj/src/index.js",
  },
  {
    ruleId: "node/complexity/cyclomatic-complexity-10-per-function",
    bad: "bad-cyclomatic.js",
    good: "good-cyclomatic.js",
    filePath: "/proj/src/classify.js",
  },
  {
    ruleId: "node/complexity/function-length-50-lines",
    bad: "bad-function-length.js",
    good: "good-function-length.js",
    filePath: "/proj/src/orders.js",
  },
  {
    ruleId: "node/complexity/max-4-parameters-per-function-use-an-options-object",
    bad: "bad-max-params.js",
    good: "good-max-params.js",
    filePath: "/proj/src/sync.js",
  },
  {
    ruleId: "node/complexity/max-nesting-depth-4",
    bad: "bad-max-nesting.js",
    good: "good-max-nesting.js",
    filePath: "/proj/src/nest.js",
  },
  {
    ruleId: "node/complexity/max-file-length-300-lines-excluding-comments-and-blanks",
    bad: "bad-max-file-length.js",
    good: "good-max-file-length.js",
    filePath: "/proj/src/huge.js",
  },
  {
    ruleId: "security/supply-chain/no-hard-coded-api-keys-in-source-params-git-history",
    bad: "bad-inline-secret.js",
    good: "good-inline-secret.js",
    filePath: "/proj/src/config.js",
  },
  // Same secret rule, now also scanning App Builder YAML config inputs.
  {
    ruleId: "security/supply-chain/no-hard-coded-api-keys-in-source-params-git-history",
    bad: "bad-yaml-secret.yaml",
    good: "good-yaml-secret.yaml",
    filePath: "/proj/actions.config.yaml",
  },
  {
    ruleId: "appbuilder/runtime/concurrency-cap-is-500-per-container-design-for-it",
    bad: "bad-concurrency.yaml",
    good: "good-concurrency.yaml",
    filePath: "/proj/actions.config.yaml",
  },
  {
    ruleId: "appbuilder/runtime/restrict-web-action-invocation-with-allowedhosts",
    bad: "bad-allowed-hosts.yaml",
    good: "good-allowed-hosts.yaml",
    filePath: "/proj/actions.config.yaml",
  },
  {
    ruleId: "appbuilder/runtime/use-a-supported-node-runtime-nodejs-18-and-older-are-eol",
    bad: "bad-runtime-version.yaml",
    good: "good-runtime-version.yaml",
    filePath: "/proj/actions.config.yaml",
  },
  {
    ruleId: "security/eslint-security/no-pseudorandombytes-for-security-sensitive-values",
    bad: "bad-pseudo-random-bytes.js",
    good: "good-pseudo-random-bytes.js",
    filePath: "/proj/src/token.js",
  },
  {
    ruleId: "security/adobe/every-web-action-must-declare-require-adobe-auth",
    bad: "bad-require-auth.yaml",
    good: "good-require-auth.yaml",
    filePath: "/proj/actions.config.yaml",
  },
];

const REPO_CASES: FixtureCase[] = [
  {
    ruleId: "appbuilder/secrets/never-commit-env-or-aio-to-git",
    bad: "",
    good: "",
    filePath: "/proj/.gitignore",
    scope: "repo",
    repoSetup: async (root) => {
      await writeFile(join(root, ".gitignore"), "node_modules/\n", "utf8");
    },
  },
  {
    ruleId: "security/supply-chain/pin-exact-versions-for-security-sensitive-packages-no-semver-ran",
    bad: "",
    good: "",
    filePath: "/proj/package.json",
    scope: "repo",
    repoSetup: async (root) => {
      await writeFile(
        join(root, "package.json"),
        JSON.stringify({ dependencies: { jsonwebtoken: "^9.0.0" } }, null, 2),
        "utf8",
      );
    },
  },
  {
    ruleId: "documentation/project/every-action-has-a-readme-purpose-inputs-outputs-examples",
    bad: "",
    good: "",
    filePath: "/proj/actions/demo",
    scope: "repo",
    repoSetup: async (root) => {
      const actionDir = join(root, "actions", "demo");
      await mkdir(actionDir, { recursive: true });
      await writeFile(join(actionDir, "index.js"), "exports.main = async () => ({});\n", "utf8");
    },
    actionEntryFiles: (root) => new Set([join(root, "actions", "demo", "index.js")]),
  },
];

async function loadRule(ruleId: string) {
  const loader = new RuleLoader(defaultRulesDir());
  const rules = await loader.load();
  const rule = rules.find((r) => r.id === ruleId);
  assert.ok(rule, `rule not found: ${ruleId}`);
  const check = parseCheckSpec(rule.checkSpec);
  assert.ok(check, `missing ### Check for ${ruleId}`);
  return { rule, check };
}

async function runFileCheck(ruleId: string, fixtureName: string, filePath: string) {
  const { rule, check } = await loadRule(ruleId);
  const content = await readFile(join(fixtures, fixtureName), "utf8");
  const ctx: CheckContext = {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
    actionEntryFiles: filePath.includes("/actions/") ? new Set([filePath]) : undefined,
  };
  return runTypeCheck({ rule, spec: check, file: ctx });
}

describe("automatable rules — file-scoped fixtures", () => {
  for (const c of FILE_CASES) {
    it(`${c.ruleId} flags bad fixture`, async () => {
      const hits = await runFileCheck(c.ruleId, c.bad, c.filePath);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.ruleId} passes good fixture`, async () => {
      const hits = await runFileCheck(c.ruleId, c.good, c.filePath);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

describe("automatable rules — repo-scoped fixtures", () => {
  for (const c of REPO_CASES) {
    it(`${c.ruleId} flags bad repo layout`, async () => {
      const { rule, check } = await loadRule(c.ruleId);
      const root = await mkdtemp(join(tmpdir(), "abqc-repo-bad-"));
      await c.repoSetup!(root);
      const ctx: RepoContext = {
        projectRoot: root,
        allFiles: [],
        actionEntryFiles: c.actionEntryFiles ? c.actionEntryFiles(root) : new Set(),
      };
      const hits = await runTypeCheck({ rule, spec: check, repo: ctx });
      assert.ok(hits.length > 0);
    });
  }

  it("secrets-git passes when .env is gitignored", async () => {
    const { rule, check } = await loadRule("appbuilder/secrets/never-commit-env-or-aio-to-git");
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-good-"));
    // console.json joined the checked-file list alongside .env/.aio (gap-fill
    // batch item 8) — it must be gitignored too for this fixture to stay clean.
    await writeFile(join(root, ".gitignore"), ".env\n.aio\nconsole.json\n", "utf8");
    const hits = await runTypeCheck({ rule, spec: check, repo: { projectRoot: root, allFiles: [] } });
    assert.equal(hits.length, 0);
  });

  it("pin-versions passes when sensitive deps are pinned", async () => {
    const { rule, check } = await loadRule("security/supply-chain/pin-exact-versions-for-security-sensitive-packages-no-semver-ran");
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-good-"));
    await writeFile(join(root, "package.json"), JSON.stringify({ dependencies: { jsonwebtoken: "9.0.2" } }), "utf8");
    const hits = await runTypeCheck({ rule, spec: check, repo: { projectRoot: root, allFiles: [] } });
    assert.equal(hits.length, 0);
  });

  it("action-readme passes when README exists", async () => {
    const { rule, check } = await loadRule("documentation/project/every-action-has-a-readme-purpose-inputs-outputs-examples");
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-good-"));
    const actionDir = join(root, "actions", "demo");
    await mkdir(actionDir, { recursive: true });
    await writeFile(join(actionDir, "index.js"), "exports.main = async () => ({});\n", "utf8");
    await writeFile(join(actionDir, "README.md"), "# Demo\n", "utf8");
    const hits = await runTypeCheck({
      rule,
      spec: check,
      repo: { projectRoot: root, allFiles: [], actionEntryFiles: new Set([join(actionDir, "index.js")]) },
    });
    assert.equal(hits.length, 0);
  });
});
