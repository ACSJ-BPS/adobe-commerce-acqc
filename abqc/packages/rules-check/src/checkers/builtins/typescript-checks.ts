import type { CheckHit, CheckContext, RepoContext, FileChecker, RepoChecker } from "../../types.js";
import { readFile } from "node:fs/promises";
import { join, dirname } from "node:path";
import { walkAst, findFunctions, locStart, type AstNode, type FunctionLike } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

// ---------------------------------------------------------------------------
// Shared local helpers (duplicated-on-purpose per file-isolation constraints —
// these are small enough that a shared module isn't worth the extra file).
// ---------------------------------------------------------------------------

/** Names that look like fire-and-forget async operations (network/notify calls). */
const FLOATING_CALL_RE = /^(fetch|axios|invoke|notify|send)/i;

/** Returns the "name" of a call's callee: the identifier name, or the property
 * name for a `.foo(...)` member call — used to pattern-match call sites since
 * there's no type checker to confirm the callee actually returns a Promise. */
function calleeName(call: AstNode | undefined): string | undefined {
  const callee = call ? member(call, "callee") : undefined;
  if (!callee) return undefined;
  if (callee.type === "Identifier") return identName(callee);
  if (callee.type === "MemberExpression") {
    const computed = (callee as AstNode & { computed?: boolean }).computed;
    if (!computed) return identName(member(callee, "property"));
  }
  return undefined;
}

/** Does `returnType` (a function's `.returnType` TSTypeAnnotation, if any) name `Promise<...>`? */
function isPromiseTypeAnnotation(returnType: AstNode | undefined): boolean {
  if (!returnType) return false;
  const inner = member(returnType, "typeAnnotation");
  if (!inner || inner.type !== "TSTypeReference") return false;
  return identName(member(inner, "typeName")) === "Promise";
}

function isNumericLiteral(n: AstNode | undefined): n is AstNode & { value: number } {
  return !!n && n.type === "Literal" && typeof (n as AstNode & { value: unknown }).value === "number";
}

function keyNameOf(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier") return identName(n);
  if (n.type === "Literal") return String((n as AstNode & { value: unknown }).value);
  return undefined;
}

/** Parent map keyed by node, recording the immediate parent + the property
 * name the node was reached through (e.g. "test", "init", "arguments"). Built
 * fresh per-check since these ASTs are small (single file) and this isn't a
 * hot path — good enough for the handful of rules that need ancestor context. */
function buildParentMap(root: AstNode): Map<AstNode, { parent: AstNode; key: string }> {
  const map = new Map<AstNode, { parent: AstNode; key: string }>();
  const visit = (n: AstNode) => {
    for (const key of Object.keys(n)) {
      if (key === "loc" || key === "range" || key === "parent") continue;
      const val = n[key];
      if (!val || typeof val !== "object") continue;
      if (Array.isArray(val)) {
        for (const item of val) {
          if (item && typeof item === "object" && "type" in item) {
            map.set(item as AstNode, { parent: n, key });
            visit(item as AstNode);
          }
        }
      } else if ("type" in val) {
        map.set(val as AstNode, { parent: n, key });
        visit(val as AstNode);
      }
    }
  };
  visit(root);
  return map;
}

// ---------------------------------------------------------------------------
// 1. no-floating-promises (approximation)
// ---------------------------------------------------------------------------

export const tsFloatingPromiseChecker: FileChecker = {
  ruleId: "typescript/async/no-floating-promises-every-promise-must-be-handled",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    // Approximation: no type-checker available in this architecture (no
    // parserOptions.project); this detects syntactic patterns as a proxy, not
    // verified types.
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ExpressionStatement") return;
      const expr = member(n, "expression");
      if (!expr || expr.type !== "CallExpression") return;
      const name = calleeName(expr);
      if (name && FLOATING_CALL_RE.test(name)) {
        hits.push({ ...locStart(n), message: `Floating promise: '${name}(...)' is never awaited or handled with .catch()` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 2. no-misused-promises-in-conditionals-or-loops
// ---------------------------------------------------------------------------

export const tsMisusedPromiseChecker: FileChecker = {
  ruleId: "typescript/async/no-misused-promises-in-conditionals-or-loops",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const asyncFns = new Map<string, FunctionLike>();
    for (const { name, node } of findFunctions(parsed.ast)) {
      if ((node as AstNode & { async?: boolean }).async) asyncFns.set(name, node);
    }
    walkAst(parsed.ast, (n) => {
      if (n.type === "IfStatement") {
        const test = member(n, "test");
        if (test?.type === "CallExpression") {
          const callee = member(test, "callee");
          const name = callee?.type === "Identifier" ? identName(callee) : undefined;
          if (name && asyncFns.has(name)) {
            hits.push({ ...locStart(n), message: `'if (${name}(...))' checks Promise truthiness, not the resolved value — add 'await'` });
          }
        }
      }
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        const computed = callee ? (callee as AstNode & { computed?: boolean }).computed : undefined;
        if (callee?.type === "MemberExpression" && !computed && identName(member(callee, "property")) === "forEach") {
          const args = ((n as AstNode & { arguments?: AstNode[] }).arguments ?? []) as AstNode[];
          const cb = args[0];
          if (cb && (cb.type === "ArrowFunctionExpression" || cb.type === "FunctionExpression") && (cb as AstNode & { async?: boolean }).async) {
            hits.push({ ...locStart(n), message: "'.forEach()' does not wait for its async callback — promises are silently dropped" });
          }
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 3. await-thenable-values-not-non-promises
// ---------------------------------------------------------------------------

export const tsAwaitNonPromiseChecker: FileChecker = {
  ruleId: "typescript/async/await-thenable-values-not-non-promises",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    // Approximation: no type-checker available in this architecture (no
    // parserOptions.project); this only catches the case where the callee's
    // OWN declared return-type annotation is present and clearly non-Promise.
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const fnsByName = new Map<string, FunctionLike>();
    for (const { name, node } of findFunctions(parsed.ast)) fnsByName.set(name, node);
    walkAst(parsed.ast, (n) => {
      if (n.type !== "AwaitExpression") return;
      const arg = member(n, "argument");
      if (arg?.type !== "CallExpression") return;
      const callee = member(arg, "callee");
      if (callee?.type !== "Identifier") return;
      const fn = fnsByName.get(identName(callee) ?? "");
      const returnType = fn ? member(fn, "returnType") : undefined;
      if (fn && returnType && !isPromiseTypeAnnotation(returnType)) {
        hits.push({ ...locStart(n), message: `'await ${identName(callee)}(...)' — declared return type is not Promise<...>` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 4. functions-that-return-promises-should-be-marked-async
// ---------------------------------------------------------------------------

function bodyReturnsThenChain(fn: FunctionLike): boolean {
  const body = fn.body;
  if (!body) return false;
  let found = false;
  walkAst(body, (n) => {
    if (found || n.type !== "ReturnStatement") return;
    const arg = member(n, "argument");
    if (arg?.type !== "CallExpression") return;
    const callee = member(arg, "callee");
    const computed = callee ? (callee as AstNode & { computed?: boolean }).computed : undefined;
    if (callee?.type === "MemberExpression" && !computed && identName(member(callee, "property")) === "then") found = true;
  });
  return found;
}

export const tsUnmarkedAsyncChecker: FileChecker = {
  ruleId: "typescript/async/functions-that-return-promises-should-be-marked-async",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { name, node } of findFunctions(parsed.ast)) {
      if ((node as AstNode & { async?: boolean }).async) continue;
      const returnType = member(node, "returnType");
      if (isPromiseTypeAnnotation(returnType) || bodyReturnsThenChain(node)) {
        hits.push({ ...locStart(node), message: `Function '${name || "(anonymous)"}' returns a Promise but is not marked 'async'` });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 5. type-async-function-return-as-promise-t-not-just-t
// ---------------------------------------------------------------------------

export const asyncReturnTypeNotWrappedChecker: FileChecker = {
  ruleId: "typescript/async/type-async-function-return-as-promise-t-not-just-t",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { name, node } of findFunctions(parsed.ast)) {
      if (!(node as AstNode & { async?: boolean }).async) continue;
      const returnType = member(node, "returnType");
      if (!returnType) continue;
      if (!isPromiseTypeAnnotation(returnType)) {
        hits.push({ ...locStart(node), message: `Async function '${name || "(anonymous)"}' should declare its return type as Promise<T>, not T` });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 6. prefer-interface-over-type-for-object-shapes
// ---------------------------------------------------------------------------

export const typeAliasObjectShapeChecker: FileChecker = {
  ruleId: "typescript/modern/prefer-interface-over-type-for-object-shapes",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "TSTypeAliasDeclaration") return;
      const ann = member(n, "typeAnnotation");
      if (ann?.type === "TSTypeLiteral") {
        hits.push({ ...locStart(n), message: `Type alias '${identName(member(n, "id")) ?? ""}' is an object shape — prefer 'interface'` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 7. mark-class-fields-readonly-when-not-reassigned
// ---------------------------------------------------------------------------

export const nonReadonlyFieldChecker: FileChecker = {
  ruleId: "typescript/modern/mark-class-fields-readonly-when-not-reassigned",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ClassDeclaration" && n.type !== "ClassExpression") return;
      const body = member(n, "body");
      const members = ((body as AstNode & { body?: AstNode[] })?.body ?? []) as AstNode[];
      const ctor = members.find(
        (m) => m.type === "MethodDefinition" && (m as AstNode & { kind?: string }).kind === "constructor",
      );
      for (const m of members) {
        if (m.type !== "PropertyDefinition") continue;
        if ((m as AstNode & { readonly?: boolean }).readonly) continue;
        if ((m as AstNode & { computed?: boolean }).computed) continue;
        const fieldName = keyNameOf(member(m, "key"));
        if (!fieldName) continue;
        let assignedOutsideCtor = false;
        for (const method of members) {
          if (method === ctor) continue;
          if (method.type !== "MethodDefinition") continue;
          walkAst(method, (inner) => {
            if (assignedOutsideCtor || inner.type !== "AssignmentExpression") return;
            const left = member(inner, "left");
            if (left?.type !== "MemberExpression") return;
            const obj = member(left, "object");
            const computed = (left as AstNode & { computed?: boolean }).computed;
            if (obj?.type === "ThisExpression" && !computed && identName(member(left, "property")) === fieldName) {
              assignedOutsideCtor = true;
            }
          });
        }
        if (!assignedOutsideCtor) {
          hits.push({ ...locStart(m), message: `Field '${fieldName}' is never reassigned outside the constructor — mark it 'readonly'` });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 8. avoid-enum-prefer-const-asserted-union
// ---------------------------------------------------------------------------

export const tsEnumDeclarationChecker: FileChecker = {
  ruleId: "typescript/modern/avoid-enum-prefer-const-asserted-union",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "TSEnumDeclaration") {
        hits.push({ ...locStart(n), message: `enum '${identName(member(n, "id")) ?? ""}' — prefer a const-asserted union of string literals` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 9. prefer-satisfies-over-type-annotation-for-literal-config
// ---------------------------------------------------------------------------

export const preferSatisfiesChecker: FileChecker = {
  ruleId: "typescript/modern/prefer-satisfies-over-type-annotation-for-literal-config",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    // Approximation: no type-checker available in this architecture (no
    // parserOptions.project); this can't verify the annotation actually
    // widens the literal, it only flags the syntactic shape.
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const id = member(n, "id");
      const init = member(n, "init");
      const typeAnn = id ? member(id, "typeAnnotation") : undefined;
      if (typeAnn?.type === "TSTypeAnnotation" && init?.type === "ObjectExpression") {
        hits.push({ ...locStart(n), message: `'${identName(id) ?? ""}: T = {...}' widens the literal — prefer '{...} satisfies T'` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 10. use-unified-function-signatures-avoid-overload-duplication
// ---------------------------------------------------------------------------

function scanOverloadDuplication(stmts: AstNode[], hits: CheckHit[]): void {
  for (let i = 1; i < stmts.length; i++) {
    const prev = stmts[i - 1];
    const cur = stmts[i];
    if (!prev || !cur) continue;
    if (prev.type === "TSDeclareFunction" && cur.type === "TSDeclareFunction") {
      const prevName = identName(member(prev, "id"));
      const curName = identName(member(cur, "id"));
      if (prevName && prevName === curName) {
        hits.push({ ...locStart(cur), message: `Overload signatures for '${curName}' — consider a single unified signature with optional/union params` });
      }
    }
  }
}

export const overloadDuplicationChecker: FileChecker = {
  // Simplification: only flags consecutive top-level/block-level
  // `TSDeclareFunction` overload signatures with an identical name — does not
  // attempt full cross-scope overload-set resolution.
  ruleId: "typescript/modern/use-unified-function-signatures-avoid-overload-duplication",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const body = (parsed.ast as AstNode & { body?: AstNode[] }).body ?? [];
    scanOverloadDuplication(body, hits);
    walkAst(parsed.ast, (n) => {
      if (n.type === "BlockStatement") {
        scanOverloadDuplication((n as AstNode & { body?: AstNode[] }).body ?? [], hits);
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 11. switch-statements-must-be-exhaustive-over-discriminated-unions
// ---------------------------------------------------------------------------

export const switchExhaustivenessChecker: FileChecker = {
  ruleId: "typescript/modern/switch-statements-must-be-exhaustive-over-discriminated-unions",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    // Approximation: no type-checker available in this architecture (no
    // parserOptions.project); can't verify TRUE exhaustiveness over a
    // discriminated union — only checks for the absence of a `default:` case
    // on a switch that looks like it discriminates on `.kind`/`.type`.
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "SwitchStatement") return;
      const discriminant = member(n, "discriminant");
      const computed = discriminant ? (discriminant as AstNode & { computed?: boolean }).computed : undefined;
      if (discriminant?.type !== "MemberExpression" || computed) return;
      const prop = identName(member(discriminant, "property"));
      if (prop !== "kind" && prop !== "type") return;
      const cases = ((n as AstNode & { cases?: AstNode[] }).cases ?? []) as AstNode[];
      const hasDefault = cases.some((c) => member(c, "test") === undefined);
      if (!hasDefault) {
        hits.push({ ...locStart(n), message: `switch on '.${prop}' has no 'default' case — add one or verify exhaustiveness explicitly` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 13. no-confusing-void-expressions (simplified)
// ---------------------------------------------------------------------------

export const confusingVoidChecker: FileChecker = {
  // Simplified per spec: only flags an implicit-return arrow whose body is a
  // bare console.log/console.error call, not the full "value expected" analysis.
  ruleId: "typescript/style/no-confusing-void-expressions",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ArrowFunctionExpression") return;
      const body = member(n, "body");
      if (!body || body.type === "BlockStatement" || body.type !== "CallExpression") return;
      const callee = member(body, "callee");
      const computed = callee ? (callee as AstNode & { computed?: boolean }).computed : undefined;
      if (callee?.type !== "MemberExpression" || computed) return;
      const obj = member(callee, "object");
      const prop = identName(member(callee, "property"));
      if (identName(obj) === "console" && (prop === "log" || prop === "error")) {
        hits.push({ ...locStart(n), message: "Implicit-return arrow body is a void-returning console call — use a block body" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 14. no-meaningless-void-operator (approximation)
// ---------------------------------------------------------------------------

export const meaninglessVoidChecker: FileChecker = {
  ruleId: "typescript/style/no-meaningless-void-operator",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    // Approximation: no type-checker available in this architecture (no
    // parserOptions.project); can't verify the call's return type is actually
    // non-Promise/non-meaningful, only that the callee's name doesn't LOOK
    // like a fire-and-forget async operation.
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "UnaryExpression") return;
      if ((n as AstNode & { operator?: string }).operator !== "void") return;
      const arg = member(n, "argument");
      if (arg?.type !== "CallExpression") return;
      const name = calleeName(arg);
      if (name && !FLOATING_CALL_RE.test(name)) {
        hits.push({ ...locStart(n), message: `'void ${name}(...)' — the call doesn't look like a suppressed promise; the 'void' is likely meaningless` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 15. prefer-string-startswith-endswith-over-slice-indexof
// ---------------------------------------------------------------------------

function isMemberCall(n: AstNode | undefined, methodName: string): boolean {
  if (!n || n.type !== "CallExpression") return false;
  const callee = member(n, "callee");
  const computed = callee ? (callee as AstNode & { computed?: boolean }).computed : undefined;
  return !!callee && callee.type === "MemberExpression" && !computed && identName(member(callee, "property")) === methodName;
}

function isNegativeNumberLiteral(n: AstNode | undefined): boolean {
  if (!n) return false;
  if (n.type === "UnaryExpression" && (n as AstNode & { operator?: string }).operator === "-") {
    return isNumericLiteral(member(n, "argument"));
  }
  return false;
}

export const indexofPrefixSuffixChecker: FileChecker = {
  ruleId: "typescript/style/prefer-string-startswith-endswith-over-slice-indexof",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "BinaryExpression") return;
      const op = (n as AstNode & { operator?: string }).operator;
      if (op !== "===" && op !== "==") return;
      const left = member(n, "left");
      const right = member(n, "right");
      if (isMemberCall(left, "indexOf") && isNumericLiteral(right) && right.value === 0) {
        hits.push({ ...locStart(n), message: "'x.indexOf(y) === 0' — prefer 'x.startsWith(y)'" });
        return;
      }
      if (isMemberCall(right, "indexOf") && isNumericLiteral(left) && left.value === 0) {
        hits.push({ ...locStart(n), message: "'0 === x.indexOf(y)' — prefer 'x.startsWith(y)'" });
        return;
      }
      const leftIsStr = left?.type === "Literal" && typeof (left as AstNode & { value?: unknown }).value === "string";
      const rightIsStr = right?.type === "Literal" && typeof (right as AstNode & { value?: unknown }).value === "string";
      if (isMemberCall(left, "slice") && isNegativeNumberLiteral(((left as AstNode & { arguments?: AstNode[] }).arguments ?? [])[0]) && rightIsStr) {
        hits.push({ ...locStart(n), message: "'x.slice(-n) === literal' — prefer 'x.endsWith(literal)'" });
        return;
      }
      if (isMemberCall(right, "slice") && isNegativeNumberLiteral(((right as AstNode & { arguments?: AstNode[] }).arguments ?? [])[0]) && leftIsStr) {
        hits.push({ ...locStart(n), message: "'literal === x.slice(-n)' — prefer 'x.endsWith(literal)'" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 16. prefer-array-includes-over-indexof
// ---------------------------------------------------------------------------

function isNegOneLiteral(n: AstNode | undefined): boolean {
  if (!n) return false;
  if (n.type === "UnaryExpression" && (n as AstNode & { operator?: string }).operator === "-") {
    const arg = member(n, "argument");
    return isNumericLiteral(arg) && arg.value === 1;
  }
  return false;
}

export const indexofMembershipChecker: FileChecker = {
  ruleId: "typescript/style/prefer-array-includes-over-indexof",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "BinaryExpression") {
        const op = (n as AstNode & { operator?: string }).operator;
        const left = member(n, "left");
        const right = member(n, "right");
        if ((op === "!==" || op === "!=") && isMemberCall(left, "indexOf") && isNegOneLiteral(right)) {
          hits.push({ ...locStart(n), message: "'x.indexOf(y) !== -1' — prefer 'x.includes(y)'" });
        } else if (op === ">" && isMemberCall(left, "indexOf") && isNegOneLiteral(right)) {
          hits.push({ ...locStart(n), message: "'x.indexOf(y) > -1' — prefer 'x.includes(y)'" });
        }
      }
      if (n.type === "UnaryExpression" && (n as AstNode & { operator?: string }).operator === "~") {
        if (isMemberCall(member(n, "argument"), "indexOf")) {
          hits.push({ ...locStart(n), message: "'~x.indexOf(y)' — prefer 'x.includes(y)'" });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 17. prefer-array-find-over-filter-0
// ---------------------------------------------------------------------------

export const filterZeroIndexChecker: FileChecker = {
  ruleId: "typescript/style/prefer-array-find-over-filter-0",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "MemberExpression") return;
      if (!(n as AstNode & { computed?: boolean }).computed) return;
      const prop = member(n, "property");
      if (!isNumericLiteral(prop) || prop.value !== 0) return;
      const obj = member(n, "object");
      if (isMemberCall(obj, "filter")) {
        hits.push({ ...locStart(n), message: "'x.filter(fn)[0]' — prefer 'x.find(fn)'" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 18/19. tsconfig repo checks
// ---------------------------------------------------------------------------

function stripJsonComments(text: string): string {
  return text.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:"])\/\/.*$/gm, "$1");
}

interface TsconfigDoc {
  path: string;
  json: Record<string, unknown>;
}

/** All tsconfig*.json files in the repo — a monorepo has one per package, not one at the root. */
async function findAllTsconfigs(ctx: RepoContext): Promise<TsconfigDoc[]> {
  const paths = ctx.allFiles.filter((f) => /(^|\/)tsconfig(\.\w[\w.-]*)?\.json$/.test(f));
  const docs: TsconfigDoc[] = [];
  for (const path of paths) {
    try {
      const raw = await readFile(path, "utf8");
      docs.push({ path, json: JSON.parse(stripJsonComments(raw)) as Record<string, unknown> });
    } catch {
      // Unparseable tsconfig — nothing to compare against for this file.
    }
  }
  return docs;
}

/**
 * Resolves whether `compilerOptionKey` is `true` for `doc`, following a
 * RELATIVE `extends` chain (e.g. `"extends": "../tsconfig.base.json"`) up to
 * a few hops. An `extends` target outside the repo (a bare package specifier
 * like `@tsconfig/recommended` — not walked, since `node_modules` is
 * skipped) can't be resolved locally; in that case this returns `undefined`
 * ("can't determine locally") rather than `false`, so the caller doesn't
 * flag a tsconfig that might legitimately inherit the option from an
 * external base it extends.
 */
function resolveCompilerOption(doc: TsconfigDoc, key: string, allDocsByPath: Map<string, TsconfigDoc>, depth = 0): boolean | undefined {
  const compilerOptions = (doc.json.compilerOptions ?? {}) as Record<string, unknown>;
  if (compilerOptions[key] === true) return true;
  if (compilerOptions[key] === false) return false;
  // Key is absent at this level. No `extends` (or too deep a chain) means
  // there's nothing left to inherit from — genuinely unset, flag it.
  const extendsSpec = doc.json.extends;
  if (typeof extendsSpec !== "string" || depth > 5) return false;
  // An external base (`@tsconfig/recommended`, not walked since node_modules
  // is skipped) might set this option — can't prove it doesn't, so don't flag.
  if (!extendsSpec.startsWith(".")) return undefined;
  const resolvedPath = normalizeJoin(dirname(doc.path), extendsSpec);
  const extended = allDocsByPath.get(resolvedPath) ?? allDocsByPath.get(`${resolvedPath}.json`);
  // A relative `extends` target that's supposed to be in this repo but
  // wasn't found is NOT the same uncertainty as an external package — treat
  // it as unset.
  if (!extended) return false;
  return resolveCompilerOption(extended, key, allDocsByPath, depth + 1);
}

function normalizeJoin(dir: string, rel: string): string {
  return join(dir, rel).replace(/\\/g, "/");
}

async function checkTsconfigOption(ctx: RepoContext, key: string, message: string): Promise<CheckHit[]> {
  const docs = await findAllTsconfigs(ctx);
  if (docs.length === 0) return [];
  const byPath = new Map(docs.map((d) => [d.path.replace(/\\/g, "/"), d] as const));
  const hits: CheckHit[] = [];
  for (const doc of docs) {
    const resolved = resolveCompilerOption(doc, key, byPath);
    if (resolved === false) hits.push({ line: 1, message, path: doc.path });
  }
  return hits;
}

export const tsconfigStrictChecker: RepoChecker = {
  ruleId: "typescript/tsconfig/tsconfig-enable-strict-mode-no-exceptions",
  kind: "repo",
  check(ctx) {
    return checkTsconfigOption(ctx, "strict", "compilerOptions.strict must be true — no exceptions");
  },
};

export const tsconfigUncheckedIndexedAccessChecker: RepoChecker = {
  ruleId: "typescript/tsconfig/tsconfig-enable-nouncheckedindexedaccess",
  kind: "repo",
  check(ctx) {
    return checkTsconfigOption(ctx, "noUncheckedIndexedAccess", "compilerOptions.noUncheckedIndexedAccess should be true");
  },
};

// ---------------------------------------------------------------------------
// 20. no-non-null-assertion-on-optional-chain
// ---------------------------------------------------------------------------

export const nonNullOnOptionalChainChecker: FileChecker = {
  ruleId: "typescript/type-safety/no-non-null-assertion-on-optional-chain",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "TSNonNullExpression") return;
      const expr = member(n, "expression");
      const isOptional =
        !!expr &&
        ((expr as AstNode & { optional?: boolean }).optional === true ||
          expr.type === "ChainExpression");
      if (isOptional) {
        hits.push({ ...locStart(n), message: "Non-null assertion ('!') on an optional-chain expression — the chain can still be undefined" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 21. prefer-nullish-coalescing-over-logical-or (approximation)
// ---------------------------------------------------------------------------

export const logicalOrDefaultChecker: FileChecker = {
  ruleId: "typescript/type-safety/prefer-nullish-coalescing-over-logical-or",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    // Approximation: no type-checker available in this architecture (no
    // parserOptions.project); can't verify the left side can legitimately be
    // falsy-but-valid (e.g. `0` or `""`) — only flags the syntactic
    // default-value SHAPE (function argument / variable initializer / object
    // property value), not condition usage.
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const parents = buildParentMap(parsed.ast);
    walkAst(parsed.ast, (n) => {
      if (n.type !== "LogicalExpression" || (n as AstNode & { operator?: string }).operator !== "||") return;
      const info = parents.get(n);
      if (!info) return;
      const { parent, key } = info;
      const isDefaultValuePosition =
        (parent.type === "VariableDeclarator" && key === "init") ||
        ((parent.type === "CallExpression" || parent.type === "NewExpression") && key === "arguments") ||
        (parent.type === "Property" && key === "value");
      if (isDefaultValuePosition) {
        hits.push({ ...locStart(n), message: "'a || b' used as a default value — prefer 'a ?? b' unless a falsy-but-valid left side is intended" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 22. prefer-optional-chaining-over-manual-null-guards
// ---------------------------------------------------------------------------

function flattenAndChain(node: AstNode, skip: Set<AstNode>): AstNode[] {
  if (node.type === "LogicalExpression" && (node as AstNode & { operator?: string }).operator === "&&") {
    skip.add(node);
    const left = member(node, "left");
    const right = member(node, "right");
    const leftFlat = left ? flattenAndChain(left, skip) : [];
    return right ? [...leftFlat, right] : leftFlat;
  }
  return [node];
}

function rootIdentifierName(node: AstNode | undefined): string | undefined {
  let cur = node;
  while (cur) {
    if (cur.type === "Identifier") return identName(cur);
    if (cur.type === "MemberExpression") {
      cur = member(cur, "object");
      continue;
    }
    return undefined;
  }
  return undefined;
}

export const manualNullGuardChainChecker: FileChecker = {
  ruleId: "typescript/type-safety/prefer-optional-chaining-over-manual-null-guards",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const consumed = new Set<AstNode>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "LogicalExpression" || (n as AstNode & { operator?: string }).operator !== "&&") return;
      if (consumed.has(n)) return;
      const localSkip = new Set<AstNode>();
      const operands = flattenAndChain(n, localSkip);
      for (const s of localSkip) consumed.add(s);
      if (operands.length < 2) return;
      const rootName = rootIdentifierName(operands[0]);
      if (!rootName) return;
      const chains = operands.slice(1).every((op) => op.type === "MemberExpression" && rootIdentifierName(op) === rootName);
      if (chains) {
        hits.push({ ...locStart(n), message: `Manual null-guard chain on '${rootName}' — prefer optional chaining ('${rootName}?.…')` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 23. prefer-record-string-t-or-map-over-index-signatures-for-unknown-
// ---------------------------------------------------------------------------

export const indexSignatureDictChecker: FileChecker = {
  ruleId: "typescript/type-safety/prefer-record-string-t-or-map-over-index-signatures-for-unknown-",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "TSIndexSignature") {
        hits.push({ ...locStart(n), message: "Index signature used as a dynamic dictionary — prefer 'Record<string, T>' or 'Map<K, T>'" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 24. no-redundant-or-duplicate-union-intersection-members
// ---------------------------------------------------------------------------

function typeSignature(t: AstNode): string {
  if (t.type === "TSLiteralType") {
    const lit = member(t, "literal");
    return `lit:${lit?.type}:${String((lit as AstNode & { value?: unknown })?.value)}`;
  }
  if (t.type === "TSTypeReference") {
    return `ref:${identName(member(t, "typeName")) ?? ""}`;
  }
  return t.type;
}

export const duplicateUnionMemberChecker: FileChecker = {
  ruleId: "typescript/type-safety/no-redundant-or-duplicate-union-intersection-members",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "TSUnionType" && n.type !== "TSIntersectionType") return;
      const types = ((n as AstNode & { types?: AstNode[] }).types ?? []) as AstNode[];
      const seen = new Set<string>();
      let hasDup = false;
      for (const t of types) {
        const sig = typeSignature(t);
        if (seen.has(sig)) hasDup = true;
        seen.add(sig);
      }
      if (hasDup) {
        hits.push({ ...locStart(n), message: `${n.type === "TSUnionType" ? "Union" : "Intersection"} type has duplicate members` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// Manual → automatable proxies (verification-pass follow-up). None of these
// have real type-checker info available (no parserOptions.project anywhere in
// this codebase — see parse.ts) so they all work off SYNTACTIC/declared type
// annotations only, never inferred types. Each documents its own residual
// false-positive/false-negative behavior below rather than claiming precision
// it doesn't have.
// ---------------------------------------------------------------------------

function rangeOf(node: AstNode | undefined): [number, number] | undefined {
  return node ? (node as unknown as { range?: [number, number] }).range : undefined;
}

function textOf(content: string, node: AstNode | undefined): string {
  const r = rangeOf(node);
  return r ? content.slice(r[0], r[1]) : "";
}

/** A function parameter's own declared type text (e.g. "string", "Order"), or undefined if untyped/destructured. */
function paramDeclaredTypes(content: string, fn: FunctionLike): Map<string, { text: string; optional: boolean }> {
  const out = new Map<string, { text: string; optional: boolean }>();
  const params = ((fn as unknown as AstNode & { params?: AstNode[] }).params ?? []) as AstNode[];
  for (const p of params) {
    if (p.type !== "Identifier") continue;
    const name = identName(p);
    const ann = member(p, "typeAnnotation");
    const inner = ann ? member(ann, "typeAnnotation") : undefined;
    if (!name || !inner) continue;
    const optional = !!(p as AstNode & { optional?: boolean }).optional;
    out.set(name, { text: textOf(content, inner).trim(), optional });
  }
  return out;
}

/**
 * `typescript/style/use-type-only-imports-for-types` proxy — a near-duplicate
 * of `typescript/style/type-only-imports-use-the-type-modifier-consistent-
 * type-imports` (already covered via the real `consistent-type-imports` ESLint
 * rule), reused here as a native builtin per the "one dedup, don't leave the
 * twin unconverted" rule.
 *
 * Flags a named import where NONE of its specifiers are referenced anywhere
 * else in the file outside a type position (TSTypeReference/TSQualifiedName).
 * Known limits: purely name-text based, not scope-resolved — a shadowing local
 * with the same name elsewhere in the file would suppress a true positive
 * (false negative); `export { X }` re-exports count as a value use even when
 * re-exporting a type-only binding is itself fine (false negative). No false
 * positives observed against the rule's own Bad/Good pair.
 */
export const typeOnlyImportChecker: FileChecker = {
  ruleId: "typescript/style/use-type-only-imports-for-types",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];

    const typePositionNodes = new Set<AstNode>();
    walkAst(parsed.ast, (n) => {
      if (n.type === "TSTypeReference") {
        let tn = member(n, "typeName");
        while (tn && tn.type === "TSQualifiedName") tn = member(tn, "left");
        if (tn) typePositionNodes.add(tn);
      }
    });

    walkAst(parsed.ast, (importDecl) => {
      if (importDecl.type !== "ImportDeclaration") return;
      if ((importDecl as AstNode & { importKind?: string }).importKind === "type") return;
      const specifiers = ((importDecl as AstNode & { specifiers?: AstNode[] }).specifiers ?? []).filter(
        (s) => s.type === "ImportSpecifier" && (s as AstNode & { importKind?: string }).importKind !== "type",
      );
      if (specifiers.length === 0) return;
      const localNodes = new Set<AstNode>();
      const names = specifiers
        .map((s) => {
          const local = member(s, "local");
          const imported = member(s, "imported");
          if (local) localNodes.add(local);
          if (imported) localNodes.add(imported);
          return identName(local);
        })
        .filter((n): n is string => !!n);
      if (names.length === 0) return;

      const usedAsValue = new Set<string>();
      walkAst(parsed.ast, (n) => {
        if (n.type !== "Identifier") return;
        if (localNodes.has(n)) return;
        if (typePositionNodes.has(n)) return;
        const name = identName(n);
        if (name && names.includes(name)) usedAsValue.add(name);
      });

      if (names.every((n) => !usedAsValue.has(n))) {
        hits.push({
          ...locStart(importDecl),
          message: `Import '${names.join(", ")}' is only ever referenced in type position — use 'import type' (or a per-specifier 'type' modifier) so it emits no runtime import.`,
        });
      }
    });
    return hits;
  },
};

/**
 * `typescript/type-safety/no-unnecessary-type-assertions` proxy. Flags
 * `(identifier as T)` where `identifier` is a parameter of the enclosing
 * function whose OWN declared annotation is textually identical to `T`.
 * Known limits: only catches the same-function-parameter case with an exact
 * textual match (misses inferred/non-annotated locals and cross-scope cases —
 * false negative); a parameter re-narrowed earlier in the body to the same
 * type text via a type guard would still be flagged (false positive risk).
 */
export const noUnnecessaryTypeAssertionChecker: FileChecker = {
  ruleId: "typescript/type-safety/no-unnecessary-type-assertions",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node: fn } of findFunctions(parsed.ast)) {
      const paramTypes = paramDeclaredTypes(ctx.content, fn);
      if (paramTypes.size === 0) continue;
      const body = (fn as unknown as AstNode & { body?: AstNode }).body ?? (fn as unknown as AstNode);
      walkAst(body, (n) => {
        if (n.type !== "TSAsExpression") return;
        const expr = member(n, "expression");
        if (expr?.type !== "Identifier") return;
        const name = identName(expr);
        const declared = name ? paramTypes.get(name) : undefined;
        if (!declared) return;
        const assertedType = member(n, "typeAnnotation");
        const assertedText = textOf(ctx.content, assertedType).trim();
        if (assertedText && assertedText === declared.text) {
          hits.push({
            ...locStart(n),
            message: `'${name}' is already declared as '${declared.text}' — asserting 'as ${assertedText}' is a no-op.`,
          });
        }
      });
    }
    return hits;
  },
};

const NON_BOOLEAN_PRIMITIVE_RE = /^(string|number|bigint)(\s*\|\s*(undefined|null))?$/;

/**
 * `typescript/style/strict-boolean-expressions-...` proxy. Flags
 * `if (identifier)` / ternary tests where `identifier` is a same-function
 * parameter declared as a non-boolean primitive (string/number/bigint,
 * optionally unioned with undefined/null) — the implicit-coercion case this
 * rule warns about ("" and 0 are falsy but valid values).
 * Known limits: same-function-parameter + exact type-text match only (misses
 * inferred locals — false negative); doesn't attempt real narrowing analysis,
 * so a param already narrowed earlier in the body may still be flagged
 * (false positive risk).
 */
export const strictBooleanExpressionChecker: FileChecker = {
  ruleId: "typescript/style/strict-boolean-expressions-no-implicit-coercion-in-conditions",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node: fn } of findFunctions(parsed.ast)) {
      const paramTypes = paramDeclaredTypes(ctx.content, fn);
      if (paramTypes.size === 0) continue;
      const body = (fn as unknown as AstNode & { body?: AstNode }).body ?? (fn as unknown as AstNode);
      walkAst(body, (n) => {
        if (n.type !== "IfStatement" && n.type !== "ConditionalExpression") return;
        const test = member(n, "test");
        const target = test?.type === "Identifier" ? test : undefined;
        if (!target) return;
        const name = identName(target);
        const declared = name ? paramTypes.get(name) : undefined;
        if (!declared || !NON_BOOLEAN_PRIMITIVE_RE.test(declared.text)) return;
        hits.push({
          ...locStart(n),
          message: `'${name}' is declared '${declared.text}' — truthiness check implicitly coerces ("" and 0 are falsy but valid). Compare explicitly (e.g. '${name} !== undefined').`,
        });
      });
    }
    return hits;
  },
};

const OBJECT_TYPE_RE = /^[A-Z][A-Za-z0-9_]*$/;

/**
 * `typescript/style/no-unnecessary-conditions-...` proxy. Flags
 * `if (identifier)` where `identifier` is a same-function parameter whose
 * declared type is a non-nullable, non-optional custom/object type (a
 * capitalized type reference with no union, no `?`) — TypeScript already
 * proved this truthy, so the check is dead code.
 * Known limits: text-based type-shape guess (a capitalized type alias for a
 * primitive, e.g. `type UserId = string`, would false-positive); misses
 * inferred/flow-narrowed non-nullability (false negative).
 */
export const noUnnecessaryConditionChecker: FileChecker = {
  ruleId: "typescript/style/no-unnecessary-conditions-typescript-proved-them-already",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node: fn } of findFunctions(parsed.ast)) {
      const paramTypes = paramDeclaredTypes(ctx.content, fn);
      if (paramTypes.size === 0) continue;
      const body = (fn as unknown as AstNode & { body?: AstNode }).body ?? (fn as unknown as AstNode);
      walkAst(body, (n) => {
        if (n.type !== "IfStatement") return;
        const test = member(n, "test");
        if (test?.type !== "Identifier") return;
        const name = identName(test);
        const declared = name ? paramTypes.get(name) : undefined;
        if (!declared || declared.optional) return;
        if (!OBJECT_TYPE_RE.test(declared.text)) return;
        hits.push({
          ...locStart(n),
          message: `'${name}' is declared non-optional '${declared.text}' — TypeScript already guarantees it's truthy; this check is dead code.`,
        });
      });
    }
    return hits;
  },
};

const PRIMITIVE_TYPE_RE = /^(string|number|bigint|boolean)(\s*\|\s*(undefined|null))?$/;

/** Every `const/let x: T = ...` declaration's own type text, file-wide (flat namespace — no real scoping). */
function declaredVariableTypes(content: string, root: AstNode): Map<string, string> {
  const out = new Map<string, string>();
  walkAst(root, (n) => {
    if (n.type !== "VariableDeclarator") return;
    const id = member(n, "id");
    if (id?.type !== "Identifier") return;
    const ann = member(id, "typeAnnotation");
    const inner = ann ? member(ann, "typeAnnotation") : undefined;
    const name = identName(id);
    if (!name || !inner) return;
    out.set(name, textOf(content, inner).trim());
  });
  return out;
}

/**
 * `typescript/type-safety/don-t-pass-non-strings-to-string-...` proxy. Flags a
 * template-literal substitution that is a bare identifier whose declared type
 * (function parameter OR `const`/`let` annotation, anywhere in the file) is an
 * inline object-shape annotation (`{ ... }`) — interpolating it calls
 * `.toString()` implicitly, producing "[object Object]".
 * Known limits: file-wide flat name lookup, not real scope resolution (a
 * shadowed name elsewhere in the file could misattribute a type — false
 * positive/negative risk); only catches an inline `{ ... }` annotation, not
 * named interfaces/classes, and only a bare identifier substitution, not
 * `${order.id}` member access (false negative, by design — avoids needing
 * real type resolution for nested property types).
 */
export const nonStringTemplateInterpChecker: FileChecker = {
  ruleId: "typescript/type-safety/don-t-pass-non-strings-to-string-concatenation-without-explicit-",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const declaredTypes = declaredVariableTypes(ctx.content, parsed.ast);
    for (const { node: fn } of findFunctions(parsed.ast)) {
      for (const [name, decl] of paramDeclaredTypes(ctx.content, fn)) declaredTypes.set(name, decl.text);
    }
    if (declaredTypes.size === 0) return hits;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "TemplateLiteral") return;
      const exprs = ((n as AstNode & { expressions?: AstNode[] }).expressions ?? []) as AstNode[];
      for (const e of exprs) {
        if (e.type !== "Identifier") continue;
        const name = identName(e);
        const declaredText = name ? declaredTypes.get(name) : undefined;
        if (!declaredText || !declaredText.startsWith("{")) continue;
        hits.push({
          ...locStart(e),
          message: `'${name}' is an object-shaped type ('${declaredText}') interpolated directly into a template literal — calls .toString() implicitly, producing "[object Object]". Use an explicit field or JSON.stringify().`,
        });
      }
    });
    return hits;
  },
};

const ANY_SOURCE_RE = /^(JSON\.parse|.*\.json)$/;

function calleeDottedName(call: AstNode | undefined): string | undefined {
  const callee = call ? member(call, "callee") : undefined;
  if (!callee) return undefined;
  if (callee.type === "Identifier") return identName(callee);
  if (callee.type === "MemberExpression") {
    const objName = identName(member(callee, "object"));
    const propName = identName(member(callee, "property"));
    if (objName && propName) return `${objName}.${propName}`;
    if (propName) return `.${propName}`;
  }
  return undefined;
}

/**
 * Shared proxy for `no-unsafe-assignment`, `no-unsafe-return`, and
 * `no-unsafe-argument` — all three share the same root cause (an `any`-typed
 * value, from a known any-producing source, flowing into typed code with no
 * validation) so they're covered by one checker per the "dedup, don't triplicate"
 * convention, referenced from all three rule markdown files.
 *
 * Genuinely the hardest of the manual-rule proxies: without a real type
 * checker there's no way to know a value's *inferred* type is `any` in
 * general. This flags the single most common real-world source instead:
 * a local initialized directly from `JSON.parse(...)` or a `.json()` member
 * call (the idiomatic App Builder "fetch a response, parse it" pattern), with
 * no `.parse(`/`.safeParse(`/`Joi.`/`ajv` validator call anywhere in the same
 * function, that is then (a) assigned to an explicitly-typed variable,
 * (b) passed as a call argument, or (c) returned from a function with an
 * explicit non-`any` return type annotation.
 *
 * Documented, real false-negative gap: misses every other `any` source
 * (untyped `require`, a poorly-typed third-party call, a cast) — this is a
 * best-effort partial proxy, not full coverage, exactly as described in the
 * verification report.
 */
/** Root identifier of a MemberExpression chain (`data.user.id` → `data`), or the identifier itself. */
function rootIdentName(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier") return identName(n);
  if (n.type === "MemberExpression") return rootIdentName(member(n, "object"));
  return undefined;
}

/** True if `n` is itself a direct any-producing call (e.g. `res.json()`), or rooted in an already-tainted identifier. */
function isAnyTaintedExpr(n: AstNode | undefined, anyTainted: Set<string>): boolean {
  if (!n) return false;
  if (n.type === "CallExpression") {
    const name = calleeDottedName(n);
    return !!name && ANY_SOURCE_RE.test(name);
  }
  if (n.type === "Identifier" || n.type === "MemberExpression") {
    const root = rootIdentName(n);
    return !!root && anyTainted.has(root);
  }
  return false;
}

function hitKey(h: CheckHit): string {
  return `${h.line}:${h.column}:${h.message}`;
}

export const unsafeAnyBoundaryChecker: FileChecker = {
  ruleId: "typescript/type-safety/no-unsafe-assignment-unsafe-call-unsafe-member-access",
  kind: "file",
  appliesTo: (p) => /\.tsx?$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    // Strip bare `JSON.parse(` before checking for a validator call — this
    // checker's whole premise is JSON.parse as the any-source, so a naive
    // guard on ".parse(" would match JSON.parse itself and never fire.
    const withoutJsonParse = ctx.content.replace(/JSON\.parse\(/g, "");
    if (/\.safeParse\(|\.parse\(|Joi\.|ajv/i.test(withoutJsonParse)) return [];
    const hits: CheckHit[] = [];

    // Each function body is scanned as its own scope (so a return-type check
    // can apply); the whole Program is ALSO scanned once more as an implicit
    // top-level "scope" (several of the rule's own canonical examples are
    // top-level snippets, not wrapped in a function) — (a)/(b) only there,
    // since "return" has no meaning outside a function. Some overlap/duplicate
    // detection between the two passes is possible for mixed top-level+
    // function code; de-duplicated below.
    const scopes: Array<{ body: AstNode; returnType: AstNode | undefined }> = [
      { body: parsed.ast, returnType: undefined },
    ];
    for (const { node: fn } of findFunctions(parsed.ast)) {
      const body = (fn as unknown as AstNode & { body?: AstNode }).body ?? (fn as unknown as AstNode);
      scopes.push({ body, returnType: member(fn as unknown as AstNode, "returnType") });
    }

    for (const { body, returnType } of scopes) {
      const anyTainted = new Set<string>();
      walkAst(body, (n) => {
        if (n.type !== "VariableDeclarator") return;
        const id = member(n, "id");
        const init = member(n, "init");
        if (id?.type !== "Identifier" || !init) return;
        const call = init.type === "AwaitExpression" ? member(init, "argument") : init;
        if (isAnyTaintedExpr(call, new Set())) {
          const varName = identName(id);
          if (varName && call?.type === "CallExpression") anyTainted.add(varName);
        }
      });

      walkAst(body, (n) => {
        // (a) assigned/declared into an explicitly-typed variable.
        if (n.type === "VariableDeclarator") {
          const id = member(n, "id");
          const init = member(n, "init");
          const typeAnn = id ? member(id, "typeAnnotation") : undefined;
          if (id?.type === "Identifier" && typeAnn && init && isAnyTaintedExpr(init, anyTainted)) {
            const root = rootIdentName(init);
            hits.push({ ...locStart(n), message: `'${root}' comes from an unvalidated JSON.parse()/.json() call — assigning it (or a property of it) to a typed variable bypasses type safety (unsafe-assignment). Validate with a schema (zod/Joi/ajv) first.` });
          }
        }
        // (b) passed as a call argument.
        if (n.type === "CallExpression") {
          const args = ((n as AstNode & { arguments?: AstNode[] }).arguments ?? []) as AstNode[];
          for (const a of args) {
            if (isAnyTaintedExpr(a, anyTainted)) {
              const root = rootIdentName(a);
              hits.push({ ...locStart(a), message: `'${root}' comes from an unvalidated JSON.parse()/.json() call — passing it as an argument erases the parameter's type guarantees (unsafe-argument). Validate with a schema first.` });
            }
          }
        }
        // (c) returned from a function with an explicit non-any return type (only meaningful for a function scope).
        if (n.type === "ReturnStatement" && returnType) {
          const arg = member(n, "argument");
          if (isAnyTaintedExpr(arg, anyTainted)) {
            const root = rootIdentName(arg);
            hits.push({ ...locStart(n), message: `'${root}' comes from an unvalidated JSON.parse()/.json() call — returning it from a function with an explicit return type silently breaks the caller's assumptions (unsafe-return). Validate with a schema first.` });
          }
        }
      });
    }

    const seen = new Set<string>();
    return hits.filter((h) => {
      const key = hitKey(h);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  },
};
