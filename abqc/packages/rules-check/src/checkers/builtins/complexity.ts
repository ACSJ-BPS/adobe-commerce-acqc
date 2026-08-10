import type { CheckHit, CheckContext, FileChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import {
  countFunctionLines,
  countNonBlankNonCommentLines,
  countParams,
  cyclomaticComplexity,
} from "../../utils/parse.js";
import { complexityHits, maxNestingDepth, requireParsed, isCheckHitArray } from "./shared.js";

export const cyclomaticChecker: FileChecker = {
  ruleId: "node/complexity/cyclomatic-complexity-10-per-function",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    return complexityHits(ctx, (fn) => cyclomaticComplexity(fn) > 10, "Cyclomatic complexity exceeds 10");
  },
};

export const functionLengthChecker: FileChecker = {
  ruleId: "node/complexity/function-length-50-lines",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    return complexityHits(ctx, (fn) => countFunctionLines(fn) > 50, "Function exceeds 50 lines");
  },
};

export const maxParamsChecker: FileChecker = {
  ruleId: "node/complexity/max-4-parameters-per-function-use-an-options-object",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    return complexityHits(ctx, (fn) => countParams(fn) > 4, "Function has more than 4 parameters — use an options object");
  },
};

export const maxNestingChecker: FileChecker = {
  ruleId: "node/complexity/max-nesting-depth-4",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    return complexityHits(ctx, (fn) => maxNestingDepth(fn) > 4, "Nesting depth exceeds 4");
  },
};

export const maxFileLengthChecker: FileChecker = {
  ruleId: "node/complexity/max-file-length-300-lines-excluding-comments-and-blanks",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const n = countNonBlankNonCommentLines(ctx.content);
    if (n > 300) {
      return [{ line: 1, message: `File has ${n} non-blank lines (max 300)` }];
    }
    return [];
  },
};
