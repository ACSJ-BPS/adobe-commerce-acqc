import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { RuleLoader } from "@abqc/core";
import { validateRuleChecks, automatableRuleIds } from "../registry.js";
import { classifyRules } from "../classify.js";
import { parseCheckSpec, matchesFileGlob } from "../checkers/spec.js";
import { runRegexCheck } from "../checkers/type-checkers/regex.js";
import { isRegisteredBuiltin } from "../checkers/type-checkers/builtin.js";
import { dedupeFindings, normalizeEslintFindings } from "../dedup.js";
import type { Finding } from "@abqc/core";
import { buildMechanicalSarif } from "../formatters.js";
import { defaultRulesDir } from "../rules-dir.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

describe("registry", () => {
  it("all ### Check specs parse and builtins resolve", async () => {
    const rulesDir = defaultRulesDir();
    const loader = new RuleLoader(rulesDir);
    const rules = await loader.load();
    assert.doesNotThrow(() => validateRuleChecks(rules));
    const auto = automatableRuleIds(rules);
    assert.ok(auto.length >= 22, `expected >= 22 automatable rules, got ${auto.length}`);
  });

  it("rejects unknown builtin id at validation", async () => {
    const fakeRule = {
      id: "test/fake",
      domain: "test",
      subTopic: "fake",
      title: "fake",
      severity: "error" as const,
      tags: [],
      rationale: "",
      bad: "",
      good: "",
      filePath: "",
      checkSpec: "type: builtin\nid: not-a-real-checker",
    };
    assert.throws(() => validateRuleChecks([fakeRule]));
    assert.ok(!isRegisteredBuiltin("not-a-real-checker"));
  });

  it("rejects an invalid regex pattern at validation (P2-7 regression)", () => {
    const fakeRule = {
      id: "test/bad-regex",
      domain: "test",
      subTopic: "fake",
      title: "fake",
      severity: "error" as const,
      tags: [],
      rationale: "",
      bad: "",
      good: "",
      filePath: "",
      // Unbalanced parenthesis — a genuinely invalid regex, not just a typo'd word.
      checkSpec: 'type: regex\npattern: "foo(bar"\nfiles: ["**/*.js"]',
    };
    assert.throws(() => validateRuleChecks([fakeRule]), /invalid regex pattern/);
  });
});

describe("parseCheckSpec", () => {
  it("parses regex check", () => {
    const check = parseCheckSpec(`type: regex
pattern: "foo"
files: ["**/*.js"]
strip: [strings, comments]`);
    assert.equal(check?.type, "regex");
    assert.equal((check as { pattern: string }).pattern, "foo");
  });

  it("parses yaml-path check", () => {
    const check = parseCheckSpec(`type: yaml-path\ncondition: final`);
    assert.equal(check?.type, "yaml-path");
  });
});

describe("regexChecker", () => {
  it("flags loose equality", async () => {
    const content = await readFile(join(fixtures, "bad-eqeq.js"), "utf8");
    const rule = { id: "test", title: "eqeqeq", severity: "error" as const, domain: "", subTopic: "", tags: [], rationale: "", bad: "", good: "", filePath: "" };
    const spec = parseCheckSpec(`type: regex
pattern: "(?<![=!])==(?!=)|!=(?!=)"
ignore: ["== null", "!= null"]
strip: [strings, comments]`)!;
    const hits = runRegexCheck(rule, spec, {
      filePath: join(fixtures, "bad-eqeq.js"),
      content,
      ext: ".js",
      projectRoot: fixtures,
    });
    assert.ok(hits.length > 0);
  });

  it("passes strict equality", async () => {
    const content = await readFile(join(fixtures, "good-eqeq.js"), "utf8");
    const rule = { id: "test", title: "eqeqeq", severity: "error" as const, domain: "", subTopic: "", tags: [], rationale: "", bad: "", good: "", filePath: "" };
    const spec = parseCheckSpec(`type: regex
pattern: "(?<![=!])==(?!=)|!=(?!=)"
ignore: ["== null", "!= null"]
strip: [strings, comments]`)!;
    const hits = runRegexCheck(rule, spec, {
      filePath: join(fixtures, "good-eqeq.js"),
      content,
      ext: ".js",
      projectRoot: fixtures,
    });
    assert.equal(hits.length, 0);
  });

  it("does not flag == inside strings when strip includes strings", () => {
    const rule = { id: "test", title: "eqeqeq", severity: "error" as const, domain: "", subTopic: "", tags: [], rationale: "", bad: "", good: "", filePath: "" };
    const spec = parseCheckSpec(`type: regex
pattern: "(?<![=!])==(?!=)"
strip: [strings]`)!;
    const hits = runRegexCheck(rule, spec, {
      filePath: "x.js",
      content: `const msg = "use == for demo";\nif (a === b) {}\n`,
      ext: ".js",
      projectRoot: "/tmp",
    });
    assert.equal(hits.length, 0);
  });

  it("warns to stderr instead of silently returning [] on an invalid pattern (P2-7 regression)", () => {
    const rule = { id: "test/bad-regex", title: "bad", severity: "error" as const, domain: "", subTopic: "", tags: [], rationale: "", bad: "", good: "", filePath: "" };
    const spec = parseCheckSpec('type: regex\npattern: "foo(bar"')!;
    const originalWrite = process.stderr.write.bind(process.stderr);
    const captured: string[] = [];
    process.stderr.write = ((chunk: string) => {
      captured.push(String(chunk));
      return true;
    }) as typeof process.stderr.write;
    let hits;
    try {
      hits = runRegexCheck(rule, spec, { filePath: "x.js", content: "foo(bar", ext: ".js", projectRoot: "/tmp" });
    } finally {
      process.stderr.write = originalWrite;
    }
    assert.equal(hits.length, 0, "an invalid pattern still can't produce hits");
    assert.ok(
      captured.some((line) => line.includes("invalid regex pattern") && line.includes("test/bad-regex")),
      "must warn to stderr naming the offending rule, not fail silently",
    );
  });
});

describe("dedup", () => {
  it("drops mechanical finding when ESLint covers same rule+line", () => {
    const findings: Finding[] = [
      { ruleId: "node/correctness/strict-equality-only-eqeqeq", severity: "error", message: "m", filePath: "/a.js", line: 5, source: "mechanical" },
      { ruleId: "node/correctness/strict-equality-only-eqeqeq", severity: "error", message: "e", filePath: "/a.js", line: 5, source: "eslint", libraryRuleId: "node/correctness/strict-equality-only-eqeqeq" },
    ];
    const deduped = dedupeFindings(normalizeEslintFindings(findings));
    assert.equal(deduped.length, 1);
    assert.equal(deduped[0]?.source, "eslint");
  });

  it("collapses two ESLint rules that map to the same library rule+line", () => {
    const findings: Finding[] = [
      { ruleId: "no-eval", severity: "error", message: "eval can be harmful.", filePath: "/a.js", line: 7, column: 18, source: "eslint" },
      { ruleId: "security/detect-eval-with-expression", severity: "error", message: "eval with argument of type Identifier", filePath: "/a.js", line: 7, column: 18, source: "eslint" },
    ];
    const deduped = dedupeFindings(normalizeEslintFindings(findings));
    assert.equal(deduped.length, 1, "duplicate eslint findings for the same library rule+line should collapse");
    assert.equal(deduped[0]?.ruleId, "node/bug-classes/no-eval-new-function-or-vm-runinthiscontext");
  });

  it("does not collapse distinct unmapped ESLint rules", () => {
    const findings: Finding[] = [
      { ruleId: "no-undef", severity: "error", message: "x is not defined", filePath: "/a.js", line: 7, source: "eslint" },
      { ruleId: "no-console", severity: "warn", message: "no console", filePath: "/a.js", line: 7, source: "eslint" },
    ];
    const deduped = dedupeFindings(normalizeEslintFindings(findings));
    assert.equal(deduped.length, 2);
  });
});

describe("sarif", () => {
  it("includes descriptor for every finding rule id", () => {
    const findings: Finding[] = [
      { ruleId: "node/correctness/strict-equality-only-eqeqeq", severity: "error", message: "m", filePath: "/a.js", line: 1, source: "eslint" },
      { ruleId: "eslint/unknown-rule", severity: "warn", message: "x", filePath: "/b.js", line: 2, source: "eslint" },
    ];
    const sarif = buildMechanicalSarif(findings, [], "/tmp") as { runs: Array<{ tool: { driver: { rules: Array<{ id: string }> } } }> };
    const ids = new Set(sarif.runs[0]?.tool.driver.rules.map((r) => r.id));
    assert.ok(ids.has("node/correctness/strict-equality-only-eqeqeq"));
    assert.ok(ids.has("eslint/unknown-rule"));
  });
});

describe("classify", () => {
  it("marks rules with ### Check as automatable", async () => {
    const rulesDir = defaultRulesDir();
    const loader = new RuleLoader(rulesDir);
    const rules = await loader.load();
    const entries = classifyRules(rules);
    const auto = entries.filter((e) => e.status === "automatable");
    assert.ok(auto.length >= 22);
    assert.ok(entries.some((e) => e.id === "node/correctness/strict-equality-only-eqeqeq" && e.status === "automatable"));
  });
});

describe("glob", () => {
  it("matches file patterns", () => {
    assert.ok(matchesFileGlob("/proj/actions/foo/index.js", ["**/actions/**/*.js"]));
    assert.ok(!matchesFileGlob("/proj/web-src/App.tsx", ["**/actions/**/*.js"]));
  });
});
