import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { buildSarif } from "../sarif.js";
import type { Finding, Rule } from "../types.js";

function makeRule(id: string): Rule {
  return {
    id,
    domain: "node",
    subTopic: "errors",
    title: "test rule",
    severity: "warn",
    tags: [],
    rationale: "",
    bad: "",
    good: "",
    filePath: "rules/node/errors.md",
  };
}

describe("buildSarif — helpUri only for genuine ESLint findings (P2-3 regression)", () => {
  it("gives a real ESLint rule id an eslint.org helpUri", () => {
    const findings: Finding[] = [
      { ruleId: "no-eval", severity: "warn", message: "m", filePath: "/a.js", source: "eslint" },
    ];
    const sarif = buildSarif(findings, [], "/");
    const descriptor = sarif.runs[0]!.tool.driver.rules.find((r) => r.id === "no-eval");
    assert.ok(descriptor?.helpUri?.startsWith("https://eslint.org/"));
  });

  it("does NOT fabricate an eslint.org helpUri for a non-ESLint synthetic ruleId", () => {
    const findings: Finding[] = [
      {
        ruleId: "judgment/layer-unavailable",
        severity: "error",
        message: "the judgment layer failed",
        filePath: "/a.js",
        source: "judgment",
      },
    ];
    const sarif = buildSarif(findings, [], "/");
    const descriptor = sarif.runs[0]!.tool.driver.rules.find((r) => r.id === "judgment/layer-unavailable");
    assert.ok(descriptor, "a descriptor must still be synthesized for the unknown id");
    assert.equal(descriptor?.helpUri, undefined, "must not fabricate a (likely-404) eslint.org URL for a non-ESLint id");
  });

  it("uses the real library descriptor (with rationale) when the ruleId matches a loaded rule", () => {
    const findings: Finding[] = [
      { ruleId: "node/errors/example", severity: "warn", message: "m", filePath: "/a.js", source: "mechanical" },
    ];
    const sarif = buildSarif(findings, [makeRule("node/errors/example")], "/");
    const descriptor = sarif.runs[0]!.tool.driver.rules.find((r) => r.id === "node/errors/example");
    assert.equal(descriptor?.name, "test rule");
  });
});
