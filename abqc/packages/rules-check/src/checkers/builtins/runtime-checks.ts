import { readFile } from "node:fs/promises";
import { isSourceFile } from "@abqc/core";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { requireParsed, isCheckHitArray, isJsxCapableFile } from "./shared.js";
import { walkAst, locStart, type AstNode } from "../../utils/parse.js";

function identName(n: AstNode | undefined): string | undefined {
  if (!n || n.type !== "Identifier") return undefined;
  return (n as AstNode & { name?: string }).name;
}

function member(n: AstNode, key: string): AstNode | undefined {
  const v = (n as Record<string, unknown>)[key];
  return v && typeof v === "object" && "type" in (v as object) ? (v as AstNode) : undefined;
}

function callArgs(n: AstNode): AstNode[] {
  return (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
}

function literalString(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Literal") {
    const v = (n as AstNode & { value?: unknown }).value;
    return typeof v === "string" ? v : undefined;
  }
  if (n.type === "TemplateLiteral") {
    const quasis = (n as AstNode & { quasis?: AstNode[] }).quasis ?? [];
    return quasis.map((q) => (q as AstNode & { value?: { raw?: string } }).value?.raw ?? "").join("");
  }
  return undefined;
}

function escapeRegex(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

// ---------------------------------------------------------------------------
// 18. runtime/loading/resource-hints-link-rel-preconnect... — missing-preconnect
// ---------------------------------------------------------------------------

export const missingPreconnectChecker: RepoChecker = {
  ruleId: "runtime/loading/resource-hints-link-rel-preconnect-for-known-third-party-origins",
  kind: "repo",
  async check(ctx) {
    const htmlPath = ctx.allFiles.find((f) => /(^|\/)index\.html$/i.test(f.replace(/\\/g, "/")));
    if (!htmlPath) return [];
    let html: string;
    try {
      html = await readFile(htmlPath, "utf8");
    } catch {
      return [];
    }

    const hostnames = new Set<string>();
    const jsFiles = ctx.allFiles.filter((f) => /\.(js|ts|jsx|tsx|mjs|cjs)$/i.test(f));
    for (const file of jsFiles) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      for (const line of content.split("\n")) {
        if (!/\bfetch\s*\(|\baxios[.(]/.test(line)) continue;
        const urlRe = /https?:\/\/([a-zA-Z0-9.-]+)/g;
        let m: RegExpExecArray | null;
        while ((m = urlRe.exec(line)) !== null) {
          if (m[1]) hostnames.add(m[1]);
        }
      }
    }

    const hits: CheckHit[] = [];
    for (const host of hostnames) {
      const preconnectRe = new RegExp(
        `<link[^>]+rel=["']preconnect["'][^>]+href=["']https?:\\/\\/${escapeRegex(host)}`,
        "i",
      );
      if (!preconnectRe.test(html)) {
        hits.push({
          path: htmlPath,
          line: 1,
          message: `No <link rel="preconnect" href="https://${host}"> found for third-party origin used in fetch/axios calls`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 19. runtime/performance/no-foit-fonts... — font-display-missing
// ---------------------------------------------------------------------------

export const fontDisplayMissingChecker: FileChecker = {
  ruleId: "runtime/performance/no-foit-fonts-must-render-with-font-display-swap-or-optional",
  kind: "file",
  appliesTo: (p) => /\.css$/i.test(p),
  check(ctx) {
    const hits: CheckHit[] = [];
    const re = /@font-face\s*\{([^}]*)\}/gi;
    let m: RegExpExecArray | null;
    while ((m = re.exec(ctx.content)) !== null) {
      const block = m[1] ?? "";
      const line = ctx.content.slice(0, m.index).split("\n").length;
      const fdMatch = /font-display\s*:\s*([a-z-]+)/i.exec(block);
      if (!fdMatch) {
        hits.push({ line, message: "@font-face block is missing font-display — fonts will FOIT (flash of invisible text) by default" });
      } else if (/^(block|auto)$/i.test(fdMatch[1] ?? "")) {
        hits.push({ line, message: `@font-face uses font-display: ${fdMatch[1]} — use 'swap' or 'optional' to avoid FOIT` });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 20. runtime/performance/no-detached-dom-nodes-after-navigation — missing-effect-cleanup
// ---------------------------------------------------------------------------

export const missingEffectCleanupChecker: FileChecker = {
  ruleId: "runtime/performance/no-detached-dom-nodes-after-navigation",
  kind: "file",
  appliesTo: isJsxCapableFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression" || identName(member(n, "callee")) !== "useEffect") return;
      const cb = callArgs(n)[0];
      if (!cb || (cb.type !== "ArrowFunctionExpression" && cb.type !== "FunctionExpression")) return;

      let hasSubscriptionCall = false;
      let hasCleanupReturn = false;
      walkAst(cb, (inner) => {
        if (inner.type === "CallExpression") {
          const callee = member(inner, "callee");
          const directName = identName(callee);
          const propName = callee?.type === "MemberExpression" ? identName(member(callee, "property")) : undefined;
          if (
            directName === "setInterval" ||
            propName === "addEventListener" ||
            propName === "setInterval" ||
            propName === "subscribe"
          ) {
            hasSubscriptionCall = true;
          }
        }
        if (inner.type === "ReturnStatement") {
          const arg = member(inner, "argument");
          if (arg && (arg.type === "ArrowFunctionExpression" || arg.type === "FunctionExpression")) {
            hasCleanupReturn = true;
          }
        }
      });

      if (hasSubscriptionCall && !hasCleanupReturn) {
        hits.push({
          ...locStart(n),
          message:
            "useEffect subscribes (addEventListener/setInterval/.subscribe) but never returns a cleanup function — leaves detached DOM nodes/listeners after unmount",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 21. runtime/performance/no-layout-thrashing... — layout-thrashing
// ---------------------------------------------------------------------------

const LAYOUT_READ_PROPS = new Set(["offsetHeight", "offsetWidth"]);

function bodyHasLayoutThrash(body: AstNode): boolean {
  let hasRead = false;
  let hasWrite = false;
  walkAst(body, (n) => {
    if (n.type === "MemberExpression") {
      const prop = identName(member(n, "property"));
      if (prop && LAYOUT_READ_PROPS.has(prop)) hasRead = true;
    }
    if (n.type === "CallExpression") {
      const callee = member(n, "callee");
      if (callee?.type === "MemberExpression" && identName(member(callee, "property")) === "getBoundingClientRect") {
        hasRead = true;
      }
    }
    if (n.type === "AssignmentExpression") {
      const left = member(n, "left");
      if (left?.type === "MemberExpression") {
        const leftObj = member(left, "object");
        if (leftObj?.type === "MemberExpression" && identName(member(leftObj, "property")) === "style") {
          hasWrite = true;
        }
      }
    }
  });
  return hasRead && hasWrite;
}

export const layoutThrashingChecker: FileChecker = {
  ruleId: "runtime/performance/no-layout-thrashing-purple-recalculate-layout-blocks-under-50ms-",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      let body: AstNode | undefined;
      if (n.type === "ForStatement" || n.type === "ForOfStatement" || n.type === "ForInStatement" || n.type === "WhileStatement") {
        body = member(n, "body");
      } else if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee?.type === "MemberExpression") {
          const prop = identName(member(callee, "property"));
          if (prop === "map" || prop === "forEach") {
            const cb = callArgs(n)[0];
            if (cb && (cb.type === "ArrowFunctionExpression" || cb.type === "FunctionExpression")) {
              body = member(cb, "body");
            }
          }
        }
      }
      if (body && bodyHasLayoutThrash(body)) {
        hits.push({
          ...locStart(n),
          message:
            "Loop reads layout (offsetHeight/offsetWidth/getBoundingClientRect) and writes .style in the same iteration — batch reads and writes separately to avoid layout thrashing",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 22. runtime/quality/html-lang-matches-the-user-facing-locale-at-runtime — locale-lang-sync
// ---------------------------------------------------------------------------

export const localeLangSyncChecker: FileChecker = {
  ruleId: "runtime/quality/html-lang-matches-the-user-facing-locale-at-runtime",
  kind: "file",
  appliesTo: isJsxCapableFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;

    let hasLocaleState = false;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const id = member(n, "id");
      const init = member(n, "init");
      if (id?.type !== "ArrayPattern" || init?.type !== "CallExpression" || identName(member(init, "callee")) !== "useState") {
        return;
      }
      const elements = (id as AstNode & { elements?: (AstNode | null)[] }).elements ?? [];
      const setter = elements[1];
      const setterName = setter && setter.type === "Identifier" ? identName(setter) : undefined;
      if (setterName && /locale|lang/i.test(setterName)) hasLocaleState = true;
    });

    if (!hasLocaleState) return [];
    if (/document\.documentElement\.lang\s*=/.test(ctx.content)) return [];
    return [
      {
        line: 1,
        message:
          "Component manages locale/lang state but never assigns document.documentElement.lang — <html lang> can drift from the active UI locale",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 23. runtime/security/zero-mixed-content-warnings... — mixed-content
// ---------------------------------------------------------------------------

const MIXED_CONTENT_RE = /(src|href)\s*=\s*["']http:\/\/[^"']+["']|url\(\s*['"]?http:\/\/[^)'"]+['"]?\s*\)/i;

export const mixedContentChecker: RepoChecker = {
  ruleId: "runtime/security/zero-mixed-content-warnings-on-production-https-pages",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const targetFiles = ctx.allFiles.filter((f) => /\.(html|js|css)$/i.test(f));
    for (const file of targetFiles) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      content.split("\n").forEach((line, idx) => {
        if (MIXED_CONTENT_RE.test(line)) {
          hits.push({
            path: file,
            line: idx + 1,
            message: "Resource referenced over http:// — will trigger a Mixed Content warning on an https production page",
          });
        }
      });
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 24. runtime/security/authenticated-routes-carry-the-expected-authorization-header-at- — missing-auth-header-fetch
// ---------------------------------------------------------------------------

function objectHasAuthHeader(obj: AstNode): boolean {
  const props = (obj as AstNode & { properties?: AstNode[] }).properties ?? [];
  for (const p of props) {
    const key = member(p, "key");
    const kName = identName(key) ?? literalString(key);
    if (kName?.toLowerCase() !== "headers") continue;
    const value = member(p, "value");
    if (value?.type !== "ObjectExpression") continue;
    const headerProps = (value as AstNode & { properties?: AstNode[] }).properties ?? [];
    if (
      headerProps.some((hp) => {
        const hk = member(hp, "key");
        const hkName = identName(hk) ?? literalString(hk);
        return hkName?.toLowerCase() === "authorization";
      })
    ) {
      return true;
    }
  }
  return false;
}

export const missingAuthHeaderFetchChecker: FileChecker = {
  ruleId: "runtime/security/authenticated-routes-carry-the-expected-authorization-header-at-",
  kind: "file",
  appliesTo: (p) => /\.(jsx|tsx|js|ts)$/i.test(p) && !/\/actions\//.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      const directName = identName(callee);
      const isAxios = callee?.type === "MemberExpression" && identName(member(callee, "object")) === "axios";
      if (directName !== "fetch" && !isAxios) return;

      const args = callArgs(n);
      const url = literalString(args[0]);
      if (url === undefined || !/\/api\//.test(url)) return;

      const label = directName === "fetch" ? "fetch" : "axios";
      const optionsArg = args[1];
      if (!optionsArg) {
        hits.push({
          ...locStart(n),
          message: `Call to '${label}(${JSON.stringify(url)})' has no options argument — no Authorization header on an authenticated /api/ route`,
        });
        return;
      }
      if (optionsArg.type !== "ObjectExpression" || !objectHasAuthHeader(optionsArg)) {
        hits.push({
          ...locStart(n),
          message: `Call to '${label}(${JSON.stringify(url)})' has no Authorization header configured`,
        });
      }
    });
    return hits;
  },
};
