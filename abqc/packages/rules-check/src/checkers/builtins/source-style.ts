import type { CheckHit, CheckContext, FileChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import { walkAst, findFunctionByName, locStart, type AstNode } from "../../utils/parse.js";
import {
  requireParsed,
  isCheckHitArray,
  extractJsdocBlock,
  jsdocIsValid,
  member,
  identName,
} from "./shared.js";

const BUILTIN_MODULES = new Set([
  "assert", "buffer", "child_process", "cluster", "crypto", "dgram", "dns",
  "events", "fs", "http", "http2", "https", "module", "net", "os", "path",
  "perf_hooks", "process", "punycode", "querystring", "readline", "stream",
  "string_decoder", "timers", "tls", "tty", "url", "util", "v8", "vm", "zlib",
  "worker_threads",
]);

export const nodePrefixChecker: FileChecker = {
  ruleId: "node/modern-apis/use-the-node-prefix-for-all-built-in-modules",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    const re = /require\s*\(\s*['"]([^'"]+)['"]\s*\)/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(ctx.content)) !== null) {
      const mod = m[1];
      if (mod && BUILTIN_MODULES.has(mod) && !mod.startsWith("node:")) {
        const before = ctx.content.slice(0, m.index);
        const line = before.split("\n").length;
        hits.push({
          line,
          message: `Use require('node:${mod}') instead of require('${mod}')`,
        });
      }
    }
    return hits;
  },
};

export const jsdocExportChecker: FileChecker = {
  ruleId: "documentation/code/jsdoc-on-every-exported-function-params-returns-throws",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "ExportNamedDeclaration") {
        const decl = member(n, "declaration");
        if (decl?.type === "FunctionDeclaration") {
          const id = member(decl, "id");
          const name = identName(id);
          const line = decl.loc?.start.line ?? 1;
          const block = extractJsdocBlock(parsed.lines, line);
          if (!jsdocIsValid(block)) {
            hits.push({
              line,
              message: `Exported function '${name ?? "anonymous"}' is missing JSDoc with @param and @returns`,
            });
          }
        }
      }
      if (n.type === "AssignmentExpression") {
        const left = member(n, "left");
        const obj = left ? member(left, "object") : undefined;
        const prop = left ? member(left, "property") : undefined;
        if (identName(obj) === "exports" && identName(prop) === "main") {
          // `exports.main = main` re-exports a function declared earlier in the
          // file — the JSDoc lives above THAT declaration, not above this
          // assignment line. Resolve the reference so a documented `function
          // main() {}` isn't flagged just because the re-export sits far below it.
          const right = member(n, "right");
          const target = right?.type === "Identifier" ? findFunctionByName(parsed.ast, identName(right) ?? "") : undefined;
          const line = (target ?? right ?? n).loc?.start.line ?? 1;
          const block = extractJsdocBlock(parsed.lines, line);
          if (!jsdocIsValid(block)) {
            hits.push({ line, message: "Exported main action is missing JSDoc with @param and @returns" });
          }
        }
      }
    });
    return hits;
  },
};

export const importsTopChecker: FileChecker = {
  ruleId: "imports/ordering/imports-go-at-the-top-of-the-file-first",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    // AST-based rather than line-based: comments (including multi-line license/
    // JSDoc header blocks) aren't part of Program.body at all, so they can't be
    // mistaken for "code before the imports"; and only top-level statements are
    // walked, so a lazy/conditional `require()` inside a function body is never
    // considered a misplaced import in the first place.
    const body = (parsed.ast as AstNode & { body?: AstNode[] }).body ?? [];
    let seenNonImport = false;
    const hits: CheckHit[] = [];
    for (const stmt of body) {
      if (isDirectivePrologue(stmt)) continue;
      if (isTopLevelImport(stmt)) {
        if (seenNonImport) {
          hits.push({ ...locStart(stmt), message: "Imports and requires must appear at the top of the file" });
          break;
        }
        continue;
      }
      seenNonImport = true;
    }
    return hits;
  },
};

function isDirectivePrologue(n: AstNode): boolean {
  return n.type === "ExpressionStatement" && member(n, "expression")?.type === "Literal";
}

function isRequireCall(n: AstNode | undefined): boolean {
  return !!n && n.type === "CallExpression" && identName(member(n, "callee")) === "require";
}

/** `require('dotenv').config()` — a call chained directly off a require() result, not a bare require call itself, but still import-adjacent setup. */
function isRequireChainCall(n: AstNode | undefined): boolean {
  if (!n || n.type !== "CallExpression") return false;
  const callee = member(n, "callee");
  return !!callee && callee.type === "MemberExpression" && isRequireCall(member(callee, "object"));
}

/** `jest.mock(...)` / `vi.mock(...)` / `vi.hoisted(...)` — the standard "mock before require/import" test setup idiom. */
function isMockSetupCall(n: AstNode | undefined): boolean {
  if (!n || n.type !== "CallExpression") return false;
  const callee = member(n, "callee");
  if (!callee || callee.type !== "MemberExpression") return false;
  const objName = identName(member(callee, "object"));
  const propName = identName(member(callee, "property"));
  return (objName === "jest" || objName === "vi") && (propName === "mock" || propName === "hoisted");
}

function isTopLevelImport(n: AstNode): boolean {
  if (n.type === "ImportDeclaration") return true;
  if (n.type === "ExpressionStatement") {
    const expr = member(n, "expression");
    return isRequireCall(expr) || isMockSetupCall(expr) || isRequireChainCall(expr);
  }
  if (n.type === "VariableDeclaration") {
    const decls = (n as AstNode & { declarations?: AstNode[] }).declarations ?? [];
    return decls.some((d) => isRequireCall(member(d, "init")) || isMockSetupCall(member(d, "init")));
  }
  return false;
}
