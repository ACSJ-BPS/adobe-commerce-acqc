import type { Rule } from "@abqc/core";
import type { CheckHit, CheckContext, RepoContext } from "../../types.js";
import type { BuiltinRuleCheck, RuleCheck } from "../spec.js";
import { matchesFileGlob } from "../spec.js";
import {
  mainSignatureChecker,
  tryCatchMainChecker,
  jsdocExportChecker,
  importsTopChecker,
  cyclomaticChecker,
  functionLengthChecker,
  maxParamsChecker,
  maxNestingChecker,
  maxFileLengthChecker,
  inlineSecretChecker,
  mathRandomIdsChecker,
  nodePrefixChecker,
} from "../builtins/index.js";
import {
  secretsGitChecker,
  pinVersionsChecker,
  actionReadmeChecker,
} from "../repo.js";
import type { FileChecker, RepoChecker } from "../../types.js";

/** Builtin checker implementations keyed by short id from `### Check` specs. */
export const BUILTIN_IDS = [
  "main-signature",
  "try-catch-main",
  "jsdoc-export",
  "imports-top",
  "cyclomatic-complexity",
  "function-length",
  "max-params",
  "max-nesting",
  "max-file-length",
  "inline-secret",
  "math-random-ids",
  "node-prefix",
  "secrets-git",
  "pin-versions",
  "action-readme",
] as const;

export type BuiltinId = (typeof BUILTIN_IDS)[number];

const FILE_BUILTINS: Record<string, FileChecker> = {
  "main-signature": mainSignatureChecker,
  "try-catch-main": tryCatchMainChecker,
  "jsdoc-export": jsdocExportChecker,
  "imports-top": importsTopChecker,
  "cyclomatic-complexity": cyclomaticChecker,
  "function-length": functionLengthChecker,
  "max-params": maxParamsChecker,
  "max-nesting": maxNestingChecker,
  "max-file-length": maxFileLengthChecker,
  "inline-secret": inlineSecretChecker,
  "math-random-ids": mathRandomIdsChecker,
  "node-prefix": nodePrefixChecker,
};

const REPO_BUILTINS: Record<string, RepoChecker> = {
  "secrets-git": secretsGitChecker,
  "pin-versions": pinVersionsChecker,
  "action-readme": actionReadmeChecker,
};

export function isRegisteredBuiltin(id: string): boolean {
  return id in FILE_BUILTINS || id in REPO_BUILTINS;
}

export function runBuiltinCheck(
  rule: Rule,
  spec: RuleCheck,
  fileCtx: CheckContext | undefined,
  repoCtx: RepoContext | undefined,
): CheckHit[] | Promise<CheckHit[]> {
  const s = spec as BuiltinRuleCheck;
  const repoBuiltin = REPO_BUILTINS[s.id];
  if (repoBuiltin || s.scope === "repo") {
    if (!repoCtx) return [];
    const checker = REPO_BUILTINS[s.id];
    if (!checker) throw new Error(`unknown repo builtin: ${s.id}`);
    return checker.check(repoCtx);
  }

  const checker = FILE_BUILTINS[s.id];
  if (!checker || !fileCtx) return [];
  if (s.files && !matchesFileGlob(fileCtx.filePath, s.files)) return [];
  if (!checker.appliesTo(fileCtx.filePath)) return [];
  return checker.check(fileCtx);
}

export function allBuiltinIds(): string[] {
  return [...BUILTIN_IDS];
}
