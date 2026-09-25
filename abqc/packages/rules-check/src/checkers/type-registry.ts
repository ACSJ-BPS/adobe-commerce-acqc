import type { Rule } from "@abqc/core";
import type { CheckHit, CheckContext, RepoContext } from "../types.js";
import type { RuleCheck } from "./spec.js";
import { runRegexCheck } from "./type-checkers/regex.js";
import { runYamlPathCheck } from "./type-checkers/yaml-path.js";
import { runBuiltinCheck } from "./type-checkers/builtin.js";

export interface TypeCheckerContext {
  file?: CheckContext;
  repo?: RepoContext;
  rule: Rule;
  spec: RuleCheck;
}

export interface TypeChecker {
  type: RuleCheck["type"];
  run(ctx: TypeCheckerContext): CheckHit[] | Promise<CheckHit[]>;
}

const TYPE_CHECKERS: TypeChecker[] = [
  { type: "regex", run: (ctx) => runRegexCheck(ctx.rule, ctx.spec, ctx.file!) },
  { type: "yaml-path", run: (ctx) => runYamlPathCheck(ctx.rule, ctx.spec, ctx.file!) },
  { type: "builtin", run: (ctx) => runBuiltinCheck(ctx.rule, ctx.spec, ctx.file, ctx.repo) },
];

const byType = new Map(TYPE_CHECKERS.map((c) => [c.type, c]));

export function getTypeChecker(type: RuleCheck["type"]): TypeChecker | undefined {
  return byType.get(type);
}

export function knownCheckTypes(): RuleCheck["type"][] {
  return [...byType.keys()];
}

export async function runTypeCheck(ctx: TypeCheckerContext): Promise<CheckHit[]> {
  const checker = getTypeChecker(ctx.spec.type);
  if (!checker) {
    throw new Error(`unknown check type: ${ctx.spec.type}`);
  }
  return checker.run(ctx);
}
