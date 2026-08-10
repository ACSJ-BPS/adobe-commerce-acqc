import type { CheckHit, CheckContext, FileChecker } from "../../types.js";
import { isActionFile } from "@abqc/core";
import { hasExportsMain, hasMainFunction, functionBodyHasTryCatch, findFunctions, locStart } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, skipNonEntryAction } from "./shared.js";

export const mainSignatureChecker: FileChecker = {
  ruleId: "appbuilder/runtime/action-handler-signature-main-params-exports-main-main",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    if (skipNonEntryAction(ctx)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    if (!hasMainFunction(ctx.content, parsed.ast)) {
      hits.push({
        line: 1,
        message: "Action must define main(params) and exports.main = main",
      });
    } else if (!hasExportsMain(ctx.content, parsed.ast)) {
      hits.push({
        line: 1,
        message: "Action must assign exports.main = main (or module.exports.main)",
      });
    }
    return hits;
  },
};

export const tryCatchMainChecker: FileChecker = {
  ruleId: "appbuilder/runtime/wrap-entire-main-body-in-try-catch-unhandled-async-kills-the-con",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    if (skipNonEntryAction(ctx)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const mainFns = findFunctions(parsed.ast).filter((f) => f.name === "main");
    const hits: CheckHit[] = [];
    for (const { node } of mainFns) {
      if (!functionBodyHasTryCatch(node)) {
        const loc = locStart(node);
        hits.push({
          ...loc,
          message: "main() body should be wrapped in try/catch — unhandled async errors kill the container",
        });
      }
    }
    return hits;
  },
};
