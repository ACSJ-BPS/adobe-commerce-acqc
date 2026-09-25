import { describe, it, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, rm, writeFile, mkdir } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { RuleLoader } from "../rule-loader.js";

let dir: string;

beforeEach(async () => {
  dir = await mkdtemp(join(tmpdir(), "abqc-rule-loader-test-"));
  await mkdir(join(dir, "widgets"), { recursive: true });
});

afterEach(async () => {
  await rm(dir, { recursive: true, force: true });
});

describe("RuleLoader — malformed rule heading warning (P2-2 regression)", () => {
  it("loads a well-formed rule normally", async () => {
    await writeFile(
      join(dir, "widgets", "basics.md"),
      "## Widgets must be round [error] [shape]\nRationale text.\n### Bad\nsquare\n### Good\nround\n",
    );
    const loader = new RuleLoader(dir);
    const rules = await loader.load();
    assert.equal(rules.length, 1);
    assert.equal(rules[0]?.severity, "error");
  });

  it("warns to stderr and skips a heading with a typo'd severity", async () => {
    await writeFile(
      join(dir, "widgets", "typo.md"),
      "## Widgets must be round [warning] [shape]\nRationale text.\n### Bad\nsquare\n### Good\nround\n",
    );
    const loader = new RuleLoader(dir);
    const originalWrite = process.stderr.write.bind(process.stderr);
    const captured: string[] = [];
    process.stderr.write = ((chunk: string) => {
      captured.push(String(chunk));
      return true;
    }) as typeof process.stderr.write;
    let rules;
    try {
      rules = await loader.load();
    } finally {
      process.stderr.write = originalWrite;
    }
    assert.equal(rules.length, 0, "a malformed heading must not be loaded as a rule");
    assert.ok(
      captured.some((line) => line.includes("looks like a rule") && line.includes("typo.md")),
      "must warn to stderr that a rule-like heading was skipped",
    );
  });

  it("does not warn for an ordinary non-rule H2 section", async () => {
    await writeFile(join(dir, "widgets", "readme-like.md"), "## Overview\nJust prose, not a rule.\n");
    const loader = new RuleLoader(dir);
    const originalWrite = process.stderr.write.bind(process.stderr);
    const captured: string[] = [];
    process.stderr.write = ((chunk: string) => {
      captured.push(String(chunk));
      return true;
    }) as typeof process.stderr.write;
    try {
      await loader.load();
    } finally {
      process.stderr.write = originalWrite;
    }
    assert.ok(!captured.some((line) => line.includes("looks like a rule")));
  });
});
