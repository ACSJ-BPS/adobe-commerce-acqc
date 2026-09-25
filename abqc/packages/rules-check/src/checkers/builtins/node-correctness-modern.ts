import type { CheckHit, CheckContext, FileChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import { walkAst, findFunctions, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

const FUNCTION_TYPES = new Set(["FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression"]);

function callArgs(n: AstNode): AstNode[] {
  return (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
}

/**
 * Walks `root`'s subtree looking for a node of `type`, without descending
 * into a NESTED function's body. `root` itself is always fully searched even
 * if it happens to be a function.
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

const VALID_TYPEOF = new Set(["undefined", "object", "boolean", "number", "string", "function", "symbol", "bigint"]);

/**
 * `node/correctness/use-isnan-and-valid-typeof-comparisons`
 */
export const isnanTypeofChecker: FileChecker = {
  ruleId: "node/correctness/use-isnan-and-valid-typeof-comparisons",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "BinaryExpression") return;
      const op = (n as AstNode & { operator?: string }).operator;
      if (op !== "===" && op !== "!==") return;
      const left = member(n, "left");
      const right = member(n, "right");
      if (identName(left) === "NaN" || identName(right) === "NaN") {
        hits.push({ ...locStart(n), message: "Use Number.isNaN() instead of comparing directly to NaN" });
        return;
      }
      const leftIsTypeof = !!left && left.type === "UnaryExpression" && (left as AstNode & { operator?: string }).operator === "typeof";
      const rightIsTypeof = !!right && right.type === "UnaryExpression" && (right as AstNode & { operator?: string }).operator === "typeof";
      const literalSide = leftIsTypeof ? right : rightIsTypeof ? left : undefined;
      if ((leftIsTypeof || rightIsTypeof) && literalSide && literalSide.type === "Literal") {
        const val = (literalSide as AstNode & { value?: unknown }).value;
        if (typeof val === "string" && !VALID_TYPEOF.has(val)) {
          hits.push({ ...locStart(n), message: `Invalid typeof comparison target '${val}'` });
        }
      }
    });
    return hits;
  },
};

const ARRAY_CALLBACK_METHODS = new Set(["map", "filter", "reduce", "find", "some", "every", "flatMap", "sort"]);

/**
 * `node/correctness/array-callbacks-must-return-array-callback-return`
 */
export const arrayCallbackReturnChecker: FileChecker = {
  ruleId: "node/correctness/array-callbacks-must-return-array-callback-return",
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
      const propName = identName(member(callee, "property"));
      if (!propName || !ARRAY_CALLBACK_METHODS.has(propName)) return;
      const cb = callArgs(n)[0];
      if (!cb || (cb.type !== "ArrowFunctionExpression" && cb.type !== "FunctionExpression")) return;
      const body = member(cb, "body");
      if (!body || body.type !== "BlockStatement") return;
      if (!hasOwnNodeType(body, "ReturnStatement")) {
        hits.push({ ...locStart(cb), message: `Callback passed to .${propName}() has a block body but never returns a value` });
      }
    });
    return hits;
  },
};

/**
 * `node/correctness/no-variable-shadowing-across-scopes`
 *
 * Best-effort, not a real scope-chain implementation: detects an inner
 * function's parameter (or a nested variable declaration) reusing the name
 * of a parameter from an enclosing function found via `findFunctions`.
 */
export const noShadowChecker: FileChecker = {
  ruleId: "node/correctness/no-variable-shadowing-across-scopes",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const outer of findFunctions(parsed.ast)) {
      const outerParamNames = new Set(
        outer.node.params.map((p) => (p.type === "Identifier" ? identName(p) : undefined)).filter((x): x is string => !!x),
      );
      if (outerParamNames.size === 0 || !outer.node.body) continue;
      walkAst(outer.node.body, (n) => {
        if (n === outer.node) return;
        if (n.type === "FunctionDeclaration" || n.type === "FunctionExpression" || n.type === "ArrowFunctionExpression") {
          const innerParams = ((n as AstNode & { params?: AstNode[] }).params ?? [])
            .map((p) => (p.type === "Identifier" ? identName(p) : undefined))
            .filter((x): x is string => !!x);
          for (const pn of innerParams) {
            if (outerParamNames.has(pn)) {
              hits.push({ ...locStart(n), message: `Parameter '${pn}' shadows a parameter of the same name in an enclosing function` });
            }
          }
        }
        if (n.type === "VariableDeclarator") {
          const id = member(n, "id");
          const name = identName(id);
          if (name && outerParamNames.has(name)) {
            hits.push({ ...locStart(n), message: `Variable '${name}' shadows a parameter of the same name in an enclosing function` });
          }
        }
      });
    }
    return hits;
  },
};

const FS_CALLBACK_METHODS = new Set([
  "readFile", "writeFile", "readdir", "stat", "mkdir", "rmdir", "unlink",
  "rename", "copyFile", "appendFile", "readlink", "access", "chmod", "chown",
]);

/**
 * `node/modern-apis/prefer-node-fs-promises-over-callback-fs`
 */
export const callbackFsChecker: FileChecker = {
  ruleId: "node/modern-apis/prefer-node-fs-promises-over-callback-fs",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    if (/require\(\s*['"](?:node:)?fs\/promises['"]\s*\)/.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!callee || callee.type !== "MemberExpression") return;
      const objName = identName(member(callee, "object"));
      const propName = identName(member(callee, "property"));
      if (objName !== "fs" || !propName || propName.endsWith("Sync") || !FS_CALLBACK_METHODS.has(propName)) return;
      const args = callArgs(n);
      const last = args[args.length - 1];
      if (last && (last.type === "ArrowFunctionExpression" || last.type === "FunctionExpression")) {
        hits.push({ ...locStart(n), message: `fs.${propName}() uses a callback — prefer require('node:fs/promises') and async/await` });
      }
    });
    return hits;
  },
};

/**
 * `node/modern-apis/no-deprecated-node-js-apis`
 */
export const deprecatedNodeApiChecker: FileChecker = {
  ruleId: "node/modern-apis/no-deprecated-node-js-apis",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "NewExpression") {
        const callee = member(n, "callee");
        if (identName(callee) === "Buffer") {
          hits.push({ ...locStart(n), message: "new Buffer() is deprecated — use Buffer.from()/Buffer.alloc()" });
        }
      }
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee && callee.type === "MemberExpression") {
          const objName = identName(member(callee, "object"));
          const propName = identName(member(callee, "property"));
          if (objName === "url" && propName === "parse") {
            hits.push({ ...locStart(n), message: "url.parse() is deprecated — use the WHATWG URL API (new URL())" });
          }
          if (objName === "crypto" && propName === "createCipher") {
            hits.push({ ...locStart(n), message: "crypto.createCipher() is deprecated/insecure — use crypto.createCipheriv()" });
          }
          if (objName === "domain" && propName === "create") {
            hits.push({ ...locStart(n), message: "domain.create() is deprecated — use async/await + try/catch or AsyncLocalStorage" });
          }
        }
        if (identName(callee) === "require") {
          const arg = callArgs(n)[0];
          if (arg && arg.type === "Literal") {
            const val = (arg as AstNode & { value?: unknown }).value;
            if (val === "punycode" || val === "node:punycode") {
              hits.push({ ...locStart(n), message: "require('punycode') is deprecated — use a userland punycode package" });
            }
          }
        }
      }
    });
    return hits;
  },
};

const GLOBAL_MODULE_MAP: Record<string, string> = {
  "node:url": "URL",
  "node:buffer": "Buffer",
  "node:process": "process",
};

/**
 * `node/modern-apis/prefer-global-url-buffer-process-don-t-import-from-node`
 */
export const redundantGlobalImportChecker: FileChecker = {
  ruleId: "node/modern-apis/prefer-global-url-buffer-process-don-t-import-from-node",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "ImportDeclaration") {
        const src = member(n, "source");
        const specifier = src && src.type === "Literal" ? String((src as AstNode & { value?: unknown }).value) : undefined;
        const expected = specifier ? GLOBAL_MODULE_MAP[specifier] : undefined;
        if (!expected) return;
        const specifiers = (n as AstNode & { specifiers?: AstNode[] }).specifiers ?? [];
        const importSpecifiers = specifiers.filter((s) => s.type === "ImportSpecifier");
        const names = importSpecifiers.map((s) => identName(member(s, "imported")));
        if (importSpecifiers.length === specifiers.length && names.length > 0 && names.every((nm) => nm === expected)) {
          hits.push({ ...locStart(n), message: `Import '${expected}' from '${specifier}' is redundant — it's available as a global` });
        }
      }
      if (n.type === "VariableDeclarator") {
        const init = member(n, "init");
        if (!init || init.type !== "CallExpression" || identName(member(init, "callee")) !== "require") return;
        const arg = callArgs(init)[0];
        const specifier = arg && arg.type === "Literal" ? String((arg as AstNode & { value?: unknown }).value) : undefined;
        const expected = specifier ? GLOBAL_MODULE_MAP[specifier] : undefined;
        if (!expected) return;
        const id = member(n, "id");
        if (!id || id.type !== "ObjectPattern") return;
        const props = (id as AstNode & { properties?: AstNode[] }).properties ?? [];
        const names = props.map((p) => identName(member(p, "key")));
        if (names.length > 0 && names.every((nm) => nm === expected)) {
          hits.push({ ...locStart(n), message: `Destructuring '${expected}' from require('${specifier}') is redundant — it's available as a global` });
        }
      }
    });
    return hits;
  },
};

/**
 * `node/naming/naming-camelcase-locals-pascalcase-classes-upper-snake-constants`
 * Also serves `typescript/modern/naming-pascalcase-types-camelcase-values-upper-for-enum-constant`.
 *
 * Note: the TS-specific facets (TSInterfaceDeclaration/TSTypeAliasDeclaration
 * PascalCase, TSEnumDeclaration member UPPER_SNAKE_CASE) are implemented below;
 * everything else is a JS-only proxy for the fuller naming-convention rule.
 */
export const namingConventionChecker: FileChecker = {
  ruleId: "node/naming/naming-camelcase-locals-pascalcase-classes-upper-snake-constants",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const pascalRe = /^[A-Z][a-zA-Z0-9]*$/;
    const upperSnakeRe = /^[A-Z][A-Z0-9_]*$/;
    const camelParamRe = /^_?[a-z][a-zA-Z0-9]*$/;

    walkAst(parsed.ast, (n) => {
      if (n.type === "ClassDeclaration") {
        const id = member(n, "id");
        const name = identName(id);
        if (name && !pascalRe.test(name)) {
          hits.push({ ...locStart(n), message: `Class '${name}' should be PascalCase` });
        }
      }
      if (n.type === "TSInterfaceDeclaration" || n.type === "TSTypeAliasDeclaration") {
        const id = member(n, "id");
        const name = identName(id);
        if (name && !pascalRe.test(name)) {
          hits.push({ ...locStart(n), message: `Type '${name}' should be PascalCase` });
        }
      }
      if (n.type === "TSEnumDeclaration") {
        const members = (n as AstNode & { members?: AstNode[] }).members ?? [];
        for (const enumMember of members) {
          const id = member(enumMember, "id");
          const name = id ? (id.type === "Identifier" ? identName(id) : id.type === "Literal" ? String((id as AstNode & { value?: unknown }).value) : undefined) : undefined;
          if (name && !upperSnakeRe.test(name)) {
            hits.push({ ...locStart(enumMember), message: `Enum member '${name}' should be UPPER_SNAKE_CASE` });
          }
        }
      }
    });

    // Test files are full of one-off local literal assignments (fixture
    // values, expected results) that are never meant to be a shared,
    // SCREAMING_CASE "constant" — only real source enforces the convention.
    const isTestFile = /(?:^|[/\\])(?:__tests__|__mocks__)[/\\]|\.(?:test|spec)\.[jt]sx?$/i.test(ctx.filePath);
    if (!isTestFile) {
      const programBody = (parsed.ast as AstNode & { body?: AstNode[] }).body ?? [];
      for (const stmt of programBody) {
        if (stmt.type !== "VariableDeclaration" || (stmt as AstNode & { kind?: string }).kind !== "const") continue;
        const decls = (stmt as AstNode & { declarations?: AstNode[] }).declarations ?? [];
        for (const d of decls) {
          const id = member(d, "id");
          const init = member(d, "init");
          if (!id || id.type !== "Identifier" || !init || init.type !== "Literal") continue;
          const val = (init as AstNode & { value?: unknown }).value;
          if (typeof val !== "string" && typeof val !== "number" && typeof val !== "boolean") continue;
          const name = identName(id) ?? "";
          if (!upperSnakeRe.test(name)) {
            hits.push({ ...locStart(d), message: `Top-level constant '${name}' should be UPPER_SNAKE_CASE` });
          }
        }
      }
    }

    for (const { node } of findFunctions(parsed.ast)) {
      for (const p of node.params) {
        if (p.type !== "Identifier") continue;
        const name = identName(p) ?? "";
        // A bare `_` is the universal "intentionally unused parameter"
        // convention — not a camelCase violation, even though it has no
        // letter after the underscore for `camelParamRe` to match.
        if (name === "_") continue;
        if (!camelParamRe.test(name)) {
          hits.push({ ...locStart(p), message: `Parameter '${name}' should be camelCase` });
        }
      }
    }
    return hits;
  },
};

/**
 * `node/naming/prefer-named-exports-for-utilities-default-exports-for-pages-com`
 * Also serves `imports/exports/prefer-named-exports-for-utilities-default-exports-for-the-one-t`.
 */
export const exportStyleByPathChecker: FileChecker = {
  ruleId: "node/naming/prefer-named-exports-for-utilities-default-exports-for-pages-com",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const isUtilPath = /\/(utils|lib|helpers)\//i.test(ctx.filePath);
    // Any `.tsx`/`.jsx` file was treated as "a page", regardless of what it
    // actually contains — mislabeling context modules, hooks, and test
    // utilities. A `pages`/`routes`/`components` directory convention is a
    // far more specific, still-static proxy for "this is a page/screen".
    const isPagePath = /\/(pages|routes|components)\//i.test(ctx.filePath);
    if (!isUtilPath && !isPagePath) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const body = (parsed.ast as AstNode & { body?: AstNode[] }).body ?? [];
    let hasDefaultExport = false;
    let defaultExportIsFunction = false;
    let defaultExportNode: AstNode | undefined;
    let namedExportCount = 0;

    for (const stmt of body) {
      if (stmt.type === "ExportDefaultDeclaration") {
        hasDefaultExport = true;
        defaultExportNode = stmt;
        const decl = member(stmt, "declaration");
        if (decl && (decl.type === "FunctionDeclaration" || decl.type === "ArrowFunctionExpression" || decl.type === "FunctionExpression")) {
          defaultExportIsFunction = true;
        }
      }
      if (stmt.type === "ExportNamedDeclaration") {
        const decl = member(stmt, "declaration");
        if (decl && decl.type === "VariableDeclaration") {
          namedExportCount += ((decl as AstNode & { declarations?: AstNode[] }).declarations ?? []).length;
        } else if (decl && decl.type === "FunctionDeclaration") {
          namedExportCount += 1;
        }
      }
      if (stmt.type === "ExpressionStatement") {
        const expr = member(stmt, "expression");
        if (expr && expr.type === "AssignmentExpression") {
          const left = member(expr, "left");
          if (left && left.type === "MemberExpression") {
            const obj = member(left, "object");
            if (identName(obj) === "module" && identName(member(left, "property")) === "exports") {
              const right = member(expr, "right");
              if (right && (right.type === "FunctionExpression" || right.type === "ArrowFunctionExpression")) {
                hasDefaultExport = true;
                defaultExportIsFunction = true;
                defaultExportNode = stmt;
              } else if (right && right.type === "Identifier") {
                hasDefaultExport = true;
                defaultExportNode = stmt;
              }
            }
          }
        }
      }
    }

    if (isUtilPath && hasDefaultExport && defaultExportIsFunction) {
      hits.push({
        ...(defaultExportNode ? locStart(defaultExportNode) : { line: 1 }),
        message: "Utility module uses a single default export — prefer named exports for utils/lib/helpers",
      });
    }
    if (isPagePath && namedExportCount > 1 && !hasDefaultExport) {
      hits.push({ line: 1, message: "Page/component module has multiple named exports and no default export — prefer a default export for pages/components" });
    }
    return hits;
  },
};
