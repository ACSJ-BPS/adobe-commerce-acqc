import { describe, it, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { expandPaths, isUnreviewableFile, walkDirectory } from "../walk.js";

let dir: string;

beforeEach(async () => {
  dir = await mkdtemp(join(tmpdir(), "abqc-walk-test-"));
});

afterEach(async () => {
  await rm(dir, { recursive: true, force: true });
});

describe("isUnreviewableFile (P1-1 regression)", () => {
  it("flags .env, secrets, keys, and lock files", () => {
    assert.equal(isUnreviewableFile(".env"), true);
    assert.equal(isUnreviewableFile(".env.production"), true);
    assert.equal(isUnreviewableFile("id_rsa.pem"), true);
    assert.equal(isUnreviewableFile("service-account.key"), true);
    assert.equal(isUnreviewableFile("my-secret-config.json"), true);
    assert.equal(isUnreviewableFile("db-credentials.yaml"), true);
  });

  it("flags known binary/image extensions", () => {
    assert.equal(isUnreviewableFile("logo.png"), true);
    assert.equal(isUnreviewableFile("bundle.wasm"), true);
    assert.equal(isUnreviewableFile("archive.zip"), true);
  });

  it("does not flag ordinary source files", () => {
    assert.equal(isUnreviewableFile("index.js"), false);
    assert.equal(isUnreviewableFile("app.config.yaml"), false);
    assert.equal(isUnreviewableFile("README.md"), false);
  });
});

describe("expandPaths never walks sensitive/binary files (P1-1 regression)", () => {
  it("excludes .env from a directory walk", async () => {
    await writeFile(join(dir, "index.js"), "module.exports.main = () => {};\n");
    await writeFile(join(dir, ".env"), "API_KEY=super-secret-value\n");
    await writeFile(join(dir, "logo.png"), Buffer.from([0x89, 0x50, 0x4e, 0x47]));

    const files = await expandPaths([dir], dir);
    const basenames = files.map((f) => f.slice(f.lastIndexOf("/") + 1));

    assert.ok(basenames.includes("index.js"), "ordinary source file must still be walked");
    assert.ok(!basenames.includes(".env"), ".env must never be walked");
    assert.ok(!basenames.includes("logo.png"), "binary files must never be walked");
  });

  it("refuses an explicitly-named sensitive file even when passed directly", async () => {
    const envPath = join(dir, ".env");
    await writeFile(envPath, "SECRET=abc123\n");

    const files = await expandPaths([envPath], dir);
    assert.equal(files.length, 0, "an explicitly-named .env path must still be refused");
  });
});

describe("walkDirectory — deterministic cap (P2-8 regression)", () => {
  it("keeps the alphabetically-first files when capped, regardless of readdir order", async () => {
    // Names deliberately NOT written in alphabetical order — if the cap read
    // straight off an unsorted readdir(), which files "won" would depend on
    // filesystem entry order (varies by OS/FS), not file name.
    const names = ["zeta.js", "alpha.js", "mike.js", "bravo.js", "delta.js"];
    for (const n of names) await writeFile(join(dir, n), "// x\n");

    const files = await walkDirectory(dir, new Set(), 3);
    const basenames = files.map((f) => f.slice(f.lastIndexOf("/") + 1)).sort();
    assert.deepEqual(basenames, ["alpha.js", "bravo.js", "delta.js"]);
  });
});
