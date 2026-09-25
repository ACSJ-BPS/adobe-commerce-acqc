import type { CheckHit, CheckContext, FileChecker } from "../../types.js";
import { isSourceFile, isActionFile } from "@abqc/core";
import { walkAst, walkWithinFunction, findFunctions, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

const FUNCTION_TYPES = new Set(["FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression"]);

function callArgs(n: AstNode): AstNode[] {
  return (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
}

function calleeFullName(callee: AstNode | undefined): string {
  if (!callee) return "";
  if (callee.type === "Identifier") return identName(callee) ?? "";
  if (callee.type === "MemberExpression") {
    const obj = member(callee, "object");
    const prop = member(callee, "property");
    const objName = obj && obj.type === "Identifier" ? identName(obj) : undefined;
    const propName = identName(prop);
    if (objName && propName) return `${objName}.${propName}`;
    return propName ?? "";
  }
  return "";
}

/**
 * Walks every descendant of `node`, visiting each node, but never descends
 * into the children of a FUNCTION_TYPES node — including when `node` itself
 * is one. Used for "top level only" scans where any function boundary
 * (however it's reached) must stop the walk from going further in.
 */
function walkExcludingFunctionBodies(node: AstNode, visit: (n: AstNode) => void): void {
  const go = (n: AstNode) => {
    visit(n);
    if (FUNCTION_TYPES.has(n.type)) return;
    for (const key of Object.keys(n)) {
      if (key === "loc" || key === "range" || key === "parent") continue;
      const val = n[key];
      if (!val || typeof val !== "object") continue;
      const children = Array.isArray(val) ? val : [val];
      for (const item of children) {
        if (item && typeof item === "object" && "type" in item) go(item as AstNode);
      }
    }
  };
  go(node);
}

/**
 * Walks `root`'s subtree looking for a node of `type`, without descending
 * into a NESTED function's body (a closure defined inside `root`). `root`
 * itself is always fully searched even if it happens to be a function.
 */
function hasOwnNodeType(root: AstNode, type: string): boolean {
  let found = false;
  const go = (n: AstNode) => {
    if (found) return;
    if (n.type === type) {
      found = true;
      return;
    }
    for (const key of Object.keys(n)) {
      if (key === "loc" || key === "range" || key === "parent") continue;
      const val = n[key];
      if (!val || typeof val !== "object") continue;
      const children = Array.isArray(val) ? val : [val];
      for (const item of children) {
        if (!item || typeof item !== "object" || !("type" in item)) continue;
        const child = item as AstNode;
        if (FUNCTION_TYPES.has(child.type) && child !== root) continue;
        go(child);
      }
    }
  };
  go(root);
  return found;
}

/**
 * `node/async/no-top-level-await-of-network-calls-inside-actions`
 */
export const topLevelAwaitNetworkChecker: FileChecker = {
  ruleId: "node/async/no-top-level-await-of-network-calls-inside-actions",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkExcludingFunctionBodies(parsed.ast, (n) => {
      if (n.type !== "AwaitExpression") return;
      const arg = member(n, "argument");
      if (!arg || arg.type !== "CallExpression") return;
      const callee = member(arg, "callee");
      const name = calleeFullName(callee);
      if (/^fetch$|axios|http\.request/.test(name)) {
        hits.push({ ...locStart(n), message: "Top-level await of a network call inside an action — network calls must happen inside main()" });
      }
    });
    return hits;
  },
};

/**
 * `node/async/async-functions-must-contain-await-require-await`
 */
export const requireAwaitChecker: FileChecker = {
  ruleId: "node/async/async-functions-must-contain-await-require-await",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { name, node } of findFunctions(parsed.ast)) {
      const isAsync = (node as AstNode & { async?: boolean }).async === true;
      if (!isAsync || !node.body) continue;
      if (!hasOwnNodeType(node.body, "AwaitExpression")) {
        hits.push({ ...locStart(node), message: `async function${name ? ` '${name}'` : ""} has no await — remove 'async' or add one` });
      }
    }
    return hits;
  },
};

/**
 * `node/errors/always-handle-promise-rejections-unhandled-container-death`
 * Also serves `node/promises/promises-must-be-returned-awaited-or-catch-d`.
 */
// Jest/Vitest mock-configuration methods (`fn.mockResolvedValue(...)`,
// `.mockRejectedValueOnce(...)`, `.mockImplementation(...)`, ...) are
// synchronous setup calls, not floating promises — excluded regardless of
// what the mocked-object identifier is named.
const MOCK_METHOD_RE = /^mock[A-Z]/;
// The known async-triggering call names this rule cares about, matched
// EXACTLY (not as a prefix of the whole `object.method` string) — a prefix
// match against the concatenated name previously matched `publishedPackages
// .sort()` (object name starts with "publish") and `notifyApplyStarted()` (a
// plain `() => void` resolver alias, not an async call) as false positives.
const ASYNC_TRIGGER_NAMES = new Set(["fetch", "axios", "invoke", "notify", "send", "fireAndForget", "publish"]);

export const floatingPromiseChecker: FileChecker = {
  ruleId: "node/errors/always-handle-promise-rejections-unhandled-container-death",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ExpressionStatement") return;
      const expr = member(n, "expression");
      if (!expr || expr.type !== "CallExpression") return;
      const callee = member(expr, "callee");
      if (!callee) return;
      // Bare call — `fetch(...)`. Match the identifier itself, exactly.
      const bareName = callee.type === "Identifier" ? identName(callee) : undefined;
      // Method call — `obj.send(...)`. Match the METHOD name only, exactly —
      // never the object name, and never a `.mock*` test-double setup call.
      const propName = callee.type === "MemberExpression" ? identName(member(callee, "property")) : undefined;
      const isAsyncTrigger =
        (bareName !== undefined && ASYNC_TRIGGER_NAMES.has(bareName)) ||
        (propName !== undefined && ASYNC_TRIGGER_NAMES.has(propName) && !MOCK_METHOD_RE.test(propName));
      if (isAsyncTrigger) {
        hits.push({
          ...locStart(n),
          message: "Floating promise — call result is neither awaited, returned, nor .catch()-handled; an unhandled rejection can crash the container",
        });
      }
    });
    return hits;
  },
};

/**
 * `node/promises/then-callbacks-must-always-return-a-value`
 */
export const thenMustReturnChecker: FileChecker = {
  ruleId: "node/promises/then-callbacks-must-always-return-a-value",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!callee || callee.type !== "MemberExpression") return;
      if (identName(member(callee, "property")) !== "then") return;
      const cb = callArgs(n)[0];
      if (!cb || (cb.type !== "ArrowFunctionExpression" && cb.type !== "FunctionExpression")) return;
      const body = member(cb, "body");
      if (!body || body.type !== "BlockStatement") return;
      if (!hasOwnNodeType(body, "ReturnStatement")) {
        hits.push({ ...locStart(cb), message: ".then() callback has a block body but never returns a value" });
      }
    });
    return hits;
  },
};

/**
 * `node/promises/no-nested-then-flatten-with-chaining-or-async-await`
 */
export const nestedThenChecker: FileChecker = {
  ruleId: "node/promises/no-nested-then-flatten-with-chaining-or-async-await",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!callee || callee.type !== "MemberExpression") return;
      if (identName(member(callee, "property")) !== "then") return;
      const cb = callArgs(n)[0];
      if (!cb || (cb.type !== "ArrowFunctionExpression" && cb.type !== "FunctionExpression")) return;
      const body = member(cb, "body") ?? cb;
      let hasNestedThen = false;
      walkAst(body, (inner) => {
        if (inner.type !== "CallExpression") return;
        const innerCallee = member(inner, "callee");
        if (innerCallee && innerCallee.type === "MemberExpression" && identName(member(innerCallee, "property")) === "then") {
          hasNestedThen = true;
        }
      });
      if (hasNestedThen) {
        hits.push({ ...locStart(n), message: "Nested .then() inside a .then() callback — flatten with chaining or async/await" });
      }
    });
    return hits;
  },
};

/**
 * `node/promises/no-return-wrapping-don-t-wrap-a-promise-in-promise-resolve`
 */
export const promiseResolveWrapChecker: FileChecker = {
  ruleId: "node/promises/no-return-wrapping-don-t-wrap-a-promise-in-promise-resolve",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node: fn } of findFunctions(parsed.ast)) {
      const isAsync = (fn as AstNode & { async?: boolean }).async === true;
      // `walkWithinFunction` (not `walkAst`) so a nested closure's own
      // `return`s are attributed only once, when `findFunctions` visits that
      // closure itself — otherwise every nested function's returns get
      // scanned twice (once here via the outer function's body, again via
      // its own entry in `findFunctions`), duplicating hits.
      walkWithinFunction(fn as unknown as AstNode, (n) => {
        if (n.type !== "ReturnStatement") return;
        const arg = member(n, "argument");
        if (!arg || arg.type !== "CallExpression") return;
        const callee = member(arg, "callee");
        if (!callee || callee.type !== "MemberExpression") return;
        const objName = identName(member(callee, "object"));
        const propName = identName(member(callee, "property"));
        if (objName !== "Promise" || (propName !== "resolve" && propName !== "reject")) return;
        // An async function auto-wraps its return value already, so ANY
        // explicit Promise.resolve()/reject() inside one is always redundant.
        // A non-async function is only flagged when the wrapped value is a
        // plain literal/`new Error(...)` — clearly nothing async about it, so
        // wrapping is pure ceremony. A non-async function wrapping the
        // result of ANOTHER call/member expression (`Promise.resolve(store
        // .get(key))`) is left alone: that shape is how a synchronous
        // implementation satisfies an async interface contract (e.g. a test
        // double for an async state store) — "fixing" it would break the
        // contract, not simplify the code.
        const wrapped = callArgs(arg)[0];
        const wrappedIsPlainValue = !wrapped || wrapped.type === "Literal" || wrapped.type === "NewExpression";
        if (isAsync || wrappedIsPlainValue) {
          hits.push({
            ...locStart(n),
            message: "Returning Promise.resolve()/reject() directly wraps an already-async value — just return/throw the value",
          });
        }
      });
    }
    return hits;
  },
};

/**
 * `node/promises/don-t-return-from-a-finally-block`
 */
export const returnInFinallyChecker: FileChecker = {
  ruleId: "node/promises/don-t-return-from-a-finally-block",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "TryStatement") {
        const finalizer = member(n, "finalizer");
        if (finalizer && finalizer.type === "BlockStatement" && hasOwnNodeType(finalizer, "ReturnStatement")) {
          hits.push({ ...locStart(finalizer), message: "return inside a finally block swallows the try/catch outcome" });
        }
      }
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee && callee.type === "MemberExpression" && identName(member(callee, "property")) === "finally") {
          const cb = callArgs(n)[0];
          const body = cb ? member(cb, "body") : undefined;
          if (body && body.type === "BlockStatement" && hasOwnNodeType(body, "ReturnStatement")) {
            hits.push({ ...locStart(n), message: "return inside a .finally() callback swallows the promise outcome" });
          }
        }
      }
    });
    return hits;
  },
};

/**
 * True if `callNode` (a `.then()`/`.catch()` CallExpression) sits inside an
 * `await`ed expression — either directly (`await x.then(...)`) or as an inner
 * link of a chain whose outermost call IS awaited (`await x.then(a).catch(b)`
 * — walking that chain's outer `.catch()` call still reaches the inner
 * `.then()` call as a descendant of the same awaited argument subtree).
 * There are no parent pointers on this AST, so this is computed by walking
 * every `AwaitExpression`'s argument once and collecting every CallExpression
 * found within it, rather than walking up from `callNode`.
 */
function collectAwaitedCalls(root: AstNode): Set<AstNode> {
  const awaited = new Set<AstNode>();
  walkAst(root, (n) => {
    if (n.type !== "AwaitExpression") return;
    const arg = member(n, "argument");
    if (!arg) return;
    walkAst(arg, (inner) => {
      if (inner.type === "CallExpression") awaited.add(inner);
    });
  });
  return awaited;
}

/**
 * `node/promises/prefer-await-over-then-in-async-functions`
 */
export const thenInAsyncFnChecker: FileChecker = {
  ruleId: "node/promises/prefer-await-over-then-in-async-functions",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node } of findFunctions(parsed.ast)) {
      const isAsync = (node as AstNode & { async?: boolean }).async === true;
      if (!isAsync || !node.body) continue;
      const awaitedCalls = collectAwaitedCalls(node.body);
      walkAst(node.body, (n) => {
        if (n.type !== "CallExpression") return;
        const callee = member(n, "callee");
        if (!callee || callee.type !== "MemberExpression") return;
        const propName = identName(member(callee, "property"));
        if (propName !== "then" && propName !== "catch") return;
        // `await x.then(...)` / `await x.then(...).catch(...)` (chained, then
        // the whole chain awaited) is already the idiom this rule asks for —
        // only a `.then()`/`.catch()` call that is NEVER itself awaited
        // (directly, or as an inner link of a chain that IS awaited) is the
        // redundant pattern being flagged.
        if (awaitedCalls.has(n)) return;
        hits.push({ ...locStart(n), message: `Prefer 'await' over '.${propName}()' inside an async function` });
      });
    }
    return hits;
  },
};

/**
 * `node/reliability/cap-concurrency-on-batched-external-calls-using-p-map-or-equival`
 */
export const unboundedPromiseAllMapChecker: FileChecker = {
  ruleId: "node/reliability/cap-concurrency-on-batched-external-calls-using-p-map-or-equival",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    if (/pMap|p-map|pLimit/i.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];

    // `const promises = arr.map(async x => {...}); await Promise.all(promises)`
    // is exactly as unbounded as the inline `Promise.all(arr.map(...))` form —
    // resolve a `Promise.all(<identifier>)` argument back to its `.map(...)`
    // declaration when it isn't inlined. Not scope-precise (a same-named
    // variable elsewhere in the file could collide), matching this file's
    // existing level of rigor for this class of checker.
    const mapCallByName = new Map<string, AstNode>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const id = member(n, "id");
      const init = member(n, "init");
      if (id?.type !== "Identifier" || !init || init.type !== "CallExpression") return;
      const initCallee = member(init, "callee");
      if (initCallee?.type === "MemberExpression" && identName(member(initCallee, "property")) === "map") {
        const name = identName(id);
        if (name) mapCallByName.set(name, init);
      }
    });

    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!callee || callee.type !== "MemberExpression") return;
      if (identName(member(callee, "object")) !== "Promise" || identName(member(callee, "property")) !== "all") return;
      const arg = callArgs(n)[0];
      if (!arg) return;
      const mapCall =
        arg.type === "CallExpression"
          ? arg
          : arg.type === "Identifier"
            ? mapCallByName.get(identName(arg) ?? "")
            : undefined;
      if (!mapCall) return;
      const mapCallee = member(mapCall, "callee");
      if (!mapCallee || mapCallee.type !== "MemberExpression" || identName(member(mapCallee, "property")) !== "map") return;
      const cb = callArgs(mapCall)[0];
      if (!cb) return;
      const body = member(cb, "body") ?? cb;
      // ANY call inside the mapped callback qualifies — not just an
      // allowlisted `fetch|axios|invoke` name (which missed the rule's own
      // motivating example, aio-lib-state's `state.get`/`state.put`), and not
      // only an awaited one (a non-async callback that just returns a bare
      // `fetch(url)` — relying on the outer `Promise.all` to await it — is
      // just as unbounded a fan-out as an `async` callback that awaits
      // internally). A `.map()` callback feeding `Promise.all` that contains
      // no call at all (a pure sync transform) is a vanishingly rare, and
      // harmless, shape to also flag here.
      let hasQualifyingCall = false;
      walkAst(body, (inner) => {
        if (inner.type === "CallExpression") hasQualifyingCall = true;
      });
      if (hasQualifyingCall) {
        hits.push({
          ...locStart(n),
          message: "Promise.all(arr.map(...)) issues unbounded concurrent external calls — cap concurrency with p-map/p-limit",
        });
      }
    });
    return hits;
  },
};
