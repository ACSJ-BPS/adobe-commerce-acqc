import { describe, it, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { randomUUID } from "node:crypto";
import {
  ledger,
  persistRun,
  getRun,
  mergeDiskState,
  withRunLock,
  isAcceptableEvidence,
  pickNextTarget,
  type LedgerRun,
} from "../ledger.js";

let projectRoot: string;

beforeEach(async () => {
  projectRoot = await mkdtemp(join(tmpdir(), "abqc-ledger-test-"));
  ledger.clear();
});

afterEach(async () => {
  await rm(projectRoot, { recursive: true, force: true });
});

function makeRun(cwd: string): LedgerRun {
  return {
    cwd,
    createdAt: Date.now(),
    files: new Map([
      [
        "/repo/a.js",
        { expected: new Set(["r1", "r2"]), verdicts: new Map(), locked: new Set() },
      ],
    ]),
  };
}

describe("isAcceptableEvidence", () => {
  it("rejects empty/short/generic evidence", () => {
    assert.equal(isAcceptableEvidence(undefined), false);
    assert.equal(isAcceptableEvidence("ok"), false);
    assert.equal(isAcceptableEvidence("reviewed, satisfied"), false);
    assert.equal(isAcceptableEvidence("rule requirements met or not applicable"), false);
  });

  it("accepts evidence that cites the actual code", () => {
    assert.equal(isAcceptableEvidence("handler awaits every fetch call before returning"), true);
    assert.equal(isAcceptableEvidence("parseInt is called with an explicit radix 10 on line 42"), true);
  });

  const rule = {
    ruleTitle: "Imported modules must be declared in package.json",
    ruleRationale: "Undeclared dependencies break installs on a clean checkout.",
  };

  it("rejects the real-world exploit phrasing (templated fallback pass)", () => {
    assert.equal(
      isAcceptableEvidence("Reviewed index.js — no pattern matching Imported modules must be declared in package.json", rule),
      false,
    );
    assert.equal(
      isAcceptableEvidence("Supplemental pass for appbuilder/imports/declared — reviewed, no violation pattern found", rule),
      false,
    );
  });

  it("rejects evidence that only echoes the rule's own title/rationale", () => {
    // Every substantive word here comes straight from the rule's title/rationale
    // above — no code-specific content at all, just a reshuffled restatement.
    assert.equal(
      isAcceptableEvidence("Modules are declared in package json; undeclared dependencies break installs on a clean checkout", rule),
      false,
    );
  });

  it("accepts evidence that echoes the rule but also cites a real construct from the file", () => {
    const fileContent = "const axios = require('axios');\nmodule.exports = { fetchOrder };";
    assert.equal(
      isAcceptableEvidence("axios is declared as a dependency and imported at the top of the file", {
        ...rule,
        fileContent,
      }),
      true,
    );
  });

  it("rejects evidence with no fileContent-verifiable citation when file content is available", () => {
    const fileContent = "const axios = require('axios');\nmodule.exports = { fetchOrder };";
    // "third-party http client" cites nothing that actually appears in fileContent.
    assert.equal(
      isAcceptableEvidence("uses a third-party http client for outbound calls", { ...rule, fileContent }),
      false,
    );
  });

  it("falls back to phrase/title-echo checks only when fileContent is unavailable", () => {
    // No fileContent (e.g. repo-scoped target, or unreadable file) — the file-citation
    // requirement is skipped, so genuine-sounding evidence unrelated to the rule's own
    // vocabulary still passes on the existing checks alone.
    assert.equal(isAcceptableEvidence("axios is pinned to an exact version in package.json", rule), true);
  });

  it("rejects evidence whose only file-citation is license-header boilerplate", () => {
    // A follow-up gaming attempt: "Copyright"/"Adobe" appear in nearly every file's
    // header comment, so citing only those clears the structural check without
    // saying anything about this file or this rule.
    const fileContent =
      "// Copyright 2026 Adobe. All rights reserved.\n// Licensed under the Apache License.\nconst axios = require('axios');";
    assert.equal(
      isAcceptableEvidence("coordinates Copyright and Adobe licensing in this actions helper", {
        ...rule,
        fileContent,
      }),
      false,
    );
  });

  it("still accepts a real citation even when boilerplate words are also present", () => {
    const fileContent =
      "// Copyright 2026 Adobe. All rights reserved.\nconst axios = require('axios');\nmodule.exports = { fetchOrder };";
    assert.equal(
      isAcceptableEvidence("Copyright header aside, axios is declared as a dependency and imported at the top", {
        ...rule,
        fileContent,
      }),
      true,
    );
  });
});

describe("persistRun / getRun / mergeDiskState", () => {
  it("round-trips a run through disk", async () => {
    const runId = randomUUID();
    const run = makeRun(projectRoot);
    await persistRun(runId, run);

    ledger.clear(); // force a disk read
    const loaded = await getRun(projectRoot, runId);
    assert.ok(loaded);
    assert.equal(loaded?.cwd, projectRoot);
    assert.deepEqual(Array.from(loaded?.files.get("/repo/a.js")?.expected ?? []), ["r1", "r2"]);
  });

  it("merges verdicts committed by another process without dropping either side", async () => {
    const runId = randomUUID();
    const runA = makeRun(projectRoot);
    const runB = makeRun(projectRoot);

    runA.files.get("/repo/a.js")!.verdicts.set("r1", { verdict: "pass", evidence: "checked by process A" });
    await persistRun(runId, runA);

    runB.files.get("/repo/a.js")!.verdicts.set("r2", { verdict: "pass", evidence: "checked by process B" });
    await mergeDiskState(runId, runB);

    const entry = runB.files.get("/repo/a.js")!;
    assert.equal(entry.verdicts.size, 2, "both processes' verdicts should be present after merge");
    assert.ok(entry.verdicts.has("r1"));
    assert.ok(entry.verdicts.has("r2"));
  });

  it("keeps this process's verdict when the same rule was decided on both sides (no clobber)", async () => {
    const runId = randomUUID();
    const disk = makeRun(projectRoot);
    const mem = makeRun(projectRoot);

    disk.files.get("/repo/a.js")!.verdicts.set("r1", { verdict: "violation", message: "decided on disk" });
    await persistRun(runId, disk);

    // This process already decided r1 differently — decided-once means the
    // in-memory verdict must win, not be overwritten by the disk copy.
    mem.files.get("/repo/a.js")!.verdicts.set("r1", { verdict: "pass", evidence: "decided in this process" });
    await mergeDiskState(runId, mem);

    const r1 = mem.files.get("/repo/a.js")!.verdicts.get("r1")!;
    assert.equal(r1.verdict, "pass", "in-memory verdict must be kept, not clobbered by disk");
    assert.equal(r1.evidence, "decided in this process");
  });

  it("adopts a completion another process already finalized", async () => {
    const runId = randomUUID();
    const disk = makeRun(projectRoot);
    disk.completedAt = Date.now();
    disk.finalResult = JSON.stringify({ reviewStatus: "COMPLETE", complete: true });
    await persistRun(runId, disk);

    const mem = makeRun(projectRoot); // no completedAt yet
    await mergeDiskState(runId, mem);

    assert.ok(mem.completedAt !== undefined, "should adopt disk completedAt");
    assert.equal(mem.finalResult, disk.finalResult);
  });
});

describe("withRunLock", () => {
  it("serializes concurrent operations on the same runId", async () => {
    const runId = randomUUID();
    const order: number[] = [];

    const task = (n: number) =>
      withRunLock(projectRoot, runId, async () => {
        order.push(n);
        await new Promise((r) => setTimeout(r, 20));
        order.push(-n);
      });

    await Promise.all([task(1), task(2), task(3)]);

    // Each task's start (n) must be immediately followed by its own end (-n) —
    // no interleaving, proving the lock actually serialized the three calls.
    for (let i = 0; i < order.length; i += 2) {
      const started = order[i]!;
      const ended = order[i + 1]!;
      assert.equal(ended, -started, `expected task ${started} to finish before the next one started`);
    }
  });

  it("allows independent runIds to proceed concurrently", async () => {
    const start = Date.now();
    await Promise.all([
      withRunLock(projectRoot, randomUUID(), async () => new Promise((r) => setTimeout(r, 30))),
      withRunLock(projectRoot, randomUUID(), async () => new Promise((r) => setTimeout(r, 30))),
    ]);
    const elapsed = Date.now() - start;
    assert.ok(elapsed < 55, `expected concurrent runIds to overlap, took ${elapsed}ms`);
  });
});

describe("pickNextTarget", () => {
  it("returns null when every file is fully covered", () => {
    const run = makeRun(projectRoot);
    run.files.get("/repo/a.js")!.verdicts.set("r1", { verdict: "pass" });
    run.files.get("/repo/a.js")!.verdicts.set("r2", { verdict: "pass" });
    assert.equal(pickNextTarget(run, () => undefined), null);
  });

  it("names the first incomplete file and a rule's domain", () => {
    const run = makeRun(projectRoot);
    const next = pickNextTarget(run, (id) => (id === "r1" ? { domain: "security" } as never : undefined));
    assert.equal(next?.filePath, "/repo/a.js");
    assert.equal(next?.domain, "security");
  });
});
