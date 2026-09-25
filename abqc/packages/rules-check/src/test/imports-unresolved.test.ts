import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, writeFile, mkdir } from "node:fs/promises";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { unresolvedImportChecker } from "../checkers/builtins/imports-unresolved.js";
import type { RepoContext } from "../types.js";

describe("unresolved-import", () => {
  it("flags a require() of a typo'd path that doesn't resolve", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-unresolved-bad-"));
    await mkdir(join(root, "utils"), { recursive: true });
    await writeFile(join(root, "utils", "logger.js"), "module.exports = {};\n", "utf8");
    const indexPath = join(root, "index.js");
    await writeFile(indexPath, "const { logger } = require('./utlis/logger');\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [indexPath, join(root, "utils", "logger.js")] };
    const hits = await unresolvedImportChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("passes when the relative import resolves", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-unresolved-good-"));
    await mkdir(join(root, "utils"), { recursive: true });
    await writeFile(join(root, "utils", "logger.js"), "module.exports = {};\n", "utf8");
    const indexPath = join(root, "index.js");
    await writeFile(indexPath, "const { logger } = require('./utils/logger');\n", "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [indexPath, join(root, "utils", "logger.js")] };
    const hits = await unresolvedImportChecker.check(ctx);
    assert.equal(hits.length, 0);
  });
});
