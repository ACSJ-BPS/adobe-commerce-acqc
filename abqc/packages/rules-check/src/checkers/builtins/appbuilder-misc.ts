import { readFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { join } from "node:path";
import { parse as parseYaml } from "yaml";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isActionFile, isYamlConfig } from "@abqc/core";
import { findFunctions, walkAst, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";
import { isEventsHandlerPath } from "./appbuilder-events.js";

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

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

function indentOf(line: string): number {
  const m = /^(\s*)/.exec(line);
  return m?.[1]?.length ?? 0;
}

function referencesIdentifier(node: AstNode, name: string): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (found) return;
    if (n.type === "Identifier" && identName(n) === name) found = true;
  });
  return found;
}

function callArguments(n: AstNode): AstNode[] {
  return ((n as AstNode & { arguments?: AstNode[] }).arguments as AstNode[] | undefined) ?? [];
}

function objectProperties(n: AstNode): AstNode[] {
  return ((n as AstNode & { properties?: AstNode[] }).properties as AstNode[] | undefined) ?? [];
}

function propKeyName(prop: AstNode): string | undefined {
  const key = member(prop, "key");
  if (!key) return undefined;
  if (key.type === "Identifier") return identName(key);
  if (key.type === "Literal") return String((key as AstNode & { value: unknown }).value);
  return undefined;
}

function resolveIdentifierInit(ast: AstNode, name: string): AstNode | undefined {
  let found: AstNode | undefined;
  walkAst(ast, (n) => {
    if (found || n.type !== "VariableDeclarator") return;
    const id = member(n, "id");
    if (identName(id) === name) found = member(n, "init");
  });
  return found;
}

// ---------------------------------------------------------------------------
// 20 / 28 — observability + sdk: structured logging via Core.Logger
// ---------------------------------------------------------------------------

/**
 * MECHANICAL. Also the target for `appbuilder/sdk/logger-pattern-core-logger-
 * with-params-log-level` — same underlying detection, reused rather than
 * duplicated.
 */
export const structuredLoggingChecker: FileChecker = {
  ruleId: "appbuilder/observability/use-structured-json-logging-and-forward-activation-logs-to-an-ex",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    if (/\bCore\.Logger\s*\(/.test(ctx.content)) return [];
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    const re = /\bconsole\.(log|error)\s*\(/;
    for (let i = 0; i < lines.length; i++) {
      if (re.test(lines[i] ?? "")) {
        hits.push({
          line: i + 1,
          message:
            "Use Core.Logger (structured JSON logging, params.LOG_LEVEL) instead of console.log/console.error — forward activation logs to an external sink.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 21 — performance: serial awaits in a loop instead of fan-out
// ---------------------------------------------------------------------------

const LOOP_TYPES = new Set(["ForStatement", "ForInStatement", "ForOfStatement", "WhileStatement", "DoWhileStatement"]);

/**
 * STATIC (heuristic). Known limits: intentionally serial work (must-be-ordered
 * writes) is indistinguishable from accidentally-serial fan-out —
 * over-flags legitimate serial dependencies by design.
 */
export const serialAwaitInLoopChecker: FileChecker = {
  ruleId: "appbuilder/performance/fan-out-large-workloads-split-into-parallel-sub-actions",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const fns = findFunctions(parsed.ast);
    walkAst(parsed.ast, (n) => {
      if (!LOOP_TYPES.has(n.type)) return;
      // Any awaited call inside the loop body qualifies — not just an
      // allowlisted `invoke|fetch|axios` callee name, which misses real
      // helper-function wrappers around the actual network call (e.g. a
      // `sendToSAP(...)` helper) and any non-HTTP async I/O just as capable
      // of benefiting from fan-out.
      let hasNetworkAwait = false;
      walkAst(n, (inner) => {
        if (hasNetworkAwait || inner.type !== "AwaitExpression") return;
        const arg = member(inner, "argument");
        if (arg?.type === "CallExpression") hasNetworkAwait = true;
      });
      if (!hasNetworkAwait) return;
      const idx = rangeOf(n)?.[0];
      const enclosing = fns.find((f) => {
        const r = rangeOf(f.node);
        return r && idx !== undefined && idx >= r[0] && idx <= r[1];
      });
      const scopeText = enclosing ? sourceTextOf(ctx.content, enclosing.node) : ctx.content;
      if (!/Promise\.all\s*\(/.test(scopeText)) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Loop awaits a network/invoke call serially with no Promise.all( fan-out in scope — split large workloads into parallel sub-actions instead of awaiting one at a time.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 22 — performance: module-scope I/O vs main()-scoped client construction
// ---------------------------------------------------------------------------

const FUNCTION_TYPES = new Set(["FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression"]);

function walkTopLevelOnly(node: AstNode, visit: (n: AstNode) => void): void {
  visit(node);
  for (const key of Object.keys(node)) {
    if (key === "loc" || key === "range" || key === "parent") continue;
    const val = node[key];
    if (!val || typeof val !== "object") continue;
    const children = Array.isArray(val) ? val : [val];
    for (const child of children) {
      if (!child || typeof child !== "object" || !("type" in child)) continue;
      const c = child as AstNode;
      if (FUNCTION_TYPES.has(c.type)) continue;
      walkTopLevelOnly(c, visit);
    }
  }
}

/**
 * MECHANICAL. Implements two independent, literally-specified conditions in
 * one checker function, since the same builtin id is shared by two rule
 * markdown entries with opposite framing: `appbuilder/performance/defer-all-
 * i-o-and-heavy-imports-out-of-module-scope...` wants condition A (I/O or
 * heavy client construction AT module scope is bad — it runs eagerly during
 * cold start); `deployment/slos/reuse-module-scope-http-clients-and-sdk-
 * instances-across-warm-in` wants condition B, the inverse (a client
 * constructed INSIDE main() on every invocation is bad — it wastes warm-
 * container reuse). Note the apparent tension: a module-scope `new
 * FooClient()` trips condition A while a main()-scoped one trips condition
 * B — every placement trips exactly one of the two, which is the literal
 * behavior specified for this shared builtin, not a design flaw introduced
 * here.
 */
export const noModuleScopeIoChecker: FileChecker = {
  ruleId: "appbuilder/performance/defer-all-i-o-and-heavy-imports-out-of-module-scope-to-avoid-col",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];

    walkTopLevelOnly(parsed.ast, (n) => {
      if (n.type === "CallExpression") {
        const text = calleeText(member(n, "callee"));
        if (/^fetch$/.test(text) || /axios/.test(text) || /readFileSync/.test(text)) {
          hits.push({
            ...(locStart(n) ?? { line: 1 }),
            message:
              "I/O call at module scope (fetch/axios/readFileSync) runs eagerly during cold start, before any request — defer all I/O and heavy imports out of module scope.",
          });
        }
      } else if (n.type === "NewExpression") {
        const text = calleeText(member(n, "callee"));
        if (/(Client|SDK)$/.test(text)) {
          hits.push({
            ...(locStart(n) ?? { line: 1 }),
            message:
              "Heavy client/SDK construction at module scope runs eagerly during cold start — defer all I/O and heavy imports out of module scope.",
          });
        }
      }
    });

    const mainFn = findFunctions(parsed.ast).find((f) => f.name === "main");
    if (mainFn?.node.body) {
      walkAst(mainFn.node.body, (n) => {
        if (n.type !== "NewExpression") return;
        const text = calleeText(member(n, "callee"));
        if (/(Client|SDK)$/.test(text)) {
          hits.push({
            ...(locStart(n) ?? { line: 1 }),
            message:
              "SDK/HTTP client constructed inside main() — construct it once at module scope so warm invocations reuse it instead of rebuilding it on every call.",
          });
        }
      });
    }

    return hits;
  },
};

// ---------------------------------------------------------------------------
// 23 — performance: pagination loops need an explicit max-pages safety limit
// ---------------------------------------------------------------------------

function referencesMemberEndingIn(node: AstNode, names: string[]): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (found || n.type !== "MemberExpression") return;
    const prop = member(n, "property");
    const propName = prop?.type === "Identifier" ? identName(prop) : undefined;
    if (propName && names.includes(propName)) found = true;
  });
  return found;
}

/** MECHANICAL. */
export const paginationMaxPagesChecker: FileChecker = {
  ruleId: "appbuilder/performance/pagination-loops-need-an-explicit-max-pages-safety-limit",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "WhileStatement" && n.type !== "ForStatement") return;
      const test = member(n, "test");
      if (!test || !referencesMemberEndingIn(test, ["has_next", "hasNextPage", "next"])) return;
      const loopText = sourceTextOf(ctx.content, n);
      const hasCounterGuard = /\b\w+\s*(\+\+|\+=\s*1)/.test(loopText) && /(<|<=|>=|>)\s*\d+/.test(loopText);
      const hasTimeGuard = /Date\.now\s*\(\)/.test(loopText);
      if (!hasCounterGuard && !hasTimeGuard) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Pagination loop driven by has_next/hasNextPage/next with no counter (max pages) or time-based safety guard — pagination loops need an explicit max-pages safety limit.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 24 — performance: bulk exports should write to Files SDK, not return bodies
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const bulkExportFilesSdkChecker: FileChecker = {
  ruleId: "appbuilder/performance/for-bulk-exports-write-to-files-sdk-and-return-a-url-don-t-retur",
  kind: "file",
  appliesTo: (filePath) => isActionFile(filePath) && /export/i.test(filePath),
  check(ctx) {
    if (/\bfiles\.write\s*\(/.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ReturnStatement") return;
      const arg = member(n, "argument");
      if (!arg || arg.type !== "ObjectExpression") return;
      const bodyProp = objectProperties(arg).find((p) => propKeyName(p) === "body");
      if (!bodyProp) return;
      let bodyValue = member(bodyProp, "value");
      if (bodyValue?.type === "Identifier") {
        bodyValue = resolveIdentifierInit(parsed.ast, identName(bodyValue) ?? "") ?? bodyValue;
      }
      const isArrayLike =
        bodyValue?.type === "ArrayExpression" ||
        (bodyValue?.type === "CallExpression" && /\.map$/.test(calleeText(member(bodyValue, "callee"))));
      if (isArrayLike) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Export action returns a mapped/array response body directly with no files.write( call anywhere — for bulk exports, write to the Files SDK and return a URL, don't return the payload inline.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 25 — performance: uniform memory/concurrency copy-pasted across actions
// ---------------------------------------------------------------------------

interface LimitEntry {
  file: string;
  action: string;
}

const DEFAULT_MEMORY = 256;
const DEFAULT_CONCURRENCY = 1;

function collectLimits(doc: unknown, file: string, keyName: string | undefined, map: Map<string, LimitEntry[]>): void {
  if (!doc || typeof doc !== "object") return;
  const obj = doc as Record<string, unknown>;
  const limits = obj.limits;
  if (limits && typeof limits === "object") {
    const l = limits as Record<string, unknown>;
    if (typeof l.memory === "number" && typeof l.concurrency === "number") {
      const isDefault = l.memory === DEFAULT_MEMORY && l.concurrency === DEFAULT_CONCURRENCY;
      if (!isDefault) {
        const pairKey = `${l.memory}:${l.concurrency}`;
        if (!map.has(pairKey)) map.set(pairKey, []);
        map.get(pairKey)!.push({ file, action: keyName ?? "unknown" });
      }
    }
  }
  for (const [k, v] of Object.entries(obj)) {
    if (v && typeof v === "object") collectLimits(v, file, k, map);
  }
}

/**
 * STATIC (heuristic) (P2). Known limits: two actions could coincidentally have the
 * same correct sizing — flagged anyway; this is a smell detector, not proof
 * of mis-sizing.
 */
export const uniformMemoryConcurrencyChecker: RepoChecker = {
  ruleId: "appbuilder/performance/right-size-action-memory-and-concurrency-don-t-ship-everything-a",
  kind: "repo",
  async check(ctx) {
    const map = new Map<string, LimitEntry[]>();
    for (const file of ctx.allFiles.filter(isYamlConfig)) {
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
      collectLimits(doc, file, undefined, map);
    }
    const hits: CheckHit[] = [];
    for (const [pairKey, entries] of map) {
      if (entries.length < 3) continue;
      const [memory, concurrency] = pairKey.split(":");
      const filesSeen = new Set<string>();
      for (const e of entries) {
        if (filesSeen.has(e.file)) continue;
        filesSeen.add(e.file);
        hits.push({
          line: 1,
          path: e.file,
          message: `${entries.length} actions share memory=${memory}MB/concurrency=${concurrency} (${entries.map((x) => x.action).join(", ")}) — right-size each action individually rather than shipping the same sizing everywhere.`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 26 — runtime: error responses must be shaped { error: { statusCode, body } }
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const errorResponseShapeChecker: FileChecker = {
  ruleId: "appbuilder/runtime/error-response-shape-error-statuscode-body",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const mainFn = findFunctions(parsed.ast).find((f) => f.name === "main");
    if (!mainFn?.node.body) return [];
    walkAst(mainFn.node.body, (n) => {
      if (n.type !== "ReturnStatement") return;
      const arg = member(n, "argument");
      if (!arg || arg.type !== "ObjectExpression") return;
      const props = objectProperties(arg);
      const statusProp = props.find((p) => propKeyName(p) === "statusCode");
      if (!statusProp) return;
      const val = member(statusProp, "value");
      const numVal = val?.type === "Literal" ? (val as AstNode & { value: unknown }).value : undefined;
      if (typeof numVal !== "number" || numVal < 400) return;
      const hasErrorWrapper = props.some((p) => propKeyName(p) === "error");
      if (!hasErrorWrapper) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Error response returns statusCode directly on the response object instead of nesting it under an 'error' key — use the shape { error: { statusCode, body } }.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 27 — runtime: back off aggressively on 429 instead of hot-looping
// ---------------------------------------------------------------------------

function isAlwaysTrueTest(test: AstNode | undefined): boolean {
  if (!test) return false;
  return test.type === "Literal" && (test as AstNode & { value: unknown }).value === true;
}

/** MECHANICAL. */
export const backoff429Checker: FileChecker = {
  ruleId: "appbuilder/runtime/respond-to-429-by-backing-off-runtime-throttles-aggressively",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      const isWhileTrue = n.type === "WhileStatement" && isAlwaysTrueTest(member(n, "test"));
      const isInfiniteFor = n.type === "ForStatement" && !member(n, "test");
      if (!isWhileTrue && !isInfiniteFor) return;
      const loopText = sourceTextOf(ctx.content, n);
      if (!/\b429\b/.test(loopText)) return;
      const hasSetTimeout = /\bsetTimeout\s*\(/.test(loopText);
      const hasBackoffWord = /backoff|retry/i.test(loopText);
      if (!hasSetTimeout && !hasBackoffWord) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Unbounded loop checks for a 429 with no setTimeout( and no backoff/retry logic — respond to 429 by backing off, runtime throttles aggressively.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 29 — sdk: use Files SDK for blobs >100KB, not State SDK
// ---------------------------------------------------------------------------

function isBlobLikeValueNode(node: AstNode | undefined): boolean {
  if (!node) return false;
  let n = node;
  if (n.type === "AwaitExpression") {
    const arg = member(n, "argument");
    if (arg) n = arg;
  }
  if (n.type === "CallExpression") {
    const text = calleeText(member(n, "callee"));
    return /\.(json|text)$/.test(text) || /^readFile(Sync)?$/.test(text);
  }
  return false;
}

/**
 * STATIC (heuristic) (P2). Known limits: can't measure actual byte size statically
 * — this flags 'State holding a whole read result' as a proxy for
 * probably-large, both under- and over-flags relative to true size.
 */
export const stateLargeBlobChecker: FileChecker = {
  ruleId: "appbuilder/sdk/use-files-sdk-for-blobs-100kb-not-state-sdk",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      const objName = identName(member(callee, "object"));
      const propName = identName(member(callee, "property"));
      if (!(objName === "state" || objName === "State") || propName !== "put") return;
      const args = callArguments(n);
      let valueArg = args[1];
      if (!valueArg) return;
      if (valueArg.type === "Identifier") {
        valueArg = resolveIdentifierInit(parsed.ast, identName(valueArg) ?? "") ?? valueArg;
      }
      if (isBlobLikeValueNode(valueArg)) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "state.put() appears to store a whole read result (.json()/.text()/readFile) as a proxy for a large blob — use the Files SDK for blobs over 100KB, State is TTL-bounded and not meant for large payloads.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 30 — sdk: State SDK is TTL-bounded, never the source of truth
// ---------------------------------------------------------------------------

/**
 * STATIC (heuristic). Known limits: the real database lives in a different action
 * file entirely, invisible to a single-file scan.
 */
export const stateOnlyPersistenceChecker: FileChecker = {
  ruleId: "appbuilder/sdk/state-sdk-is-ttl-bounded-never-the-source-of-truth",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const hasStateCalls = /\b[Ss]tate\.(get|put)\s*\(/.test(ctx.content);
    if (!hasStateCalls) return [];
    const hasOtherPersistence =
      /\.query\s*\(/.test(ctx.content) ||
      /\bdb\.\w+\s*\(/i.test(ctx.content) ||
      /\b(mongo|mongoose|sequelize|knex|pg\.|mysql)\b/i.test(ctx.content);
    if (hasOtherPersistence) return [];
    return [
      {
        line: 1,
        message:
          "The only persistence-shaped calls in this file are state.get/state.put — State SDK is TTL-bounded and must never be the source of truth, use an external DB or Files SDK for durable data.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 31 — sdk: define default params at the package level, not per action
// ---------------------------------------------------------------------------

interface PackageInfo {
  inputs?: Record<string, unknown>;
  actions: Record<string, unknown>;
}

function findPackages(doc: unknown): PackageInfo[] {
  const packages: PackageInfo[] = [];
  function walk(node: unknown): void {
    if (!node || typeof node !== "object") return;
    const obj = node as Record<string, unknown>;
    if (obj.actions && typeof obj.actions === "object") {
      packages.push({
        inputs: (obj.inputs as Record<string, unknown> | undefined) ?? undefined,
        actions: obj.actions as Record<string, unknown>,
      });
    }
    for (const v of Object.values(obj)) {
      if (v && typeof v === "object") walk(v);
    }
  }
  walk(doc);
  return packages;
}

function normalizeInputs(inputs: unknown): string | null {
  if (!inputs || typeof inputs !== "object") return null;
  const keys = Object.keys(inputs as object).sort();
  if (keys.length === 0) return null;
  return JSON.stringify(keys.map((k) => [k, (inputs as Record<string, unknown>)[k]]));
}

/** MECHANICAL. */
export const duplicatePackageInputsChecker: RepoChecker = {
  ruleId: "appbuilder/sdk/define-default-params-at-package-level-when-shared-across-action",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const file of ctx.allFiles.filter(isYamlConfig)) {
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
      let flaggedThisFile = false;
      for (const pkg of findPackages(doc)) {
        const pkgSig = normalizeInputs(pkg.inputs);
        const groups = new Map<string, string[]>();
        for (const [actionName, actionDef] of Object.entries(pkg.actions ?? {})) {
          const sig = normalizeInputs((actionDef as Record<string, unknown> | undefined)?.inputs);
          if (!sig) continue;
          if (pkgSig && sig === pkgSig) continue;
          if (!groups.has(sig)) groups.set(sig, []);
          groups.get(sig)!.push(actionName);
        }
        for (const names of groups.values()) {
          if (names.length >= 2) {
            hits.push({
              line: 1,
              path: file,
              message: `Actions ${names.join(", ")} declare an identical 'inputs' block — define shared defaults at the package level instead of duplicating them per action.`,
            });
            flaggedThisFile = true;
            break;
          }
        }
        if (flaggedThisFile) break;
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 32 — sdk: no built-in relational database — simulated joins via State
// ---------------------------------------------------------------------------

/**
 * STATIC (heuristic) (simplified — full cross-call data-flow tracing was judged too
 * complex for reasonable effort here). Known limits: 3+ state.get calls in
 * one function doesn't prove a join is happening, just a smell; a real join
 * spanning multiple functions/files is invisible to this per-function count.
 */
export const stateSimulatedJoinChecker: FileChecker = {
  ruleId: "appbuilder/sdk/no-built-in-relational-database-use-files-sdk-or-external-db",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const stateGetRe = /\b[Ss]tate\.get\s*\(/g;
    const fns = findFunctions(parsed.ast);
    if (fns.length === 0) {
      const count = (ctx.content.match(stateGetRe) ?? []).length;
      if (count >= 3) {
        hits.push({ line: 1, message: "3+ state.get( calls in this file — no built-in relational database, use Files SDK or an external DB instead of simulating joins with State." });
      }
      return hits;
    }
    for (const { node } of fns) {
      const text = sourceTextOf(ctx.content, node);
      const count = (text.match(stateGetRe) ?? []).length;
      if (count >= 3) {
        hits.push({
          ...(locStart(node) ?? { line: 1 }),
          message:
            "Function makes 3+ separate state.get( calls — a possible simulated relational join; there's no built-in relational database, use Files SDK or an external DB instead.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 33 — validation: checkMissingRequestInputs before SDK init calls
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const checkMissingInputsFirstChecker: FileChecker = {
  ruleId: "appbuilder/validation/validate-inputs-with-checkmissingrequestinputs-before-sdk-calls",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const mainFn = findFunctions(parsed.ast).find((f) => f.name === "main");
    if (!mainFn?.node.body) return [];
    const calls: Array<{ name: string; pos: number; node: AstNode }> = [];
    walkAst(mainFn.node.body, (n) => {
      if (n.type !== "CallExpression") return;
      const name = calleeText(member(n, "callee"));
      const pos = rangeOf(n)?.[0] ?? 0;
      calls.push({ name, pos, node: n });
    });
    calls.sort((a, b) => a.pos - b.pos);
    const checkIdx = calls.findIndex((c) => /(^|\.)checkMissingRequestInputs$/.test(c.name));
    const sdkInitRe = /(^|\.)(init|createClient|connect)$/i;
    const hits: CheckHit[] = [];
    for (let i = 0; i < calls.length; i++) {
      const c = calls[i]!;
      if (sdkInitRe.test(c.name) && (checkIdx === -1 || i < checkIdx)) {
        hits.push({
          ...(locStart(c.node) ?? { line: 1 }),
          message:
            "SDK init/client call happens before (or without) a checkMissingRequestInputs( call in main() — validate inputs first, before any SDK calls.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 34 — validation: never pass params into eval/exec/shell/SQL
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const paramsToSqlEvalShellChecker: FileChecker = {
  ruleId: "appbuilder/validation/treat-params-as-untrusted-never-pass-to-sql-eval-or-shell",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression") {
        const name = calleeText(member(n, "callee"));
        const isDangerous = /^eval$/.test(name) || /(^|\.)(exec|execSync)$/.test(name);
        if (isDangerous) {
          const arg = callArguments(n)[0];
          if (arg && (arg.type === "TemplateLiteral" || arg.type === "BinaryExpression") && referencesIdentifier(arg, "params")) {
            hits.push({
              ...(locStart(n) ?? { line: 1 }),
              message:
                "Never pass params into eval()/exec()/execSync() — treat action params as untrusted input, never build shell/eval strings from them.",
            });
          }
        }
      }
      if (n.type === "TemplateLiteral") {
        const text = sourceTextOf(ctx.content, n);
        if (/\b(select|insert|update|delete)\b/i.test(text) && referencesIdentifier(n, "params")) {
          hits.push({
            ...(locStart(n) ?? { line: 1 }),
            message:
              "SQL-like template literal interpolates params directly — treat params as untrusted input, use parameterized queries, never string-build SQL from them.",
          });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 35 — validation: never construct action/sequence invoke names from input
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const noDynamicActionInvokeNameChecker: FileChecker = {
  ruleId: "appbuilder/validation/never-construct-action-or-sequence-names-from-user-input",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const calleeName = calleeText(member(n, "callee"));
      if (!/\.invoke$|^invoke$/.test(calleeName)) return;
      const objArg = callArguments(n).find((a) => a.type === "ObjectExpression");
      if (!objArg) return;
      const nameProp = objectProperties(objArg).find((p) => propKeyName(p) === "name");
      if (!nameProp) return;
      const value = member(nameProp, "value");
      const isStringLiteral = value?.type === "Literal" && typeof (value as AstNode & { value: unknown }).value === "string";
      if (!isStringLiteral) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Action/sequence invoke() name is not a string literal — never construct action or sequence names from user input.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 37 — secrets: never hand-edit auto-generated .env AIO_ vars
// ---------------------------------------------------------------------------

/**
 * STATIC (heuristic) (P4). Known limits: a hand-edit that happens to touch the whole
 * block is indistinguishable; a legitimate CLI-driven regeneration that only
 * changed one line (e.g. token rotation) gets flagged as a hand-edit.
 */
export const envPartialEditHistoryChecker: RepoChecker = {
  ruleId: "appbuilder/secrets/never-edit-auto-generated-aio-secrets-in-env",
  kind: "repo",
  async check(ctx) {
    let log: string;
    try {
      log = execFileSync("git", ["log", "-p", "--", ".env"], {
        cwd: ctx.projectRoot,
        encoding: "utf8",
        stdio: ["ignore", "pipe", "ignore"],
      });
    } catch {
      return [];
    }
    if (!log.trim()) return [];
    const hits: CheckHit[] = [];
    const commitBlocks = log.split(/^commit /m).slice(1);
    for (const block of commitBlocks) {
      const changedLines = block.split("\n").filter((l) => /^[+-][^+-]/.test(l));
      const aioChanged = changedLines.filter((l) => /AIO_/.test(l));
      if (aioChanged.length > 0 && changedLines.length > 0 && changedLines.length < 3) {
        hits.push({
          line: 1,
          path: join(ctx.projectRoot, ".env"),
          message:
            "A commit touches only a couple of AIO_-prefixed lines in .env — looks like a hand-edit rather than a full CLI-driven regeneration; never hand-edit auto-generated aio secrets in .env.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 38 — tooling: TypeScript actions require webpack + ts-loader
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const tsActionNeedsWebpackChecker: RepoChecker = {
  ruleId: "appbuilder/tooling/typescript-actions-require-webpack-with-ts-loader",
  kind: "repo",
  async check(ctx) {
    const configs = ctx.allFiles.filter((f) => /(^|[\\/])app\.config\.ya?ml$/.test(f.replace(/\\/g, "/")));
    let hasTsAction = false;
    for (const file of configs) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      if (/function\s*:\s*.*\.ts\b/.test(content)) {
        hasTsAction = true;
        break;
      }
    }
    if (!hasTsAction) return [];
    const webpackFiles = ctx.allFiles.filter((f) => /(^|[\\/])webpack\.config\.js$/.test(f.replace(/\\/g, "/")));
    for (const file of webpackFiles) {
      try {
        const content = await readFile(file, "utf8");
        if (content.includes("ts-loader")) return [];
      } catch {
        continue;
      }
    }
    return [
      {
        line: 1,
        message:
          "TypeScript action(s) found (function: *.ts) but no webpack.config.js with ts-loader was found — TypeScript actions require a webpack build step with ts-loader.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 39 — tooling: aio app dev doesn't run scheduled events / webhook triggers
// ---------------------------------------------------------------------------

/**
 * STATIC (heuristic) (P1). Known limits: reuses the events-handler path heuristic
 * (actions/ path containing events|webhook|handler), so an unconventionally
 * named handler is invisible to this check too.
 */
export const eventsLocalDevDocChecker: RepoChecker = {
  ruleId: "appbuilder/tooling/aio-app-dev-does-not-run-scheduled-events-or-webhook-triggers",
  kind: "repo",
  async check(ctx) {
    const hasEventsHandler = ctx.allFiles.some(isEventsHandlerPath);
    if (!hasEventsHandler) return [];
    const docFiles = ctx.allFiles.filter((f) => /(^|[\\/])(README|CONTRIBUTING)\.md$/i.test(f.replace(/\\/g, "/")));
    for (const file of docFiles) {
      try {
        const content = await readFile(file, "utf8");
        if (/aio runtime action invoke/i.test(content) || /aio app test/i.test(content)) return [];
      } catch {
        continue;
      }
    }
    return [
      {
        line: 1,
        message:
          "Repo has an I/O Events / webhook handler action but no README/CONTRIBUTING doc mentions 'aio runtime action invoke' or 'aio app test' — aio app dev does not run scheduled events or webhook triggers locally, document how to test them.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 40 — tooling: use aio runtime action invoke for cloud smoke tests in CI
// ---------------------------------------------------------------------------

/**
 * STATIC (heuristic) (P1). Known limits: a repo with no CI workflow files at all is
 * treated as out of scope for this check (no CI to add a smoke-test step
 * to) rather than flagged — a deliberate design choice to avoid noise for
 * repos that don't use GitHub Actions CI.
 */
export const smokeTestCiStepChecker: RepoChecker = {
  ruleId: "appbuilder/tooling/use-aio-runtime-action-invoke-for-cloud-smoke-tests",
  kind: "repo",
  async check(ctx) {
    const workflows = ctx.allFiles.filter((f) => /(^|[\\/])\.github[\\/]workflows[\\/][^\\/]+\.ya?ml$/.test(f.replace(/\\/g, "/")));
    if (workflows.length === 0) return [];
    for (const file of workflows) {
      try {
        const content = await readFile(file, "utf8");
        if (/aio runtime action invoke/i.test(content)) return [];
      } catch {
        continue;
      }
    }
    return [
      {
        line: 1,
        message:
          "No CI workflow step runs 'aio runtime action invoke' — use aio runtime action invoke for a cloud smoke test after deploy.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 41 — tooling: track/pin the aio CLI version
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const aioCliVersionPinnedChecker: RepoChecker = {
  ruleId: "appbuilder/tooling/track-the-aio-cli-version-watch-release-notes-for-deprecations",
  kind: "repo",
  async check(ctx) {
    const pkgPath = join(ctx.projectRoot, "package.json");
    let pkg: { dependencies?: Record<string, string>; devDependencies?: Record<string, string> };
    try {
      pkg = JSON.parse(await readFile(pkgPath, "utf8"));
    } catch {
      return [];
    }
    const ver = pkg.devDependencies?.["@adobe/aio-cli"] ?? pkg.dependencies?.["@adobe/aio-cli"];
    if (!ver) {
      return [
        {
          line: 1,
          path: pkgPath,
          message:
            "@adobe/aio-cli is not declared in package.json dependencies/devDependencies — track the aio CLI version and watch release notes for deprecations.",
        },
      ];
    }
    if (ver === "latest" || /^[\^~*]/.test(ver)) {
      return [
        {
          line: 1,
          path: pkgPath,
          message: `@adobe/aio-cli version '${ver}' is not pinned to an exact version — track the aio CLI version and watch release notes for deprecations.`,
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 42 — tooling: remove template/sample scaffold actions before shipping
// ---------------------------------------------------------------------------

const SCAFFOLD_ACTION_DENYLIST = new Set(["generic", "sample", "hello-world"]);

function findApplicationId(doc: unknown): string | undefined {
  if (!doc || typeof doc !== "object") return undefined;
  const app = (doc as Record<string, unknown>).application as Record<string, unknown> | undefined;
  if (app && typeof app.id === "string") return app.id;
  return undefined;
}

function findAllActionNames(doc: unknown): string[] {
  const names: string[] = [];
  function walk(node: unknown): void {
    if (!node || typeof node !== "object") return;
    const obj = node as Record<string, unknown>;
    if (obj.actions && typeof obj.actions === "object") {
      names.push(...Object.keys(obj.actions as Record<string, unknown>));
    }
    for (const v of Object.values(obj)) {
      if (v && typeof v === "object") walk(v);
    }
  }
  walk(doc);
  return names;
}

/**
 * STATIC (heuristic) (P3). Known limits: always under-flags (denylist is finite); a
 * renamed-but-still-unmodified sample evades a name-only denylist.
 */
export const scaffoldMarkersChecker: RepoChecker = {
  ruleId: "appbuilder/tooling/remove-all-template-sample-actions-and-placeholder-content-befor",
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
      if (findApplicationId(doc) === "my-app") {
        hits.push({ line: 1, path: file, message: "application.id is still the scaffold default 'my-app' — set a real application id before shipping." });
      }
      for (const actionName of findAllActionNames(doc)) {
        if (SCAFFOLD_ACTION_DENYLIST.has(actionName.toLowerCase())) {
          hits.push({
            line: 1,
            path: file,
            message: `Action '${actionName}' looks like an unmodified template/sample action — remove all template/sample actions and placeholder content before shipping.`,
          });
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 43 — tooling: parameterize app.config.yaml inputs via env vars
// ---------------------------------------------------------------------------

/** MECHANICAL. */
export const literalConfigInputsChecker: FileChecker = {
  ruleId: "appbuilder/tooling/parameterize-app-config-yaml-inputs-via-env-vars-mirror-them-in-",
  kind: "file",
  appliesTo: isYamlConfig,
  check(ctx) {
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    let inInputsBlock = false;
    let inputsIndent = -1;
    for (let i = 0; i < lines.length; i++) {
      const raw = lines[i] ?? "";
      if (raw.trim() === "" || raw.trim().startsWith("#")) continue;
      const indent = indentOf(raw);
      if (inInputsBlock && indent <= inputsIndent) inInputsBlock = false;
      if (/^\s*inputs\s*:\s*$/.test(raw)) {
        inInputsBlock = true;
        inputsIndent = indent;
        continue;
      }
      if (!inInputsBlock) continue;
      const kv = /^\s*([A-Za-z0-9_]+)\s*:\s*(.+)$/.exec(raw);
      if (!kv) continue;
      const key = kv[1]!;
      const rawValue = (kv[2] ?? "").replace(/\s*#.*$/, "").trim();
      if (!rawValue) continue;
      const isQuoted = /^['"].*['"]$/.test(rawValue);
      const bare = isQuoted ? rawValue.slice(1, -1) : rawValue;
      // Adobe's own `actions.config.yaml` convention is the BARE `$VAR` form
      // (e.g. `LOG_LEVEL: $LOG_LEVEL`) — the braced `${VAR}` form is also
      // valid but far less common in real App Builder configs. Rejecting
      // bare `$VAR` as "hard-coded" measured a 85-91% false-positive rate
      // against real app.config.yaml/actions.config.yaml files.
      if (/^\$\{.+\}$/.test(bare) || /^\$[A-Za-z_][A-Za-z0-9_]*$/.test(bare)) continue;
      if (/^(true|false)$/i.test(bare)) continue;
      if (/^-?\d+(\.\d+)?$/.test(bare)) continue;
      hits.push({
        line: i + 1,
        message: `Config input '${key}' has a hard-coded literal value ('${bare}') — parameterize app.config.yaml inputs via \${ENV_VAR}s and mirror them in .env.`,
      });
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 45 — runtime: action sequences must respect the 60s edge cap
// ---------------------------------------------------------------------------

interface SequenceInfo {
  name: string;
  nameLine: number;
  sum: number;
  missing: boolean;
}

function parseSequences(content: string): SequenceInfo[] {
  const lines = content.split("\n");
  const results: SequenceInfo[] = [];
  let i = 0;
  while (i < lines.length && !/^\s*sequences\s*:\s*$/.test(lines[i] ?? "")) i++;
  if (i >= lines.length) return results;
  const seqBlockIndent = indentOf(lines[i] ?? "");
  i++;
  let current: SequenceInfo | null = null;
  for (; i < lines.length; i++) {
    const raw = lines[i] ?? "";
    if (raw.trim() === "") continue;
    const ind = indentOf(raw);
    if (ind <= seqBlockIndent) break;
    const nameMatch = /^\s*([A-Za-z0-9_-]+)\s*:\s*(#.*)?$/.exec(raw);
    if (nameMatch && ind === seqBlockIndent + 2) {
      if (current) results.push(current);
      current = { name: nameMatch[1]!, nameLine: i + 1, sum: 0, missing: false };
      continue;
    }
    const itemMatch = /^\s*-\s*[A-Za-z0-9_./-]+\s*(#\s*avgDurationMs\s*:\s*(\d+))?/.exec(raw);
    if (itemMatch && current) {
      if (itemMatch[2]) {
        current.sum += Number(itemMatch[2]);
      } else {
        current.missing = true;
      }
    }
  }
  if (current) results.push(current);
  return results;
}

/**
 * STATIC (heuristic) (P5). Depends on a hand-maintained `# avgDurationMs: N` comment
 * annotation per sequence step (YAML comments aren't in the parsed
 * structure, hence the raw-text regex approach). Known limits: an
 * unannotated or stale annotation makes the estimate meaningless; this
 * checker only verifies the annotation is present and sums to under the
 * cap, not that the annotation is accurate. Also the target for
 * `appbuilder/api-mesh/performance/design-queries-to-complete-well-under-
 * the-60s-edge-timeout` (same builtin, aliased).
 */
export const sequenceBudgetChecker: FileChecker = {
  ruleId: "appbuilder/runtime/action-sequences-must-respect-the-60s-edge-cap-design-steps-to-c",
  kind: "file",
  appliesTo: isYamlConfig,
  check(ctx) {
    const hits: CheckHit[] = [];
    for (const seq of parseSequences(ctx.content)) {
      if (seq.missing) {
        hits.push({
          line: seq.nameLine,
          message: `Sequence '${seq.name}' has a step with no '# avgDurationMs: N' annotation — cannot verify the 60s edge-cap budget; annotate every step.`,
        });
      } else if (seq.sum >= 60000) {
        hits.push({
          line: seq.nameLine,
          message: `Sequence '${seq.name}' estimated total duration ${seq.sum}ms meets/exceeds the 60000ms edge cap — design steps to complete well under the 60s edge timeout.`,
        });
      }
    }
    return hits;
  },
};
