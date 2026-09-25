import { readFile } from "node:fs/promises";
import { parse as parseYaml } from "yaml";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isActionFile, isYamlConfig } from "@abqc/core";
import { findFunctions, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray } from "./shared.js";

function rangeOf(node: AstNode | undefined): [number, number] | undefined {
  return node ? (node as unknown as { range?: [number, number] }).range : undefined;
}

function sourceTextOf(content: string, node: AstNode | undefined): string {
  const range = rangeOf(node);
  if (!range) return "";
  return content.slice(range[0], range[1]);
}

function offsetToLine(content: string, offset: number): number {
  let line = 1;
  const cap = Math.min(offset, content.length);
  for (let i = 0; i < cap; i++) {
    if (content[i] === "\n") line++;
  }
  return line;
}

/** Known-valid Adobe extension points. Needs manual maintenance as Adobe adds new ones. */
const KNOWN_EXTENSION_POINTS = new Set([
  "dx/excshell/1",
  "dx/asset-compute/worker/1",
  "aem/cf-console/admin/1",
  "commerce/backend-ui/1",
  "commerce/backend-ui/2",
  "commerce/extensibility/1",
]);

/**
 * STATIC (heuristic). Known limits: the enum needs manual maintenance as Adobe adds
 * new valid extension points — under-flags new valid points as if invented.
 * Also the target for the react/setup duplicate rules describing the same
 * extension-point allowlist.
 */
export const extensionPointEnumChecker: RepoChecker = {
  ruleId: "appbuilder/integration/aem-cloud-service-6-5-and-commerce-have-native-extension-points-",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const configs = ctx.allFiles.filter((f) => /(^|[\\/])app\.config\.ya?ml$/.test(f.replace(/\\/g, "/")));
    for (const file of configs) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      let doc: unknown;
      try {
        doc = parseYaml(content);
      } catch {
        continue;
      }
      const extensions = (doc as Record<string, unknown> | null)?.["extensions"];
      if (!extensions || typeof extensions !== "object") continue;
      for (const key of Object.keys(extensions as Record<string, unknown>)) {
        if (!KNOWN_EXTENSION_POINTS.has(key)) {
          hits.push({
            line: 1,
            path: file,
            message: `Unknown extension point '${key}' — not in the known-valid Adobe extension point list; verify it's a real, supported extension point before shipping.`,
          });
        }
      }
    }
    return hits;
  },
};

/** MECHANICAL. Known limits: none significant. */
export const nonNodeRuntimeChecker: FileChecker = {
  ruleId: "appbuilder/integration/default-runtime-is-node-js-other-languages-only-via-docker",
  kind: "file",
  appliesTo: isYamlConfig,
  check(ctx) {
    const hits: CheckHit[] = [];
    let doc: unknown;
    try {
      doc = parseYaml(ctx.content);
    } catch {
      return [];
    }
    function walk(node: unknown, insideActions: boolean): void {
      if (!node || typeof node !== "object") return;
      const obj = node as Record<string, unknown>;
      for (const [k, v] of Object.entries(obj)) {
        if (k === "runtime" && typeof v === "string" && insideActions && !v.startsWith("nodejs:")) {
          hits.push({
            line: 1,
            message: `Action runtime '${v}' is not a Node.js runtime — default runtime is Node.js, other languages only via Docker containers.`,
          });
        }
        if (v && typeof v === "object") walk(v, insideActions || k === "actions");
      }
    }
    walk(doc, false);
    return hits;
  },
};

/**
 * STATIC (heuristic). Known limits: per-user token cached under a non-obvious
 * variable name evades the keying heuristic.
 */
export const commerceGraphqlAppCredsChecker: FileChecker = {
  ruleId: "appbuilder/integration/call-commerce-graphql-with-app-level-credentials-from-secrets-ne",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const fns = findFunctions(parsed.ast);
    const scopes: AstNode[] = fns.length > 0 ? fns.map((f) => f.node) : [parsed.ast];
    for (const scope of scopes) {
      const src = sourceTextOf(ctx.content, scope) || ctx.content;
      const hasGraphqlCall = /\b(fetch|axios(?:\.\w+)?)\s*\([^)]*graphql/is.test(src) || (/graphql/i.test(src) && /\b(fetch|axios(?:\.\w+)?)\s*\(/.test(src));
      const hasUserKeyedStateGet = /\b[Ss]tate\.get\s*\([^)]*user/i.test(src);
      const hasAuthHeader = /authorization/i.test(src);
      if (hasGraphqlCall && hasUserKeyedStateGet && hasAuthHeader) {
        hits.push({
          ...(locStart(scope) ?? { line: 1 }),
          message:
            "Commerce GraphQL call appears to use a per-user cached credential (state.get keyed by user) as the Authorization header — call Commerce GraphQL with app-level credentials from Secrets, never a per-user token.",
        });
      }
    }
    return hits;
  },
};

function extractCallArgsText(content: string, openParenIndex: number): string {
  let depth = 0;
  let i = openParenIndex;
  const start = i;
  for (; i < content.length; i++) {
    if (content[i] === "(") depth++;
    else if (content[i] === ")") {
      depth--;
      if (depth === 0) {
        i++;
        break;
      }
    }
  }
  return content.slice(start, i);
}

/**
 * MECHANICAL. Reused (same builtin) for the Admin UI SDK duplicate rule —
 * appliesTo is broad enough (anything not under actions/) to cover both.
 */
export const noCommerceAdminFromSpaChecker: FileChecker = {
  ruleId: "appbuilder/integration/never-call-commerce-admin-graphql-with-admin-credentials-from-th",
  kind: "file",
  appliesTo: (filePath) => {
    const p = filePath.replace(/\\/g, "/");
    if (/(^|\/)actions\//.test(p)) return false;
    return /\.(js|jsx|ts|tsx|mjs|cjs)$/.test(p);
  },
  check(ctx) {
    const hits: CheckHit[] = [];
    const re = /\b(fetch|axios(?:\.\w+)?)\s*\(/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(ctx.content)) !== null) {
      const openParen = m.index + m[0].length - 1;
      const argsText = extractCallArgsText(ctx.content, openParen);
      if (/graphql|rest\/V1|\/admin/i.test(argsText)) {
        hits.push({
          line: offsetToLine(ctx.content, m.index),
          message:
            "SPA source calls Commerce GraphQL/REST/admin endpoints directly from the browser — never call Commerce admin GraphQL with admin credentials from the SPA; route through a backend runtime action.",
        });
      }
    }
    return hits;
  },
};

/** MECHANICAL. */
export const noStoreContextFromHeadersChecker: FileChecker = {
  ruleId: "appbuilder/integration/pass-store-view-website-context-explicitly-never-inferred-from-h",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    const headerRe = /params\s*\??\.\s*__ow_headers\s*\??\.\s*(host|referer|origin)/i;
    const storeRe = /store|region|website/i;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (!headerRe.test(line)) continue;
      const windowStart = Math.max(0, i - 2);
      const windowEnd = Math.min(lines.length - 1, i + 2);
      const windowText = lines.slice(windowStart, windowEnd + 1).join("\n");
      if (storeRe.test(windowText)) {
        hits.push({
          line: i + 1,
          message:
            "Store/region/website context appears to be derived from request headers (host/referer/origin) — pass store view/website context explicitly, never inferred from headers.",
        });
      }
    }
    return hits;
  },
};

/**
 * STATIC (heuristic). Known limits: bounding done via a shared validation helper
 * called elsewhere in the file evades a strict same-function requirement.
 */
export const boundGraphqlPagesizeChecker: FileChecker = {
  ruleId: "appbuilder/integration/bound-page-sizes-when-calling-commerce-graphql-no-unbounded-page",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const unboundedRe = /(pageSize|limit)\s*:\s*(params|args)\.(pageSize|limit)/g;
    const fns = findFunctions(parsed.ast);
    let m: RegExpExecArray | null;
    while ((m = unboundedRe.exec(ctx.content)) !== null) {
      const idx = m.index;
      const enclosing = fns.find((f) => {
        const r = rangeOf(f.node);
        return r && idx >= r[0] && idx <= r[1];
      });
      const scopeText = enclosing ? sourceTextOf(ctx.content, enclosing.node) : ctx.content;
      if (!/Math\.min\s*\(/.test(scopeText)) {
        hits.push({
          line: offsetToLine(ctx.content, idx),
          message:
            "GraphQL query variable pageSize/limit is passed straight through from params/args with no Math.min( bound — bound page sizes when calling Commerce GraphQL, never pass an unbounded page size.",
        });
      }
    }
    return hits;
  },
};
