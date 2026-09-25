import { dirname, join, resolve } from "node:path";
import { readFileSync } from "node:fs";
import type { CheckHit, RepoChecker } from "../../types.js";

const IMPORT_RE = /(?:require\(|from\s+)['"](\.[^'"]*)['"]/g;
const RESOLVABLE_EXTS = ["", ".js", ".cjs", ".mjs", ".ts", ".tsx", ".jsx", ".json", "/index.js", "/index.ts"];

/**
 * Regex-over-raw-text (not AST — this is a repo-scope check with no per-file
 * parse pass) means an `import ...` line written inside a JSDoc `@example`
 * block comment reads as a real import specifier unless comments are
 * stripped first. Only handles block comments (where JSDoc examples live) —
 * a `//`-commented-out import is already extremely rare and, unlike a JSDoc
 * example, arguably still worth flagging if broken.
 */
function stripBlockComments(content: string): string {
  return content.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "));
}

/**
 * Repo-scope proxy for ESLint's import/no-unresolved: resolves every relative
 * import specifier found by regex against the known file set (ctx.allFiles),
 * trying common extension/index fallbacks. No module-resolution algorithm
 * (package "exports" maps, tsconfig paths) is implemented — only plain
 * relative-path + extension resolution.
 *
 * Known limits: only checks RELATIVE imports (bare package specifiers are
 * covered by the separate extraneous-dependency check); a real bundler's
 * module resolution (tsconfig `paths`, package.json `exports` conditions)
 * can resolve paths this simple check would flag as broken — so this can
 * over-flag repos using such advanced resolution features.
 */
export const unresolvedImportChecker: RepoChecker = {
  ruleId: "imports/dependencies/imported-modules-must-resolve-no-unresolved",
  kind: "repo",
  check(ctx) {
    const hits: CheckHit[] = [];
    const known = new Set(ctx.allFiles.map((f) => resolve(f)));
    const sourceFiles = ctx.allFiles.filter((f) => /\.(js|cjs|mjs|jsx|ts|tsx)$/i.test(f));
    for (const file of sourceFiles) {
      let content: string;
      try {
        content = stripBlockComments(readFileSync(file, "utf8"));
      } catch {
        continue;
      }
      const dir = dirname(file);
      let m: RegExpExecArray | null;
      IMPORT_RE.lastIndex = 0;
      while ((m = IMPORT_RE.exec(content)) !== null) {
        const spec = m[1];
        if (!spec) continue;
        const base = resolve(join(dir, spec));
        const resolved = RESOLVABLE_EXTS.some((ext) => known.has(resolve(base + ext)));
        if (!resolved) {
          const line = content.slice(0, m.index).split("\n").length;
          hits.push({
            path: file,
            line,
            message: `Import '${spec}' does not resolve to any known file (tried ${RESOLVABLE_EXTS.filter(Boolean).join(", ")})`,
          });
        }
      }
    }
    return hits;
  },
};
