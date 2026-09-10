import { join, dirname } from "node:path";
import { readFile } from "node:fs/promises";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import { walkAst, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

// ---------------------------------------------------------------------------
// Shared helpers (imports domain)
// ---------------------------------------------------------------------------

async function readSafe(p: string): Promise<string | null> {
  try {
    return await readFile(p, "utf8");
  } catch {
    return null;
  }
}

const JS_EXT = /\.(js|jsx|ts|tsx|mjs|cjs)$/i;

const NODE_BUILTINS = new Set([
  "assert", "buffer", "child_process", "cluster", "crypto", "dgram", "dns", "events", "fs",
  "http", "http2", "https", "module", "net", "os", "path", "perf_hooks", "process", "punycode",
  "querystring", "readline", "stream", "string_decoder", "timers", "tls", "tty", "url", "util",
  "v8", "vm", "zlib", "worker_threads", "inspector", "repl", "test", "domain", "diagnostics_channel",
]);

function isBuiltinPackage(name: string): boolean {
  const stripped = name.startsWith("node:") ? name.slice("node:".length) : name;
  // Handle subpaths like `fs/promises`, `node:fs/promises`.
  const base = stripped.split("/")[0] ?? stripped;
  return NODE_BUILTINS.has(stripped) || NODE_BUILTINS.has(base);
}

function extractPackageName(spec: string): string {
  if (spec.startsWith("@")) {
    return spec.split("/").slice(0, 2).join("/");
  }
  return spec.split("/")[0] ?? spec;
}

// ---------------------------------------------------------------------------
// 14. extraneous-dependency
// ---------------------------------------------------------------------------

async function readDeclaredDeps(pkgJsonPath: string): Promise<Set<string> | null> {
  try {
    const raw = await readFile(pkgJsonPath, "utf8");
    const pkg = JSON.parse(raw) as {
      dependencies?: Record<string, string>;
      devDependencies?: Record<string, string>;
      peerDependencies?: Record<string, string>;
      optionalDependencies?: Record<string, string>;
    };
    // Workspace-protocol (`workspace:*`) and catalog-protocol (`catalog:`)
    // version specifiers are still ordinary keys under `dependencies` — they
    // were never the problem. The real gap is reading the WRONG package.json:
    // a monorepo package's own dependency isn't necessarily in the repo
    // root's package.json at all.
    return new Set([
      ...Object.keys(pkg.dependencies ?? {}),
      ...Object.keys(pkg.devDependencies ?? {}),
      ...Object.keys(pkg.peerDependencies ?? {}),
      ...Object.keys(pkg.optionalDependencies ?? {}),
    ]);
  } catch {
    return null;
  }
}

export const extraneousDependencyChecker: RepoChecker = {
  ruleId: "imports/dependencies/imported-modules-must-be-in-package-json-no-extraneous-deps",
  kind: "repo",
  async check(ctx) {
    // A monorepo has one package.json per workspace package, not one at the
    // repo root — resolve each SOURCE file against its OWN nearest-ancestor
    // package.json, not unconditionally the root's. Cache per directory so a
    // package.json shared by many files is only read/parsed once.
    const packageJsonDirs = ctx.allFiles
      .filter((f) => /(^|[\\/])package\.json$/.test(f))
      .map((f) => dirname(f));
    const declaredCache = new Map<string, Set<string> | null>();
    async function declaredDepsFor(fileDir: string): Promise<Set<string>> {
      let dir = fileDir;
      for (let i = 0; i < 50; i++) {
        if (packageJsonDirs.includes(dir)) {
          if (!declaredCache.has(dir)) {
            declaredCache.set(dir, await readDeclaredDeps(join(dir, "package.json")));
          }
          const deps = declaredCache.get(dir);
          if (deps) return deps;
        }
        const parent = dirname(dir);
        if (parent === dir) break;
        dir = parent;
      }
      // Fall back to the project root's package.json (single-package repos —
      // the common case — never had a bug here; only monorepos did).
      if (!declaredCache.has(ctx.projectRoot)) {
        declaredCache.set(ctx.projectRoot, await readDeclaredDeps(join(ctx.projectRoot, "package.json")));
      }
      return declaredCache.get(ctx.projectRoot) ?? new Set();
    }

    const hits: CheckHit[] = [];
    for (const file of ctx.allFiles) {
      if (!JS_EXT.test(file)) continue;
      const content = await readSafe(file);
      if (!content) continue;
      const declared = await declaredDepsFor(dirname(file));
      const flaggedInFile = new Set<string>();
      for (const re of [/\brequire\(\s*['"]([^'"]+)['"]\s*\)/g, /\bfrom\s+['"]([^'"]+)['"]/g]) {
        let m: RegExpExecArray | null;
        while ((m = re.exec(content)) !== null) {
          const spec = m[1] ?? "";
          // A leading `#` is a Node.js package.json `imports` subpath alias
          // (`#test`, `#config`, ...) — an internal resolution mechanism, not
          // an npm package; it was never a candidate for "extraneous
          // dependency" in the first place.
          if (!spec || spec.startsWith(".") || spec.startsWith("/") || spec.startsWith("#")) continue;
          const pkgName = extractPackageName(spec);
          if (isBuiltinPackage(pkgName) || declared.has(pkgName) || flaggedInFile.has(pkgName)) continue;
          flaggedInFile.add(pkgName);
          const line = content.slice(0, m.index).split("\n").length;
          hits.push({
            line,
            path: file,
            message: `Import of '${pkgName}' is not declared in package.json dependencies/devDependencies — extraneous dependency (works locally via hoisting, fails on a clean install).`,
          });
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 15. circular-import
// ---------------------------------------------------------------------------

function resolveRelativeImport(fromFile: string, spec: string, fileSet: Set<string>): string | null {
  const baseRaw = join(dirname(fromFile), spec);
  const base = baseRaw.replace(/\\/g, "/");
  const candidates = [
    base,
    `${base}.js`, `${base}.ts`, `${base}.jsx`, `${base}.tsx`, `${base}.mjs`, `${base}.cjs`,
    `${base}/index.js`, `${base}/index.ts`, `${base}/index.jsx`, `${base}/index.tsx`,
  ];
  for (const c of candidates) {
    if (fileSet.has(c)) return c;
  }
  return null;
}

function detectCycles(graph: Map<string, Set<string>>): Set<string> {
  const flagged = new Set<string>();
  const visiting = new Set<string>();
  const visited = new Set<string>();
  const stack: string[] = [];

  function dfs(node: string): void {
    if (visited.has(node)) return;
    visiting.add(node);
    stack.push(node);
    for (const next of graph.get(node) ?? []) {
      if (visiting.has(next)) {
        const idx = stack.indexOf(next);
        const cycleNodes = idx >= 0 ? stack.slice(idx) : [next, node];
        for (const n of cycleNodes) flagged.add(n);
      } else if (!visited.has(next)) {
        dfs(next);
      }
    }
    stack.pop();
    visiting.delete(node);
    visited.add(node);
  }

  for (const node of graph.keys()) dfs(node);
  return flagged;
}

/**
 * STATIC (heuristic)-adjacent MECHANICAL check with a real limitation: import
 * resolution is regex + filename-probing (no bundler resolution), so a
 * cycle that only exists via a re-exported barrel file's OTHER exports (not
 * a direct relative specifier) can be missed.
 */
export const circularImportChecker: RepoChecker = {
  ruleId: "imports/discipline/no-circular-imports-break-the-cycle",
  kind: "repo",
  async check(ctx) {
    const jsFiles = ctx.allFiles.filter((f) => JS_EXT.test(f)).map((f) => f.replace(/\\/g, "/"));
    const fileSet = new Set(jsFiles);
    const graph = new Map<string, Set<string>>();

    for (const file of jsFiles) {
      const content = await readSafe(file);
      if (!content) continue;
      const edges = new Set<string>();
      for (const re of [/\bfrom\s+['"](\.[^'"]*)['"]/g, /\brequire\(\s*['"](\.[^'"]*)['"]\s*\)/g]) {
        let m: RegExpExecArray | null;
        while ((m = re.exec(content)) !== null) {
          const spec = m[1];
          if (!spec) continue;
          const resolved = resolveRelativeImport(file, spec, fileSet);
          if (resolved) edges.add(resolved);
        }
      }
      graph.set(file, edges);
    }

    const flagged = detectCycles(graph);
    const hits: CheckHit[] = [];
    for (const f of flagged) {
      hits.push({
        line: 1,
        path: f,
        message: "File is part of a circular import chain — extract the shared part into a third module both sides depend on.",
      });
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 16. anonymous-default-export
// ---------------------------------------------------------------------------

export const anonymousDefaultExportChecker: FileChecker = {
  ruleId: "imports/exports/default-exports-must-be-named-at-the-export-site-no-anonymous-de",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ExportDefaultDeclaration") return;
      const decl = member(n, "declaration");
      if (!decl) return;
      let anonymous = false;
      if (decl.type === "ObjectExpression" || decl.type === "ArrayExpression" || decl.type === "ArrowFunctionExpression") {
        anonymous = true;
      } else if (decl.type === "FunctionExpression" || decl.type === "FunctionDeclaration") {
        anonymous = !member(decl, "id");
      }
      if (anonymous) {
        hits.push({
          ...locStart(n),
          message: "Anonymous default export — assign to a named const/function first so stack traces and DevTools show a real symbol name.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 17. namespace-import
// ---------------------------------------------------------------------------

export const namespaceImportChecker: FileChecker = {
  ruleId: "imports/exports/avoid-namespace-imports-import-as",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "ImportNamespaceSpecifier") {
        hits.push({
          ...locStart(n),
          message: "Namespace import (import * as x) pulls in every export and defeats tree-shaking — prefer named imports.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 18. no-blank-line-after-imports
// ---------------------------------------------------------------------------

function isImportLikeLine(t: string): boolean {
  return (
    /^import\s/.test(t) ||
    /^export\s*\*/.test(t) ||
    /^export\s*\{/.test(t) ||
    /^(const|let|var)\s+.*=\s*require\(/.test(t) ||
    /^require\(/.test(t)
  );
}

function isCommentLine(t: string): boolean {
  return t.startsWith("//") || t.startsWith("/*") || t.startsWith("*");
}

export const noBlankLineAfterImportsChecker: FileChecker = {
  ruleId: "imports/ordering/blank-line-after-the-import-block-newline-after-import",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const lines = ctx.content.split("\n");
    let lastImportLine = -1;
    let inBlockComment = false;
    for (let i = 0; i < lines.length; i++) {
      const t = (lines[i] ?? "").trim();
      if (inBlockComment) {
        if (t.includes("*/")) inBlockComment = false;
        continue;
      }
      if (t === "") continue;
      if (t.startsWith("/*") && !t.includes("*/")) {
        inBlockComment = true;
        continue;
      }
      if (isCommentLine(t)) continue;
      if (isImportLikeLine(t)) {
        lastImportLine = i;
        continue;
      }
      break;
    }
    if (lastImportLine === -1) return [];

    // Skip past any comment-only lines directly below the import block —
    // only a genuinely blank line counts as the required separator.
    let j = lastImportLine + 1;
    while (j < lines.length && isCommentLine((lines[j] ?? "").trim())) j++;
    if (j >= lines.length) return [];
    const nextLine = lines[j] ?? "";
    if (nextLine.trim() !== "") {
      return [
        {
          line: j + 1,
          message: "No blank line after the import block — add a newline to visually separate imports from the rest of the file.",
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 19. import-group-order
// ---------------------------------------------------------------------------

function importGroupRank(source: string): number {
  if (source.startsWith("node:") || isBuiltinPackage(extractPackageName(source))) return 0;
  if (source.startsWith("../")) return 2;
  if (source.startsWith("./")) return 3;
  return 1; // external
}

export const importGroupOrderChecker: FileChecker = {
  ruleId: "imports/ordering/group-imports-builtin-external-internal-parent-sibling",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const body = (parsed.ast as AstNode & { body?: AstNode[] }).body ?? [];
    const imports = body.filter((n) => n.type === "ImportDeclaration");
    const hits: CheckHit[] = [];
    let maxRankSeen = -1;
    for (const imp of imports) {
      const sourceNode = member(imp, "source");
      const value = sourceNode ? (sourceNode as AstNode & { value?: unknown }).value : undefined;
      if (typeof value !== "string") continue;
      const rank = importGroupRank(value);
      if (rank < maxRankSeen) {
        hits.push({
          ...locStart(imp),
          message: `Import '${value}' is out of group order — group imports builtin → external → internal → parent → sibling.`,
        });
      } else {
        maxRankSeen = rank;
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 20. deep-relative-import
// ---------------------------------------------------------------------------

export const deepRelativeImportChecker: FileChecker = {
  ruleId: "imports/aliases/prefer-absolute-aliased-imports-over-deep-relative-paths",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    for (const re of [/\bfrom\s+['"]([^'"]+)['"]/g, /\brequire\(\s*['"]([^'"]+)['"]\s*\)/g]) {
      let m: RegExpExecArray | null;
      while ((m = re.exec(ctx.content)) !== null) {
        const spec = m[1] ?? "";
        const depth = (spec.match(/\.\.\//g) ?? []).length;
        if (depth >= 3) {
          const line = ctx.content.slice(0, m.index).split("\n").length;
          hits.push({
            line,
            message: `Deep relative import '${spec}' (${depth} levels up) — prefer an absolute/aliased import so it doesn't break on file moves.`,
          });
        }
      }
    }
    return hits;
  },
};
