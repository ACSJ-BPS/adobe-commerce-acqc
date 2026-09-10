import { readFile, stat } from "node:fs/promises";
import { basename } from "node:path";
import { gzipSync } from "node:zlib";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isActionFile, isSourceFile } from "@abqc/core";
import { parseSource, walkAst, findFunctions, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName, isJsxCapableFile } from "./shared.js";

// ---------------------------------------------------------------------------
// Gap-fill batch — App Builder performance + runtime/vitals checkers
// (rules audit items 34, 35, 38-43, 46).
// ---------------------------------------------------------------------------

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
  for (let i = 0; i < cap; i++) if (content[i] === "\n") line++;
  return line;
}

function calleeText(node: AstNode | undefined): string {
  if (!node) return "";
  if (node.type === "Identifier") return identName(node) ?? "";
  if (node.type === "MemberExpression") {
    const obj = calleeText(member(node, "object"));
    const prop = member(node, "property");
    const propName = prop?.type === "Identifier" ? (identName(prop) ?? "") : "";
    return obj ? `${obj}.${propName}` : propName;
  }
  return "";
}

function callArgs(n: AstNode): AstNode[] {
  return ((n as AstNode & { arguments?: AstNode[] }).arguments as AstNode[] | undefined) ?? [];
}

function objectProperties(n: AstNode | undefined): AstNode[] {
  if (!n) return [];
  return ((n as AstNode & { properties?: AstNode[] }).properties as AstNode[] | undefined) ?? [];
}

function propKeyName(prop: AstNode): string | undefined {
  const key = member(prop, "key");
  if (!key) return undefined;
  if (key.type === "Identifier") return identName(key);
  if (key.type === "Literal") return String((key as AstNode & { value: unknown }).value);
  return undefined;
}

function escapeRegExp(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

async function readSafe(p: string): Promise<string | null> {
  try {
    return await readFile(p, "utf8");
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// 34. state-write-concurrency
// STATIC (heuristic). Known limits: manual batching via a for-loop with sleep isn't
// recognized as capped.
// ---------------------------------------------------------------------------

export const stateWriteConcurrencyChecker: FileChecker = {
  ruleId: "appbuilder/performance/cap-concurrency-on-aio-lib-state-writes-and-plan-ioext-quota-inc",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    if (/pMap|p-map|pLimit/i.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      if (calleeText(member(n, "callee")) !== "Promise.all") return;
      const first = callArgs(n)[0];
      if (!first || first.type !== "CallExpression") return;
      const innerCallee = member(first, "callee");
      const innerProp = innerCallee ? member(innerCallee, "property") : undefined;
      if (!(innerProp?.type === "Identifier" && identName(innerProp) === "map")) return;
      const mapArgs = callArgs(first);
      const cb = mapArgs[mapArgs.length - 1];
      if (!cb) return;
      let hasStatePut = false;
      walkAst(cb, (inner) => {
        if (hasStatePut || inner.type !== "CallExpression") return;
        const t = calleeText(member(inner, "callee"));
        if (/^(state|State)\.put$/.test(t)) hasStatePut = true;
      });
      if (hasStatePut) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Promise.all(...map(...state.put...)) with no pMap/p-map/pLimit concurrency cap in the file — cap concurrency on aio-lib-state writes and plan IOEXT quota increases for burst workloads.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 35. chunk-checkpoints
// STATIC (heuristic). Known limits: "large enough to need chunking" can't be
// measured statically — deliberately over-flags every unbounded loop
// regardless of real data volume.
// ---------------------------------------------------------------------------

const CHECKPOINT_REF_RE = /checkpoint|cursor|lastId/i;
const STATE_PUT_RE = /\bstate\.put\s*\(|\bState\.put\s*\(/;
const COUNTER_GUARD_RE = /\b\w+\s*(\+\+|\+=\s*1)/;
const COUNTER_COMPARE_RE = /(<|<=|>=|>)\s*\d+/;

export const chunkCheckpointsChecker: FileChecker = {
  ruleId: "appbuilder/performance/chunk-large-jobs-across-invocations-with-state-checkpoints",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "WhileStatement" && n.type !== "ForStatement") return;
      const loopText = sourceTextOf(ctx.content, n);
      const hasCounterGuard = COUNTER_GUARD_RE.test(loopText) && COUNTER_COMPARE_RE.test(loopText);
      const hasTimeGuard = /Date\.now\s*\(\)/.test(loopText);
      if (hasCounterGuard || hasTimeGuard) return;
      const hasCheckpointState = STATE_PUT_RE.test(loopText) && CHECKPOINT_REF_RE.test(loopText);
      if (!hasCheckpointState) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Unbounded while/for loop with no counter/time safety guard and no state.put( checkpoint/cursor/lastId ) — chunk large jobs across invocations with State checkpoints.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 38. sw-scope
// ---------------------------------------------------------------------------

export const swScopeChecker: FileChecker = {
  ruleId: "runtime/loading/service-worker-registers-with-valid-scope-pwa-only",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      if (calleeText(member(n, "callee")) !== "navigator.serviceWorker.register") return;
      const args = callArgs(n);
      if (args.length < 2) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "navigator.serviceWorker.register(...) called with no options object — pass an explicit { scope } so the service worker registers with a valid, intentional scope.",
        });
        return;
      }
      const opts = args[1];
      const hasScope = opts?.type === "ObjectExpression" && objectProperties(opts).some((p) => propKeyName(p) === "scope");
      if (!hasScope) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "navigator.serviceWorker.register(...) options object has no scope property — set an explicit, valid scope.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 39. golden-path-risk-factors
// STATIC (heuristic) (risk-reduction proxy). Known limits: runtime errors from
// causes outside this checklist are invisible; a flagged risk factor doesn't
// guarantee an actual console.error fires in practice — this is a
// risk-reduction proxy, not a guarantee.
// ---------------------------------------------------------------------------

const GOLDEN_PATH_CAP = 20;

function isStatementNode(n: AstNode): boolean {
  return n.type.endsWith("Statement") || n.type === "VariableDeclaration";
}

function walkWithNearestStatement(
  node: AstNode,
  stmtAncestor: AstNode | undefined,
  visit: (n: AstNode, stmt: AstNode | undefined) => void,
): void {
  const next = isStatementNode(node) ? node : stmtAncestor;
  visit(node, next);
  for (const key of Object.keys(node)) {
    if (key === "loc" || key === "range" || key === "parent") continue;
    const val = node[key];
    if (!val || typeof val !== "object") continue;
    if (Array.isArray(val)) {
      for (const item of val) {
        if (item && typeof item === "object" && "type" in item) walkWithNearestStatement(item as AstNode, next, visit);
      }
    } else if ("type" in val) {
      walkWithNearestStatement(val as AstNode, next, visit);
    }
  }
}

function scanGoldenPathFile(filePath: string, content: string, hits: CheckHit[]): void {
  const parsed = parseSource(content, filePath);
  if (!parsed) return;
  const ast = parsed.ast;

  // (a) JSON.parse( with no enclosing try/catch.
  const tryBlockRanges: Array<[number, number]> = [];
  walkAst(ast, (n) => {
    if (n.type !== "TryStatement") return;
    const block = member(n, "block");
    const r = rangeOf(block);
    if (r) tryBlockRanges.push(r);
  });
  const insideTry = (idx: number): boolean => tryBlockRanges.some(([s, e]) => idx >= s && idx <= e);
  walkAst(ast, (n) => {
    if (hits.length >= GOLDEN_PATH_CAP) return;
    if (n.type !== "CallExpression") return;
    if (calleeText(member(n, "callee")) !== "JSON.parse") return;
    const idx = rangeOf(n)?.[0];
    if (idx !== undefined && !insideTry(idx)) {
      hits.push({
        path: filePath,
        line: offsetToLine(content, idx),
        message: "JSON.parse( with no enclosing try/catch — golden-path risk factor for an uncaught runtime error.",
      });
    }
  });

  // (b) .then( chain with no .catch( in the same statement.
  const seenStmtStart = new Set<number>();
  walkWithNearestStatement(ast, undefined, (n, stmt) => {
    if (hits.length >= GOLDEN_PATH_CAP) return;
    if (n.type !== "CallExpression") return;
    const callee = member(n, "callee");
    const prop = callee ? member(callee, "property") : undefined;
    if (!(callee?.type === "MemberExpression" && prop?.type === "Identifier" && identName(prop) === "then")) return;
    const scopeNode = stmt ?? n;
    const scopeStart = rangeOf(scopeNode)?.[0];
    if (scopeStart === undefined || seenStmtStart.has(scopeStart)) return;
    const text = sourceTextOf(content, scopeNode);
    if (!/\.catch\s*\(/.test(text)) {
      seenStmtStart.add(scopeStart);
      const idx = rangeOf(n)?.[0] ?? scopeStart;
      hits.push({
        path: filePath,
        line: offsetToLine(content, idx),
        message: ".then( chain with no .catch( anywhere in the same statement — golden-path risk factor for an unhandled rejection.",
      });
    }
  });

  // (c) computed bracket access with no visible bounds check in the enclosing function.
  const fns = findFunctions(ast);
  walkAst(ast, (n) => {
    if (hits.length >= GOLDEN_PATH_CAP) return;
    if (n.type !== "MemberExpression") return;
    if (!(n as AstNode & { computed?: boolean }).computed) return;
    const propNode = member(n, "property");
    if (propNode?.type === "Literal" && typeof (propNode as AstNode & { value: unknown }).value === "string") return;
    const idx = rangeOf(n)?.[0];
    const enclosing = fns.find((f) => {
      const r = rangeOf(f.node);
      return r && idx !== undefined && idx >= r[0] && idx <= r[1];
    });
    const scopeText = enclosing ? sourceTextOf(content, enclosing.node) : content;
    const hasBoundsCheck = /\.length\b/.test(scopeText) || /if\s*\([^)]*<[^)]*\)/.test(scopeText);
    if (!hasBoundsCheck && idx !== undefined) {
      hits.push({
        path: filePath,
        line: offsetToLine(content, idx),
        message:
          "Computed array/bracket access with no visible bounds check (.length / if (i < ...)) in the enclosing function — golden-path risk factor for an out-of-range read.",
      });
    }
  });
}

export const goldenPathRiskFactorsChecker: RepoChecker = {
  ruleId: "runtime/quality/zero-console-error-calls-on-the-golden-path-traversal",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const files = ctx.allFiles.filter(isJsxCapableFile);
    for (const f of files) {
      if (hits.length >= GOLDEN_PATH_CAP) break;
      const content = await readSafe(f);
      if (content === null) continue;
      scanGoldenPathFile(f, content, hits);
    }
    return hits.slice(0, GOLDEN_PATH_CAP);
  },
};

// ---------------------------------------------------------------------------
// 40. csp-uncovered-resource
// STATIC (heuristic). Known limits: a resource URL built dynamically at runtime from
// a config value can't be resolved to a concrete host statically;
// nonce/hash-based inline-script allowances aren't matched against actual
// script content.
// ---------------------------------------------------------------------------

// The CSP value itself commonly contains single-quoted tokens ('self',
// 'unsafe-inline') while the surrounding HTML attribute is double-quoted —
// a single [^"']+ capture (matching either quote char) would stop at the
// first token's opening quote. Match same-quote-pair alternatives instead.
const CSP_META_RE = /<meta[^>]+Content-Security-Policy[^>]+content=(?:"([^"]*)"|'([^']*)')/i;
const CSP_IGNORED_TOKENS = new Set(["'self'", "'unsafe-inline'", "'unsafe-eval'", "'none'", "data:", "blob:", "filesystem:"]);
const RESOURCE_URL_RE = /(?:src|href)\s*=\s*["'](https?:\/\/[^"']+)["']|(?:fetch|import)\(\s*["'](https?:\/\/[^"']+)["']/g;

function hostCoveredByCsp(host: string, allowedHosts: Set<string>): boolean {
  for (const allowed of allowedHosts) {
    if (allowed === host) return true;
    if (allowed.startsWith("*.") && host.endsWith(allowed.slice(1))) return true;
  }
  return false;
}

export const cspUncoveredResourceChecker: RepoChecker = {
  ruleId: "runtime/security/zero-refused-to-load-csp-violations-in-the-browser-console",
  kind: "repo",
  async check(ctx) {
    const htmlFiles = ctx.allFiles.filter((f) => /index\.html$/i.test(f.replace(/\\/g, "/")));
    if (htmlFiles.length === 0) return [];

    let cspRaw: string | undefined;
    let entryFile: string | undefined;
    for (const f of htmlFiles) {
      const content = await readSafe(f);
      entryFile = entryFile ?? f;
      if (content === null) continue;
      const m = CSP_META_RE.exec(content);
      const captured = m?.[1] ?? m?.[2];
      if (captured) {
        cspRaw = captured;
        entryFile = f;
        break;
      }
    }
    if (!cspRaw) {
      return [
        {
          path: entryFile,
          line: 1,
          message: "No CSP policy found (no Content-Security-Policy meta tag in the HTML entry file) to verify resource coverage against.",
        },
      ];
    }

    const allowedHosts = new Set<string>();
    for (const directive of cspRaw.split(";")) {
      const tokens = directive.trim().split(/\s+/).slice(1);
      for (const tok of tokens) {
        if (!tok || CSP_IGNORED_TOKENS.has(tok) || tok.startsWith("'nonce-") || tok.startsWith("'sha")) continue;
        allowedHosts.add(tok.replace(/^https?:\/\//, ""));
      }
    }

    const hits: CheckHit[] = [];
    const resourceFiles = ctx.allFiles.filter((f) => /\.(js|jsx|ts|tsx|html)$/i.test(f));
    for (const f of resourceFiles) {
      if (hits.length >= 20) break;
      const content = await readSafe(f);
      if (content === null) continue;
      const re = new RegExp(RESOURCE_URL_RE.source, "g");
      let m: RegExpExecArray | null;
      while ((m = re.exec(content))) {
        const url = m[1] ?? m[2];
        if (!url) continue;
        let host: string;
        try {
          host = new URL(url).hostname;
        } catch {
          continue;
        }
        if (!hostCoveredByCsp(host, allowedHosts)) {
          hits.push({
            path: f,
            line: offsetToLine(content, m.index),
            message: `Resource host "${host}" is not covered by any CSP directive in the entry HTML — risks a 'Refused to load' CSP violation in the browser console.`,
          });
        }
        if (hits.length >= 20) break;
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 41. lighthouse-anti-pattern-checklist
// STATIC (heuristic) (lowest-precision proxy in the rule set). Known limits: passing
// the checklist doesn't guarantee a score >= 90 (unlisted factors matter);
// failing an item doesn't guarantee the score actually drops below 90 —
// intended as review-candidate flagging only.
// ---------------------------------------------------------------------------

export const lighthouseAntiPatternChecklistChecker: RepoChecker = {
  ruleId: "runtime/vitals/lighthouse-performance-score-90-on-the-production-build",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];

    for (const f of ctx.allFiles.filter((x) => /\.css$/i.test(x))) {
      const content = await readSafe(f);
      if (content === null) continue;
      const blocks = content.match(/@font-face\s*\{[^}]*\}/gi) ?? [];
      for (const block of blocks) {
        const m = /font-display\s*:\s*([a-z-]+)/i.exec(block);
        if (!m || /^(block|auto)$/i.test(m[1] ?? "")) {
          hits.push({
            path: f,
            line: 1,
            message: "@font-face block missing font-display (or set to block/auto) — Lighthouse Performance anti-pattern candidate (invisible-text blocking).",
          });
        }
      }
    }

    for (const f of ctx.allFiles.filter((x) => /\.html$/i.test(x))) {
      const content = await readSafe(f);
      if (content === null) continue;
      const headMatch = /<head[^>]*>([\s\S]*?)<\/head>/i.exec(content);
      const headContent = headMatch?.[1] ?? "";
      const scriptTags = headContent.match(/<script\b[^>]*>/gi) ?? [];
      for (const tag of scriptTags) {
        if (/\bsrc\s*=/.test(tag) && !/\b(defer|async)\b/.test(tag)) {
          hits.push({
            path: f,
            line: 1,
            message: "<script> in <head> has a src but no defer/async — render-blocking script is a Lighthouse Performance anti-pattern candidate.",
          });
        }
      }
    }

    for (const f of ctx.allFiles.filter((x) => /\.html$/i.test(x) || isJsxCapableFile(x))) {
      const content = await readSafe(f);
      if (content === null) continue;
      const imgTags = content.match(/<img\b[^>]*>/gi) ?? [];
      for (const tag of imgTags) {
        if (!/loading\s*=\s*["']lazy["']/.test(tag)) {
          hits.push({
            path: f,
            line: 1,
            message:
              "<img> with no loading=\"lazy\" (candidate only — imprecise for above-the-fold images) — Lighthouse Performance review candidate.",
          });
        }
      }
    }

    return hits;
  },
};

// ---------------------------------------------------------------------------
// 42. lcp-risk-factors
// Known limits: static half only — the actual LCP timing measurement
// requires a live browser, not verified here.
// ---------------------------------------------------------------------------

export const lcpRiskFactorsChecker: RepoChecker = {
  ruleId: "runtime/vitals/largest-contentful-paint-2-5s-on-slow-3g",
  kind: "repo",
  async check(ctx) {
    const htmlFiles = ctx.allFiles.filter((f) => /index\.html$/i.test(f.replace(/\\/g, "/")));
    if (htmlFiles.length === 0) return [];
    const hits: CheckHit[] = [];
    for (const f of htmlFiles) {
      const content = await readSafe(f);
      if (content === null) continue;
      const imgTags = content.match(/<img\b[^>]*>/gi) ?? [];
      for (const tag of imgTags) {
        const hasDims = /\bwidth\s*=/.test(tag) && /\bheight\s*=/.test(tag);
        if (hasDims) continue;
        const srcMatch = /\bsrc\s*=\s*["']([^"']+)["']/.exec(tag);
        const src = srcMatch?.[1];
        const preloadRe = src
          ? new RegExp(`<link[^>]+rel=["']preload["'][^>]+as=["']image["'][^>]+href=["']${escapeRegExp(src)}["']`, "i")
          : null;
        const hasPreload = preloadRe ? preloadRe.test(content) : false;
        if (!hasPreload) {
          hits.push({
            path: f,
            line: 1,
            message: "<img> with no width/height and no matching <link rel=\"preload\" as=\"image\"> — risk factor for LCP >= 2.5s on slow-3G.",
          });
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 43. cls-risk-factors
// Known limits: static half only — actual aggregate CLS score requires a
// live browser.
// ---------------------------------------------------------------------------

export const clsRiskFactorsChecker: RepoChecker = {
  ruleId: "runtime/vitals/cumulative-layout-shift-0-1-across-all-top-routes",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const f of ctx.allFiles.filter((x) => /\.html$/i.test(x) || isJsxCapableFile(x))) {
      const content = await readSafe(f);
      if (content === null) continue;
      const tags = content.match(/<(img|iframe)\b[^>]*>/gi) ?? [];
      for (const tag of tags) {
        const hasDims = /\bwidth\s*=/.test(tag) && /\bheight\s*=/.test(tag);
        if (!hasDims) {
          hits.push({
            path: f,
            line: 1,
            message: "<img>/<iframe> with no width/height attributes — risk factor for CLS >= 0.1 across top routes.",
          });
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 46. bundle-size
// Known limits: only measures a build artifact if one already exists in the
// scanned file set — running a fresh build is out of scope for a static
// check; only checks the single largest/most-main-looking JS file, may miss
// the true entry chunk in an unusual build layout.
// ---------------------------------------------------------------------------

export const bundleSizeChecker: RepoChecker = {
  ruleId: "runtime/performance/first-paint-js-bundle-200kb-gzipped",
  kind: "repo",
  async check(ctx) {
    const distJs = ctx.allFiles.filter((f) => /dist\/.*\.js$/i.test(f.replace(/\\/g, "/")));
    if (distJs.length === 0) return [];
    let target = distJs.find((f) => /\b(index|main)\b/i.test(basename(f)));
    if (!target) {
      let bestSize = -1;
      for (const f of distJs) {
        try {
          const info = await stat(f);
          if (info.size > bestSize) {
            bestSize = info.size;
            target = f;
          }
        } catch {
          continue;
        }
      }
    }
    if (!target) return [];
    let content: Buffer;
    try {
      content = await readFile(target);
    } catch {
      return [];
    }
    const gz = gzipSync(content);
    if (gz.length >= 204_800) {
      return [
        {
          path: target,
          line: 1,
          message: `${basename(target)} is ${Math.round(gz.length / 1024)} KB gzipped (>= 200KB) — keep the first-paint JS bundle under 200KB gzipped.`,
        },
      ];
    }
    return [];
  },
};
