/**
 * React "Components, Naming & List Keys" domain checkers.
 *
 * Covers `rules/react/components.md`. One rule in that file —
 * "No findDOMNode — it's deprecated, use refs" — needs no AST code at all;
 * it's wired as a plain `type: regex` check (`pattern: findDOMNode\(`) directly
 * in the rule's `### Check` block, so there's no exported checker for it here.
 */
import type { CheckHit, FileChecker } from "../../types.js";
import { requireParsed, isCheckHitArray, member, isJsxCapableFile } from "./shared.js";
import { walkAst, locStart, type AstNode } from "../../utils/parse.js";
import { basename } from "node:path";

const appliesToJsx = isJsxCapableFile;

/** Works for both plain `Identifier` and JSX's `JSXIdentifier` name nodes. */
function nameOf(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier" || n.type === "JSXIdentifier") {
    return (n as AstNode & { name: string }).name;
  }
  return undefined;
}

function isReactComponentSuperClass(superClass: AstNode | undefined): boolean {
  if (!superClass) return false;
  if (superClass.type === "Identifier") return nameOf(superClass) === "Component";
  if (superClass.type === "MemberExpression") {
    const obj = member(superClass, "object");
    const prop = member(superClass, "property");
    return nameOf(obj) === "React" && nameOf(prop) === "Component";
  }
  return false;
}

function jsxAttrs(n: AstNode): AstNode[] {
  const opening = member(n, "openingElement");
  const attrs = opening ? (opening as AstNode & { attributes?: AstNode[] }).attributes : undefined;
  return attrs ?? [];
}

function findAttr(el: AstNode, attrName: string): AstNode | undefined {
  return jsxAttrs(el).find((a) => a.type === "JSXAttribute" && nameOf(member(a, "name")) === attrName);
}

function elementName(n: AstNode): string | undefined {
  const opening = member(n, "openingElement");
  if (!opening) return undefined;
  return nameOf(member(opening, "name"));
}

function nonWhitespaceChildren(n: AstNode): AstNode[] {
  const children = (n as AstNode & { children?: AstNode[] }).children ?? [];
  return children.filter((c) => !(c.type === "JSXText" && !(c as AstNode & { value: string }).value.trim()));
}

function functionReturnsJSX(fn: AstNode): boolean {
  const body = member(fn, "body");
  if (!body) return false;
  if (body.type === "JSXElement" || body.type === "JSXFragment") return true; // arrow shorthand body
  let found = false;
  walkAst(body, (n) => {
    if (n.type !== "ReturnStatement") return;
    const arg = member(n, "argument");
    if (arg && (arg.type === "JSXElement" || arg.type === "JSXFragment")) found = true;
  });
  return found;
}

/** Depth-first descent over an AST node's children (no parent pointers needed). */
function walkChildren(node: AstNode, visit: (n: AstNode) => void): void {
  for (const key of Object.keys(node)) {
    if (key === "loc" || key === "range" || key === "parent") continue;
    const val = node[key];
    if (!val || typeof val !== "object") continue;
    if (Array.isArray(val)) {
      for (const item of val) {
        if (item && typeof item === "object" && "type" in item) visit(item as AstNode);
      }
    } else if ("type" in val) {
      visit(val as AstNode);
    }
  }
}

/**
 * Component name/file naming convention.
 *
 * Rather than requiring the candidate's name to already be uppercase before
 * it's considered "the" component (which would make the PascalCase check
 * vacuous — a mis-cased component would never become a candidate in the
 * first place), we pick the first component-like declaration in the file —
 * a `FunctionDeclaration`, a `VariableDeclarator` initialized with an arrow
 * or function expression, or a `ClassDeclaration extends React.Component` —
 * in whatever case it happens to be, and THEN validate both its casing and
 * whether the file's basename matches it.
 */
export const componentPascalCaseChecker: FileChecker = {
  ruleId: "react/components/components-are-pascalcase-files-match-component-name",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    let candidate: { name: string; node: AstNode; fn?: AstNode } | undefined;
    walkAst(parsed.ast, (n) => {
      if (candidate) return;
      if (n.type === "ClassDeclaration") {
        const superClass = member(n, "superClass");
        if (isReactComponentSuperClass(superClass)) {
          const name = nameOf(member(n, "id"));
          if (name) candidate = { name, node: n };
        }
      } else if (n.type === "FunctionDeclaration") {
        const name = nameOf(member(n, "id"));
        // A `use[A-Z]...` name is the React hook-naming convention, not a
        // component — even when it happens to be the first function
        // declared in the file, it never renders JSX itself (it's called
        // *by* a component). Skip it so the search continues to a real
        // component if one exists, instead of misfiring on the hook.
        if (name && !/^use[A-Z]/.test(name) && functionReturnsJSX(n)) candidate = { name, node: n, fn: n };
      } else if (n.type === "VariableDeclarator") {
        const init = member(n, "init");
        const name = nameOf(member(n, "id"));
        if (
          name &&
          !/^use[A-Z]/.test(name) &&
          init &&
          (init.type === "ArrowFunctionExpression" || init.type === "FunctionExpression") &&
          functionReturnsJSX(init)
        ) {
          candidate = { name, node: n, fn: init };
        }
      }
    });
    if (!candidate) return [];
    const hits: CheckHit[] = [];
    const { name, node } = candidate;
    if (!/^[A-Z][a-zA-Z0-9]*$/.test(name)) {
      hits.push({ ...locStart(node), message: `Component name '${name}' is not PascalCase` });
    }
    const base = basename(ctx.filePath).replace(/\.(jsx|tsx|js|mjs|cjs)$/i, "");
    if (base !== "index" && base !== name) {
      hits.push({ ...locStart(node), message: `File name '${base}' does not match component name '${name}'` });
    }
    return hits;
  },
};

export const leakedRenderChecker: FileChecker = {
  ruleId: "react/components/no-leaked-render-short-circuit-booleans-numbers-in-jsx",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXExpressionContainer") return;
      const expr = member(n, "expression");
      if (expr?.type !== "LogicalExpression") return;
      if ((expr as AstNode & { operator?: string }).operator !== "&&") return;
      const left = member(expr, "left");
      if (!left || left.type !== "MemberExpression") return;
      if (nameOf(member(left, "property")) !== "length") return;
      hits.push({
        ...locStart(n),
        message: "Leaked render risk: `x.length && <JSX/>` renders '0' in the DOM when length is 0 — use `> 0` or a ternary",
      });
    });
    return hits;
  },
};

/** Names of function-like declarations that "look like" a React component. */
function componentLikeInfo(n: AstNode): { name: string; node: AstNode } | undefined {
  if (n.type === "FunctionDeclaration") {
    const name = nameOf(member(n, "id"));
    if (name) return { name, node: n };
  }
  if (n.type === "VariableDeclarator") {
    const init = member(n, "init");
    const name = nameOf(member(n, "id"));
    if (name && init && (init.type === "ArrowFunctionExpression" || init.type === "FunctionExpression")) {
      return { name, node: init };
    }
  }
  return undefined;
}

export const nestedComponentDefChecker: FileChecker = {
  ruleId: "react/components/no-components-defined-inside-other-components",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const visit = (node: AstNode, insideComponent: boolean): void => {
      const info = componentLikeInfo(node);
      const isComponent = !!info && (/^[A-Z]/.test(info.name) || functionReturnsJSX(info.node));
      if (isComponent && info) {
        if (insideComponent) {
          hits.push({
            ...locStart(info.node),
            message: `Component '${info.name}' is defined inside another component — move it to module scope`,
          });
        }
        walkChildren(node, (child) => visit(child, true));
        return;
      }
      walkChildren(node, (child) => visit(child, insideComponent));
    };
    visit(parsed.ast, false);
    return hits;
  },
};

export const childrenPropChecker: FileChecker = {
  ruleId: "react/components/pass-children-as-jsx-not-as-a-prop-named-children",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "JSXAttribute" && nameOf(member(n, "name")) === "children") {
        hits.push({ ...locStart(n), message: "Prop named 'children' passed explicitly — use JSX children syntax instead" });
      }
    });
    return hits;
  },
};

export const classComponentChecker: FileChecker = {
  ruleId: "react/components/write-new-components-as-function-components-avoid-class-componen",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ClassDeclaration") return;
      if (!isReactComponentSuperClass(member(n, "superClass"))) return;
      const body = member(n, "body");
      const methods = body ? ((body as AstNode & { body?: AstNode[] }).body ?? []) : [];
      const isErrorBoundary = methods.some(
        (m) =>
          m.type === "MethodDefinition" &&
          ["componentDidCatch", "getDerivedStateFromError"].includes(nameOf(member(m, "key")) ?? ""),
      );
      if (isErrorBoundary) return;
      const name = nameOf(member(n, "id")) ?? "<anonymous>";
      hits.push({
        ...locStart(n),
        message: `Class component '${name}' should be a function component — hooks, Suspense, and use() don't work with classes`,
      });
    });
    return hits;
  },
};

// Exported for reuse by sibling react-* builtin modules that need the same
// small JSX-AST helpers without importing across files.
export { nameOf, jsxAttrs, findAttr, elementName, nonWhitespaceChildren, functionReturnsJSX, componentLikeInfo };
