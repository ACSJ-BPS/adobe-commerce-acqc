import type { CheckHit, CheckContext, FileChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import { walkAst } from "../../utils/parse.js";
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
          const line = n.loc?.start.line ?? 1;
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
    const lines = ctx.content.split("\n");
    let seenNonImport = false;
    const hits: CheckHit[] = [];
    for (let i = 0; i < lines.length; i++) {
      const t = lines[i]?.trim() ?? "";
      if (!t || t.startsWith("//") || t.startsWith("/*")) continue;
      const isImport = /^import\s/.test(t) || /^const\s+.+=\s+require\s*\(/.test(t) || /^require\s*\(/.test(t);
      if (!isImport && !t.startsWith("'use strict'") && !t.startsWith('"use strict"')) seenNonImport = true;
      if (seenNonImport && isImport) {
        hits.push({ line: i + 1, message: "Imports and requires must appear at the top of the file" });
        break;
      }
    }
    return hits;
  },
};
