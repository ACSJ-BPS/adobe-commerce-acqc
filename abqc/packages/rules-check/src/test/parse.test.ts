import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { RuleLoader } from "@abqc/core";
import { defaultRulesDir } from "../rules-dir.js";
import { parseCheckSpec } from "../checkers/spec.js";
import { runTypeCheck } from "../checkers/type-registry.js";
import { parseSource, findFunctions, cyclomaticComplexity, maxNestingDepth } from "../utils/parse.js";
import type { CheckContext } from "../types.js";

async function loadRule(ruleId: string) {
  const loader = new RuleLoader(defaultRulesDir());
  const rules = await loader.load();
  const rule = rules.find((r) => r.id === ruleId);
  assert.ok(rule, `rule not found: ${ruleId}`);
  const check = parseCheckSpec(rule.checkSpec);
  assert.ok(check, `missing ### Check for ${ruleId}`);
  return { rule, check };
}

// A deeply-branching arrow function bound the common App Builder way:
// `export const main = async () => { ... }`. Before the P0-3 fix, findFunctions
// only recognized named FunctionDeclarations + a couple of `main`-specific
// special cases, so this exact shape was invisible to every complexity/length/
// params/nesting rule — the check always reported zero functions, hence zero
// findings, on real arrow-function action code.
const HIGH_COMPLEXITY_ARROW_MAIN = `
export const main = async (params) => {
  if (params.region === 'EU') {
    if (params.total > 1000) {
      if (params.tier === 'gold') return 'eu-gold-large';
      if (params.tier === 'silver') return 'eu-silver-large';
      return 'eu-large';
    }
    if (params.total > 500) return 'eu-mid';
    return 'eu-small';
  }
  if (params.region === 'US') {
    if (params.total > 1000) return 'us-large';
    if (params.total > 500) return 'us-mid';
    return 'us-small';
  }
  if (params.region === 'APAC') {
    if (params.total > 1000) return 'apac-large';
    return 'apac-small';
  }
  return 'other';
};
`;

describe("findFunctions — arrow / function-expression coverage (P0-3 regression)", () => {
  it("recognizes `export const main = async () => {}`", () => {
    const parsed = parseSource(HIGH_COMPLEXITY_ARROW_MAIN, "/proj/actions/demo/index.js");
    assert.ok(parsed);
    const fns = findFunctions(parsed.ast);
    const main = fns.find((f) => f.name === "main");
    assert.ok(main, "arrow function bound via `export const main = ...` must be discovered");
    assert.equal(main.node.type, "ArrowFunctionExpression");
  });

  it("recognizes object-literal and class methods", () => {
    const src = `
      const handlers = {
        run: () => { if (a) { if (b) { return 1; } } },
        handle() { if (a) return 1; },
      };
      class Worker {
        process() { if (a) { if (b) { return 1; } } }
      }
    `;
    const parsed = parseSource(src, "/proj/x.js");
    assert.ok(parsed);
    const names = findFunctions(parsed.ast).map((f) => f.name);
    assert.ok(names.includes("run"));
    assert.ok(names.includes("handle"));
    assert.ok(names.includes("process"));
  });

  it("does not double-report a named function reached two ways", () => {
    const src = `
      function mainHandler() { return 1; }
      exports.main = mainHandler;
    `;
    const parsed = parseSource(src, "/proj/actions/demo/index.js");
    assert.ok(parsed);
    const fns = findFunctions(parsed.ast);
    // mainHandler's underlying node is reachable both as its own declaration
    // AND via the `exports.main = mainHandler` alias lookup — it must still
    // only be counted (and length/complexity-checked) once.
    const matches = fns.filter((f) => f.name === "mainHandler" || f.name === "main");
    const uniqueNodes = new Set(matches.map((f) => f.node));
    assert.equal(uniqueNodes.size, 1, "the same function node must not be counted twice");
  });

  it("cyclomatic-complexity rule now fires on a high-complexity arrow `main`", async () => {
    const { rule, check } = await loadRule("node/complexity/cyclomatic-complexity-10-per-function");
    const ctx: CheckContext = {
      filePath: "/proj/actions/demo/index.js",
      content: HIGH_COMPLEXITY_ARROW_MAIN,
      ext: ".js",
      projectRoot: "/proj",
      actionEntryFiles: new Set(["/proj/actions/demo/index.js"]),
    };
    const hits = await runTypeCheck({ rule, spec: check, file: ctx });
    assert.ok(hits.length > 0, "expected the arrow-function main to be flagged for high cyclomatic complexity");
  });
});

describe("cyclomaticComplexity — nested-closure isolation", () => {
  it("does not attribute a nested callback's branches to the outer function", () => {
    const src = `
      function outer() {
        return items.map(function (item) {
          if (item.a) { if (item.b) { if (item.c) { return 1; } } }
          return 0;
        });
      }
    `;
    const parsed = parseSource(src, "/proj/x.js");
    assert.ok(parsed);
    const outer = findFunctions(parsed.ast).find((f) => f.name === "outer");
    assert.ok(outer);
    // outer() itself has no branching at all — complexity must stay at the
    // baseline of 1, not absorb the 3 nested ifs from the inner callback.
    assert.equal(cyclomaticComplexity(outer.node), 1);
  });
});

describe("maxNestingDepth — try/catch is one level, not two", () => {
  it("a single try/catch is depth 1", () => {
    const src = `
      function f() {
        try {
          doThing();
        } catch (e) {
          handle(e);
        }
      }
    `;
    const parsed = parseSource(src, "/proj/x.js");
    assert.ok(parsed);
    const f = findFunctions(parsed.ast).find((fn) => fn.name === "f");
    assert.ok(f);
    assert.equal(maxNestingDepth(f.node), 1, "try/catch must count as ONE nesting level, not two");
  });

  it("an if nested inside a catch block is depth 2, matching an if inside an if", () => {
    const tryCatch = `
      function f() {
        try {
          doThing();
        } catch (e) {
          if (e.code === 'X') { handle(e); }
        }
      }
    `;
    const ifIf = `
      function g() {
        if (a) {
          if (b) { handle(); }
        }
      }
    `;
    const parsedTry = parseSource(tryCatch, "/proj/x.js");
    const parsedIf = parseSource(ifIf, "/proj/y.js");
    assert.ok(parsedTry && parsedIf);
    const f = findFunctions(parsedTry.ast).find((fn) => fn.name === "f")!;
    const g = findFunctions(parsedIf.ast).find((fn) => fn.name === "g")!;
    assert.equal(maxNestingDepth(f.node), maxNestingDepth(g.node));
  });
});
