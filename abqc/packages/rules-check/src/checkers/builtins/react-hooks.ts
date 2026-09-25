/**
 * React "Hooks Correctness & Render Performance" domain checkers, plus the
 * one React 19 rule (`use()` for promises) which is closely related to the
 * `useEffect`+`.then()` pattern checked here.
 *
 * Covers `rules/react/hooks.md` and `rules/react/react19.md`.
 */
import { readFile } from "node:fs/promises";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { requireParsed, isCheckHitArray, member, isJsxCapableFile } from "./shared.js";
import { walkAst, locStart, parseSource, type AstNode } from "../../utils/parse.js";
import { nameOf, jsxAttrs, elementName, functionReturnsJSX } from "./react-components.js";

const appliesToJsx = isJsxCapableFile;

const FUNCTION_TYPES = new Set(["FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression"]);

/**
 * Visits `root`'s subtree without descending into a nested function's body —
 * a closure defined inside `root` is a separate scope and its calls (e.g. a
 * `fetch()` inside a `useEffect` callback) shouldn't count as happening in
 * `root`'s own top-level body.
 */
function walkWithinFunction(root: AstNode, visit: (n: AstNode) => void): void {
  const go = (n: AstNode): void => {
    visit(n);
    for (const key of Object.keys(n)) {
      if (key === "loc" || key === "range" || key === "parent") continue;
      const val = n[key];
      if (!val || typeof val !== "object") continue;
      if (Array.isArray(val)) {
        for (const item of val) {
          if (item && typeof item === "object" && "type" in item) {
            const child = item as AstNode;
            if (FUNCTION_TYPES.has(child.type) && child !== root) continue;
            go(child);
          }
        }
      } else if ("type" in val) {
        const child = val as AstNode;
        if (FUNCTION_TYPES.has(child.type) && child !== root) continue;
        go(child);
      }
    }
  };
  go(root);
}

function componentFunctionInfo(n: AstNode): { name: string; node: AstNode } | undefined {
  if (n.type === "FunctionDeclaration") {
    const name = nameOf(member(n, "id"));
    if (name && /^[A-Z]/.test(name)) return { name, node: n };
  }
  if (n.type === "VariableDeclarator") {
    const name = nameOf(member(n, "id"));
    const init = member(n, "init");
    if (name && /^[A-Z]/.test(name) && init && (init.type === "ArrowFunctionExpression" || init.type === "FunctionExpression")) {
      return { name, node: init };
    }
  }
  return undefined;
}

function isMemoCall(n: AstNode): boolean {
  if (n.type !== "CallExpression") return false;
  const callee = member(n, "callee");
  if (nameOf(callee) === "memo") return true;
  if (callee?.type === "MemberExpression" && nameOf(member(callee, "object")) === "React" && nameOf(member(callee, "property")) === "memo") {
    return true;
  }
  return false;
}

/**
 * Covers BOTH "no inline object/array literals as props on memoed children"
 * and "stable handlers via useCallback when passed to memoed children" — an
 * inline `{}`/`[]` and an inline arrow/function expression are the exact
 * same defeat-React.memo-by-reference-identity bug, so one pass covers both.
 *
 * Cross-file by design: pass 1 scans every .jsx/.tsx file in the repo for
 * `React.memo(...)`/`memo(...)` assignments to learn memoed component names;
 * pass 2 scans every file's JSX usages of those names for inline literal
 * props.
 */
export const inlinePropOnMemoedChecker: RepoChecker = {
  ruleId: "react/hooks/no-inline-object-array-literals-as-props-on-memoed-children",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const files = ctx.allFiles.filter(isJsxCapableFile);
    const memoNames = new Set<string>();
    const parsedFiles: Array<{ path: string; ast: AstNode }> = [];

    for (const file of files) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      const parsed = parseSource(content, file);
      if (!parsed) continue;
      parsedFiles.push({ path: file, ast: parsed.ast });
      walkAst(parsed.ast, (n) => {
        if (n.type === "VariableDeclarator") {
          const init = member(n, "init");
          if (init && isMemoCall(init)) {
            const idName = nameOf(member(n, "id"));
            if (idName) memoNames.add(idName);
          }
        }
        if (n.type === "ExportDefaultDeclaration") {
          const decl = member(n, "declaration");
          if (decl && isMemoCall(decl)) {
            const args = (decl as AstNode & { arguments?: AstNode[] }).arguments ?? [];
            const inner = args[0];
            const innerName = inner ? nameOf(inner) : undefined;
            if (innerName) memoNames.add(innerName);
          }
        }
      });
    }

    if (memoNames.size === 0) return hits;

    for (const { path, ast } of parsedFiles) {
      walkAst(ast, (n) => {
        if (n.type !== "JSXElement") return;
        const tagName = elementName(n);
        if (!tagName || !memoNames.has(tagName)) return;
        for (const attr of jsxAttrs(n)) {
          if (attr.type !== "JSXAttribute") continue;
          const value = member(attr, "value");
          if (value?.type !== "JSXExpressionContainer") continue;
          const expr = member(value, "expression");
          if (!expr) continue;
          if (["ObjectExpression", "ArrayExpression", "ArrowFunctionExpression", "FunctionExpression"].includes(expr.type)) {
            const propName = nameOf(member(attr, "name"));
            hits.push({
              ...locStart(attr),
              message: `Inline literal passed as prop '${propName}' to memoed component '${tagName}' creates a new reference every render, defeating React.memo`,
              path,
            });
          }
        }
      });
    }
    return hits;
  },
};

export const trivialUseMemoChecker: FileChecker = {
  ruleId: "react/hooks/usememo-only-for-measurably-expensive-computation",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      if (nameOf(member(n, "callee")) !== "useMemo") return;
      const args = (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
      const factory = args[0];
      if (!factory || (factory.type !== "ArrowFunctionExpression" && factory.type !== "FunctionExpression")) return;
      const body = member(factory, "body");
      if (!body) return;
      let expr: AstNode | undefined;
      if (body.type === "BlockStatement") {
        const stmts = (body as AstNode & { body?: AstNode[] }).body ?? [];
        const only = stmts.length === 1 ? stmts[0] : undefined;
        if (!only || only.type !== "ReturnStatement") return;
        expr = member(only, "argument");
      } else {
        expr = body; // arrow shorthand body
      }
      if (expr && isTrivialExpr(expr)) {
        hits.push({ ...locStart(n), message: "useMemo wraps a trivial computation — likely unnecessary." });
      }
    });
    return hits;
  },
};

function isTrivialExpr(expr: AstNode): boolean {
  if (["MemberExpression", "Literal", "Identifier"].includes(expr.type)) return true;
  if (expr.type === "UnaryExpression" || expr.type === "BinaryExpression") {
    let hasLoopOrHeavyCall = false;
    walkAst(expr, (n) => {
      if (["ForStatement", "WhileStatement", "DoWhileStatement", "ForInStatement", "ForOfStatement"].includes(n.type)) {
        hasLoopOrHeavyCall = true;
      }
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee?.type === "MemberExpression") {
          const propName = nameOf(member(callee, "property"));
          if (propName && ["map", "filter", "sort", "reduce"].includes(propName)) hasLoopOrHeavyCall = true;
        }
      }
    });
    return !hasLoopOrHeavyCall;
  }
  return false;
}

export const fetchInRenderChecker: FileChecker = {
  ruleId: "react/hooks/no-fetching-in-render-use-useeffect-suspense-or-a-query-lib",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      const info = componentFunctionInfo(n);
      if (!info) return;
      walkWithinFunction(info.node, (inner) => {
        if (inner.type !== "CallExpression") return;
        const callee = member(inner, "callee");
        const calleeName = nameOf(callee);
        const isFetchLike =
          calleeName === "fetch" ||
          calleeName === "axios" ||
          (callee?.type === "MemberExpression" && nameOf(member(callee, "object")) === "axios");
        if (isFetchLike) {
          hits.push({ ...locStart(inner), message: "Fetching directly in the component render body — move to useEffect, Suspense, or a query lib" });
        }
      });
    });
    return hits;
  },
};

export const noLazyRouteChecker: FileChecker = {
  ruleId: "react/hooks/code-split-with-lazy-suspense-for-routes-and-heavy-components",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    let hasLazyCall = false;
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression" && nameOf(member(n, "callee")) === "lazy") hasLazyCall = true;
    });
    if (hasLazyCall) return [];
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ImportDeclaration") return;
      const source = member(n, "source");
      const value = source?.type === "Literal" ? (source as AstNode & { value: unknown }).value : undefined;
      if (typeof value === "string" && /\/(pages|routes)\//i.test(value)) {
        hits.push({ ...locStart(n), message: `Eager import of route/page module '${value}' — code-split with React.lazy + Suspense` });
      }
    });
    return hits;
  },
};

export const unvirtualizedListChecker: FileChecker = {
  ruleId: "react/hooks/virtualize-long-lists-100-items",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    if (/react-window|react-virtual|TableView/.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      if (nameOf(member(callee, "property")) !== "map") return;
      const obj = member(callee, "object");
      if (!obj || obj.type === "ArrayExpression") return; // small literal array — not a concern
      const args = (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
      const cb = args[0];
      if (!cb || (cb.type !== "ArrowFunctionExpression" && cb.type !== "FunctionExpression")) return;
      if (!functionReturnsJSX(cb)) return;
      hits.push({
        ...locStart(n),
        message: "Rendering a mapped list without virtualization — consider react-window/react-virtual or Spectrum's TableView for lists over ~100 items",
      });
    });
    return hits;
  },
};

export const bindInJsxChecker: FileChecker = {
  ruleId: "react/hooks/no-bind-in-jsx-use-usecallback-or-class-methods",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXAttribute") return;
      const value = member(n, "value");
      if (value?.type !== "JSXExpressionContainer") return;
      const expr = member(value, "expression");
      if (expr?.type !== "CallExpression") return;
      const callee = member(expr, "callee");
      if (callee?.type !== "MemberExpression") return;
      if (nameOf(member(callee, "property")) !== "bind") return;
      hits.push({ ...locStart(n), message: "`.bind()` in JSX creates a new function reference every render — use useCallback or a class method" });
    });
    return hits;
  },
};

export const manualPromiseToStateChecker: FileChecker = {
  ruleId: "react/react19/use-the-react-19-use-hook-for-promises-and-context",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    let hasUseHook = false;
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression" && nameOf(member(n, "callee")) === "use") hasUseHook = true;
    });
    if (hasUseHook) return [];

    let hasUseState = false;
    const setterNames = new Set<string>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const init = member(n, "init");
      if (init?.type !== "CallExpression" || nameOf(member(init, "callee")) !== "useState") return;
      hasUseState = true;
      const id = member(n, "id");
      if (id?.type !== "ArrayPattern") return;
      const elements = (id as AstNode & { elements?: Array<AstNode | null> }).elements ?? [];
      const setterEl = elements[1];
      const setterName = setterEl ? nameOf(setterEl) : undefined;
      if (setterName) setterNames.add(setterName);
    });
    if (!hasUseState || setterNames.size === 0) return [];

    let hasThenIntoSetter = false;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression" || nameOf(member(callee, "property")) !== "then") return;
      const args = (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
      const cb = args[0];
      if (!cb) return;
      let callsSetter = cb.type === "Identifier" && setterNames.has(nameOf(cb) ?? "");
      walkAst(cb, (inner) => {
        if (inner.type === "CallExpression") {
          const innerName = nameOf(member(inner, "callee"));
          if (innerName && setterNames.has(innerName)) callsSetter = true;
        }
      });
      if (callsSetter) hasThenIntoSetter = true;
    });

    if (hasThenIntoSetter) {
      return [{ message: "Manual promise-to-state via useEffect + .then() — consider the React 19 use() hook instead" }];
    }
    return [];
  },
};
