/**
 * React JSX-specific, i18n, safety, provider-setup, and architecture domain
 * checkers.
 *
 * Covers `rules/react/jsx.md`, `rules/react/i18n.md`, `rules/react/safety.md`,
 * `rules/react/setup.md`, and `rules/react/architecture.md`.
 */
import { readFile } from "node:fs/promises";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { requireParsed, isCheckHitArray, member, isJsxCapableFile } from "./shared.js";
import { walkAst, locStart, parseSource, type AstNode } from "../../utils/parse.js";
import { nameOf, jsxAttrs, findAttr, elementName, nonWhitespaceChildren } from "./react-components.js";

const appliesToJsx = isJsxCapableFile;

function attrLiteralString(attr: AstNode | undefined): string | undefined {
  if (!attr) return undefined;
  const value = member(attr, "value");
  if (!value) return undefined;
  if (value.type === "Literal") {
    const v = (value as AstNode & { value: unknown }).value;
    return typeof v === "string" ? v : undefined;
  }
  if (value.type === "JSXExpressionContainer") {
    const expr = member(value, "expression");
    if (expr?.type === "Literal") {
      const v = (expr as AstNode & { value: unknown }).value;
      return typeof v === "string" ? v : undefined;
    }
  }
  return undefined;
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

/**
 * Simplified per spec: this single implementation is the target for THREE
 * rule ids — `react/jsx/sanitize-before-dangerouslysetinnerhtml-or-skip-it-
 * entirely`, `security/owasp-web/sanitize-all-user-controlled-html-before-
 * render`, and `appbuilder/admin-ui-sdk/security/never-use-dangerously
 * setinnerhtml-in-admin-ui-sdk-spas`. The admin-ui-sdk rule says "never use"
 * regardless of sanitization, so rather than special-casing sanitized usage
 * (which would satisfy the other two rules but not that one), this always
 * flags any `dangerouslySetInnerHTML` — "skip it entirely" is always a valid
 * fix for all three rules' Bad examples.
 */
export const sanitizeDangerouslySetInnerHtmlChecker: FileChecker = {
  ruleId: "react/jsx/sanitize-before-dangerouslysetinnerhtml-or-skip-it-entirely",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "JSXAttribute" && nameOf(member(n, "name")) === "dangerouslySetInnerHTML") {
        hits.push({
          ...locStart(n),
          message: "dangerouslySetInnerHTML bypasses React's escaping — sanitize with DOMPurify or avoid it entirely",
        });
      }
    });
    return hits;
  },
};

export const unnecessaryWrappingDivChecker: FileChecker = {
  ruleId: "react/jsx/prefer-fragment-shorthand-over-wrapping-div-when-no-styling-need",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ReturnStatement") return;
      const arg = member(n, "argument");
      if (!arg || arg.type !== "JSXElement") return;
      if (elementName(arg) !== "div") return;
      if (jsxAttrs(arg).length > 0) return;
      hits.push({ ...locStart(arg), message: "Wrapping <div> with no attributes and no styling need — use a Fragment (<>...</>) instead" });
    });
    return hits;
  },
};

export const targetBlankRelChecker: FileChecker = {
  ruleId: "react/jsx/target-blank-must-include-rel-noopener-noreferrer",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      if (elementName(n) !== "a") return;
      if (attrLiteralString(findAttr(n, "target")) !== "_blank") return;
      const rel = attrLiteralString(findAttr(n, "rel")) ?? "";
      if (rel.includes("noopener") && rel.includes("noreferrer")) return;
      hits.push({ ...locStart(n), message: '<a target="_blank"> is missing rel="noopener noreferrer" — tabnabbing risk' });
    });
    return hits;
  },
};

export const singleChildFragmentChecker: FileChecker = {
  ruleId: "react/jsx/no-useless-fragments-with-one-child",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXFragment") return;
      if (nonWhitespaceChildren(n).length === 1) {
        hits.push({ ...locStart(n), message: "Fragment wraps a single child — remove the fragment or add more siblings" });
      }
    });
    return hits;
  },
};

export const nonSelfClosingEmptyTagChecker: FileChecker = {
  ruleId: "react/jsx/self-close-tags-with-no-children",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      const opening = member(n, "openingElement");
      const selfClosing = opening ? Boolean((opening as AstNode & { selfClosing?: boolean }).selfClosing) : true;
      if (selfClosing) return;
      if (nonWhitespaceChildren(n).length === 0) {
        hits.push({ ...locStart(n), message: `<${elementName(n) ?? "element"}> has no children — self-close it` });
      }
    });
    return hits;
  },
};

export const buttonMissingTypeChecker: FileChecker = {
  ruleId: "react/jsx/every-button-needs-an-explicit-type-attribute",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      if (elementName(n) !== "button") return;
      if (findAttr(n, "type")) return;
      hits.push({ ...locStart(n), message: '<button> has no explicit type attribute — defaults to type="submit"' });
    });
    return hits;
  },
};

const MUTATING_METHODS = new Set(["push", "pop", "shift", "unshift", "splice", "sort", "reverse"]);

export const directStateMutationChecker: FileChecker = {
  ruleId: "react/jsx/never-mutate-state-directly-use-the-setter",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const stateVars = new Set<string>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const init = member(n, "init");
      if (init?.type !== "CallExpression" || nameOf(member(init, "callee")) !== "useState") return;
      const id = member(n, "id");
      if (id?.type !== "ArrayPattern") return;
      const elements = (id as AstNode & { elements?: Array<AstNode | null> }).elements ?? [];
      const stateEl = elements[0];
      const name = stateEl ? nameOf(stateEl) : undefined;
      if (name) stateVars.add(name);
    });
    if (stateVars.size === 0) return [];
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee?.type === "MemberExpression") {
          const objName = nameOf(member(callee, "object"));
          const propName = nameOf(member(callee, "property"));
          if (objName && stateVars.has(objName) && propName && MUTATING_METHODS.has(propName)) {
            hits.push({ ...locStart(n), message: `Mutating state variable '${objName}' directly with .${propName}() — use the setter with a new reference` });
          }
        }
      }
      if (n.type === "AssignmentExpression") {
        const left = member(n, "left");
        if (left?.type === "MemberExpression") {
          const objName = nameOf(member(left, "object"));
          if (objName && stateVars.has(objName)) {
            const propName = nameOf(member(left, "property")) ?? "?";
            hits.push({ ...locStart(n), message: `Direct assignment to '${objName}.${propName}' bypasses React state — use the setter` });
          }
        }
      }
    });
    return hits;
  },
};

const VOID_ELEMENTS = new Set(["img", "br", "input", "hr", "meta", "link", "area", "base", "col", "embed", "source", "track", "wbr"]);

export const voidElementChildrenChecker: FileChecker = {
  ruleId: "react/jsx/void-dom-elements-img-br-input-cannot-have-children",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      const name = elementName(n);
      if (!name || !VOID_ELEMENTS.has(name)) return;
      if (nonWhitespaceChildren(n).length > 0) {
        hits.push({ ...locStart(n), message: `<${name}> is a void element and cannot have children` });
      }
    });
    return hits;
  },
};

const SKIP_ATTRS = new Set(["data-testid", "className", "id", "htmlFor", "name", "type", "key", "role"]);
const CHECK_ATTRS = new Set(["aria-label", "title", "placeholder"]);

export const hardcodedUiStringChecker: FileChecker = {
  ruleId: "react/i18n/never-hardcode-ui-strings-all-visible-text-must-be-externalized-",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "JSXText") {
        const trimmed = (n as AstNode & { value: string }).value.trim();
        if (trimmed.length > 1) {
          hits.push({ ...locStart(n), message: `Hardcoded UI text "${trimmed}" — externalize via a message catalog / i18n hook` });
        }
        return;
      }
      if (n.type === "JSXAttribute") {
        const name = nameOf(member(n, "name"));
        if (!name || SKIP_ATTRS.has(name) || !CHECK_ATTRS.has(name)) return;
        const value = member(n, "value");
        if (value?.type === "Literal" && typeof (value as AstNode & { value: unknown }).value === "string") {
          hits.push({ ...locStart(n), message: `Hardcoded string on '${name}' — externalize via a message catalog / i18n hook` });
        }
      }
    });
    return hits;
  },
};

export const routeNoErrorBoundaryChecker: FileChecker = {
  ruleId: "react/safety/wrap-routes-in-error-boundaries-uncaught-errors-blank-the-page",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    let hasErrorBoundary = false;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      const name = elementName(n);
      if (name && /ErrorBoundary/i.test(name)) hasErrorBoundary = true;
    });
    // A single global boundary (e.g. wrapping the whole router) satisfies
    // every route — to avoid over-flagging, treat ANY ErrorBoundary found
    // anywhere in the file as satisfying every <Route> in it.
    if (hasErrorBoundary) return [];
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      if (elementName(n) !== "Route") return;
      if (findAttr(n, "element") || findAttr(n, "component")) {
        hits.push({ ...locStart(n), message: "<Route> has no Error Boundary ancestor — wrap it so a render error doesn't blank the whole app" });
      }
    });
    return hits;
  },
};

export const iframeNoSandboxChecker: FileChecker = {
  ruleId: "react/safety/iframes-must-specify-a-sandbox-attribute",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      if (elementName(n) !== "iframe") return;
      if (findAttr(n, "sandbox")) return;
      hits.push({ ...locStart(n), message: "<iframe> has no sandbox attribute — an unsandboxed iframe can run arbitrary JS and navigate the parent" });
    });
    return hits;
  },
};

export const noSpectrumProviderChecker: RepoChecker = {
  ruleId: "react/setup/wrap-the-app-in-a-spectrum-provider-with-theme-locale",
  kind: "repo",
  async check(ctx) {
    // Only meaningful for a React UI. Skip repos with no React entry at all
    // (e.g. backend-only App Builder actions or plain Node CLIs) — otherwise
    // this fires on any `index.js`/`main.js` that happens to exist.
    const candidates = ctx.allFiles.filter((f) => /\/(index|app|main)\.(jsx|tsx|js|mjs|cjs)$/i.test(f));
    for (const entry of candidates) {
      let content: string;
      try {
        content = await readFile(entry, "utf8");
      } catch {
        continue;
      }
      // A real React app entry imports React / Spectrum or renders JSX.
      const importsReact =
        /\bfrom\s+['"](react(?:-dom(?:\/client)?)?|@adobe\/react-spectrum)['"]/.test(content) ||
        /require\(\s*['"](react(?:-dom(?:\/client)?)?|@adobe\/react-spectrum)['"]\s*\)/.test(content) ||
        /ReactDOM\.|createRoot\s*\(|\brender\s*\(\s*</.test(content);
      const parsed = parseSource(content, entry);
      if (!parsed) return [{ message: "File could not be parsed — fix syntax errors before running AST checks", path: entry, line: 1 }];
      let hasJsx = false;
      let hasProviderWithThemeAndLocale = false;
      walkAst(parsed.ast, (n) => {
        if (n.type === "JSXElement") {
          hasJsx = true;
          if (elementName(n) === "Provider" && findAttr(n, "theme") && findAttr(n, "locale")) {
            hasProviderWithThemeAndLocale = true;
          }
        }
      });
      // Skip non-React entries (backend-only App Builder actions, plain Node
      // CLIs) — they have neither a React/Spectrum import nor any JSX.
      if (!importsReact && !hasJsx) continue;
      if (hasProviderWithThemeAndLocale) return [];
      return [
        {
          message: `React app entry '${entry}' has no <Provider theme=... locale=...> ancestor — Spectrum components need a Provider ancestor`,
          path: entry,
          line: 1,
        },
      ];
    }
    return [];
  },
};

/**
 * Covers BOTH "prefer React Spectrum components over hand-rolled UI" and
 * "build custom components with React Aria / React Stately hooks" — both
 * boil down to the same "you rolled your own div-based widget instead of
 * using Adobe's component/hook libraries" pattern.
 */
export const handRolledUiIdiomChecker: FileChecker = {
  ruleId: "react/setup/prefer-react-spectrum-components-over-hand-rolled-ui",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      const name = elementName(n);
      if (name === "div" || name === "span") {
        const classAttr = attrLiteralString(findAttr(n, "className"));
        if (findAttr(n, "onClick") && classAttr && /btn|button/i.test(classAttr)) {
          hits.push({ ...locStart(n), message: `Hand-rolled clickable <${name} className="${classAttr}"> — use a Spectrum <Button> instead` });
        }
        if (name === "div" && attrLiteralString(findAttr(n, "role")) === "dialog") {
          hits.push({ ...locStart(n), message: 'Hand-rolled <div role="dialog"> — use Spectrum\'s Dialog or React Aria\'s useDialog instead' });
        }
      }
      if (name === "input" && attrLiteralString(findAttr(n, "type")) === "checkbox") {
        if (findAttr(n, "className") || findAttr(n, "style")) {
          hits.push({ ...locStart(n), message: 'Styled raw <input type="checkbox"> — use Spectrum\'s Checkbox or React Aria\'s useCheckbox instead' });
        }
      }
    });
    return hits;
  },
};

export const noExcAppImportChecker: FileChecker = {
  ruleId: "react/setup/handle-unified-shell-iframe-constraints-resize-csp-and-shell-api",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const hasExcAppImport = /from\s+['"]@adobe\/exc-app['"]|require\(\s*['"]@adobe\/exc-app['"]\s*\)/.test(ctx.content);
    if (hasExcAppImport) return [];
    const hits: CheckHit[] = [];
    ctx.content.split("\n").forEach((line, idx) => {
      if (/window\.parent\.postMessage\s*\(/.test(line) || /document\.body\.style\.height\s*=/.test(line)) {
        hits.push({ line: idx + 1, message: "Direct postMessage/body-height manipulation without @adobe/exc-app — use the shell runtime API for resize/navigation" });
      }
    });
    return hits;
  },
};

/**
 * Approximate, single-file only: true prop drilling often spans multiple
 * files (a prop threaded through several separately-defined components), but
 * without a project-wide call graph this checker can only see components
 * defined in the SAME file. It flags a prop name passed unchanged
 * (`x={x}` shorthand-equivalent) into 3+ distinct JSX call sites within one
 * file, when the file has no `useContext(` call anywhere.
 */
export const propDrillingChecker: FileChecker = {
  ruleId: "react/architecture/avoid-prop-drilling-beyond-two-levels-use-context-or-a-state-lib",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    let hasUseContext = false;
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression" && nameOf(member(n, "callee")) === "useContext") hasUseContext = true;
    });
    if (hasUseContext) return [];
    const propSites = new Map<string, Set<AstNode>>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      for (const attr of jsxAttrs(n)) {
        if (attr.type !== "JSXAttribute") continue;
        const attrName = nameOf(member(attr, "name"));
        if (!attrName) continue;
        const value = member(attr, "value");
        if (value?.type !== "JSXExpressionContainer") continue;
        const expr = member(value, "expression");
        if (expr?.type !== "Identifier" || nameOf(expr) !== attrName) continue;
        if (!propSites.has(attrName)) propSites.set(attrName, new Set());
        propSites.get(attrName)?.add(n);
      }
    });
    const hits: CheckHit[] = [];
    for (const [propName, sites] of propSites) {
      if (sites.size >= 3) {
        const first = [...sites][0];
        hits.push({
          ...(first ? locStart(first) : {}),
          message: `Prop '${propName}' is passed unchanged through ${sites.size} components in this file — lift it to Context or a state library (single-file heuristic; cross-file drilling isn't tracked)`,
        });
      }
    }
    return hits;
  },
};

export const handRolledShellLayoutChecker: FileChecker = {
  ruleId: "react/architecture/check-quarry-for-higher-order-experience-cloud-layout-components",
  kind: "file",
  appliesTo: appliesToJsx,
  check(ctx) {
    if (/@quarry\/react/.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      const info = componentFunctionInfo(n);
      if (!info || !/Shell|Layout|Nav/i.test(info.name)) return;
      let hasNav = false;
      let hasMain = false;
      walkAst(info.node, (inner) => {
        if (inner.type !== "JSXElement") return;
        const role = attrLiteralString(findAttr(inner, "role"));
        if (role === "navigation") hasNav = true;
        if (role === "main") hasMain = true;
      });
      if (hasNav && hasMain) {
        hits.push({
          ...locStart(info.node),
          message: `Component '${info.name}' hand-rolls a shell layout (role="navigation" + role="main") — check Quarry (@quarry/react) for an existing higher-order layout component first`,
        });
      }
    });
    return hits;
  },
};
