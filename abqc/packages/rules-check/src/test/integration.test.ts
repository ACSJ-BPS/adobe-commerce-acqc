import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { dirname, join, resolve } from "node:path";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { Engine, RuleLoader } from "@abqc/core";
import { defaultRulesDir } from "../rules-dir.js";
import { runMechanicalChecks } from "../runner.js";
import { findBrokenEslintMappings } from "../eslint-map.js";

const rulesDir = defaultRulesDir();
const monorepoRoot = dirname(rulesDir);
const sampleApp = resolve(monorepoRoot, "examples/sample-app");

async function loadRules() {
  const loader = new RuleLoader(rulesDir);
  return loader.load();
}

describe("eslint-map integrity", () => {
  it("every ESLint→library target resolves to a real rule id", async () => {
    const rules = await loadRules();
    const broken = findBrokenEslintMappings(rules);
    assert.equal(
      broken.length,
      0,
      `broken eslint mappings:\n${broken.map((b) => `  ${b.eslintId} → ${b.libraryId}`).join("\n")}`,
    );
  });
});

describe("runMechanicalChecks — end to end", () => {
  it("produces findings tagged by source on the sample app", async () => {
    const rules = await loadRules();
    const result = await runMechanicalChecks({
      paths: [sampleApp],
      cwd: sampleApp,
      rules,
      eslint: true,
    });
    assert.ok(result.filesScanned > 0, "expected to scan at least one file");
    assert.ok(result.checkersRun >= 22, `expected >= 22 declarative checkers, got ${result.checkersRun}`);
    for (const f of result.findings) {
      assert.ok(
        f.source === "mechanical" || f.source === "eslint",
        `unexpected finding source: ${f.source}`,
      );
      assert.ok(f.ruleId, "finding must carry a rule id");
    }
  });

  it("mechanical findings reference real library rule ids", async () => {
    const rules = await loadRules();
    const ids = new Set(rules.map((r) => r.id));
    const result = await runMechanicalChecks({
      paths: [sampleApp],
      cwd: sampleApp,
      rules,
      eslint: false,
    });
    for (const f of result.findings.filter((x) => x.source === "mechanical")) {
      assert.ok(ids.has(f.ruleId), `mechanical finding uses unknown rule id: ${f.ruleId}`);
    }
  });
});

describe("runMechanicalChecks — explicit `files` scoping (P0-1 regression)", () => {
  it("checks only the files passed via `files`, not everything under `paths`", async () => {
    const rules = await loadRules();
    const dir = await mkdtemp(join(tmpdir(), "abqc-diff-scope-test-"));
    try {
      const changed = join(dir, "changed.js");
      const unchanged = join(dir, "unchanged.js");
      // Deliberately identical content — if `files` scoping didn't work, both
      // would be scanned and both would appear in `evaluated`/findings.
      await writeFile(changed, "const x = 1 == 1;\nmodule.exports.main = () => x;\n");
      await writeFile(unchanged, "const x = 1 == 1;\nmodule.exports.main = () => x;\n");

      const result = await runMechanicalChecks({
        paths: [dir], // the whole directory — mimics a full `input.paths`
        files: [changed], // but only `changed.js` was actually diff-scoped
        cwd: dir,
        rules,
        eslint: true,
      });

      assert.equal(result.filesScanned, 1, "must scan exactly the one file passed via `files`");
      for (const { filePath } of result.evaluated) {
        assert.notEqual(filePath, unchanged, "`evaluated` must not reference a file outside the diff scope");
      }
      for (const f of result.findings) {
        assert.notEqual(f.filePath, unchanged, "findings must not reference a file outside the diff scope");
      }
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  });
});

describe("Engine.review — MCP scenario (staticOnly + default mechanical)", () => {
  it("populates routedRules so callers can see files were reviewed", async () => {
    const engine = new Engine({ rulesDir, staticOnly: true });
    await engine.ready();
    const result = await engine.review({ paths: [sampleApp], cwd: sampleApp });
    assert.ok(result.routedRules.size > 0, "routedRules must be populated even in staticOnly mechanical mode");
    assert.ok(result.findings.length >= 0);
    // Regression guard for the MCP "no files reviewed" bug: findings without routedRules.
    const wouldFailLikeMcp = result.routedRules.size === 0 && result.findings.length > 0;
    assert.equal(wouldFailLikeMcp, false, "MCP would falsely report no files reviewed");
  });

  it("--no-mechanical path still routes and runs ESLint", async () => {
    const engine = new Engine({ rulesDir, staticOnly: true });
    await engine.ready();
    const result = await engine.review({ paths: [sampleApp], cwd: sampleApp, mechanical: false });
    assert.ok(result.routedRules.size > 0, "routedRules must be populated in ESLint-only mode");
  });
});
