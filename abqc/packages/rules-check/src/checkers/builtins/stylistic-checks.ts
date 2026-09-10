import type { CheckHit, FileChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import { walkAst, findFunctions, locStart, type AstNode, type FunctionLike } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

// ---------------------------------------------------------------------------
// Local helpers (small, duplicated per file-isolation constraints).
// ---------------------------------------------------------------------------

function keyNameOf(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier") return identName(n);
  if (n.type === "Literal") return String((n as AstNode & { value: unknown }).value);
  return undefined;
}

function isStringLiteral(n: AstNode | undefined): n is AstNode & { value: string } {
  return !!n && n.type === "Literal" && typeof (n as AstNode & { value: unknown }).value === "string";
}

function isNumericLiteral(n: AstNode | undefined): n is AstNode & { value: number } {
  return !!n && n.type === "Literal" && typeof (n as AstNode & { value: unknown }).value === "number";
}

function isMemberCall(n: AstNode | undefined, methodName: string): boolean {
  if (!n || n.type !== "CallExpression") return false;
  const callee = member(n, "callee");
  const computed = callee ? (callee as AstNode & { computed?: boolean }).computed : undefined;
  return !!callee && callee.type === "MemberExpression" && !computed && identName(member(callee, "property")) === methodName;
}

/** Parent map keyed by node → {parent, key} — used to check ancestor context
 * (e.g. "is this ReturnStatement inside a TryStatement's try-block?"). */
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

const FUNCTION_TYPES = new Set(["FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression"]);

// ---------------------------------------------------------------------------
// 25. no-nested-ternaries-use-if-else-or-a-lookup
// ---------------------------------------------------------------------------

export const nestedTernaryChecker: FileChecker = {
  ruleId: "stylistic/control-flow/no-nested-ternaries-use-if-else-or-a-lookup",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ConditionalExpression") return;
      const consequent = member(n, "consequent");
      const alternate = member(n, "alternate");
      if (consequent?.type === "ConditionalExpression" || alternate?.type === "ConditionalExpression") {
        hits.push({ ...locStart(n), message: "Nested ternary — use if/else or a lookup table instead" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 26. no-unneeded-ternaries
// ---------------------------------------------------------------------------

export const unneededTernaryChecker: FileChecker = {
  ruleId: "stylistic/control-flow/no-unneeded-ternaries",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ConditionalExpression") return;
      const test = member(n, "test");
      const consequent = member(n, "consequent");
      const alternate = member(n, "alternate");
      const isBool = (x: AstNode | undefined) => x?.type === "Literal" && typeof (x as AstNode & { value: unknown }).value === "boolean";
      if (isBool(consequent) && isBool(alternate)) {
        hits.push({ ...locStart(n), message: "Ternary returns literal booleans — use the condition (or its negation) directly" });
        return;
      }
      if (test?.type === "Identifier" && consequent?.type === "Identifier" && identName(test) === identName(consequent)) {
        hits.push({ ...locStart(n), message: "'x ? x : y' — prefer 'x ?? y' / 'x || y'" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 27. no-empty-blocks-at-least-a-comment-explaining-why
// ---------------------------------------------------------------------------

export const emptyBlockNoCommentChecker: FileChecker = {
  ruleId: "stylistic/control-flow/no-empty-blocks-at-least-a-comment-explaining-why",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const comments = ((parsed.ast as AstNode & { comments?: AstNode[] }).comments ?? []) as AstNode[];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "BlockStatement") return;
      const stmts = (n as AstNode & { body?: AstNode[] }).body ?? [];
      if (stmts.length !== 0) return;
      const range = (n as AstNode & { range?: [number, number] }).range;
      if (!range) return;
      const hasComment = comments.some((c) => {
        const cr = (c as AstNode & { range?: [number, number] }).range;
        return !!cr && cr[0] >= range[0] && cr[1] <= range[1];
      });
      if (!hasComment) {
        hits.push({ ...locStart(n), message: "Empty block with no comment explaining why — add at least a one-line comment" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 28. no-useless-return-statements
// ---------------------------------------------------------------------------

export const uselessReturnChecker: FileChecker = {
  ruleId: "stylistic/control-flow/no-useless-return-statements",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const FUNCTION_TYPES = new Set(["FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression"]);
    walkAst(parsed.ast, (n) => {
      // Only a FUNCTION's own body counts — an `if(cond){ return; }` guard
      // clause is the last statement of the IF's block, not of the enclosing
      // function, and is very often followed by substantial code after the
      // if-statement itself; that's a legitimate early return, not a useless
      // trailing one. Walking every BlockStatement (including if/for/try
      // blocks) conflated the two and flagged real early-return guard clauses.
      if (!FUNCTION_TYPES.has(n.type)) return;
      const body = member(n, "body");
      if (!body || body.type !== "BlockStatement") return;
      const stmts = (body as AstNode & { body?: AstNode[] }).body ?? [];
      const last = stmts[stmts.length - 1];
      if (last && last.type === "ReturnStatement" && member(last, "argument") === undefined && (last as AstNode & { argument?: unknown }).argument == null) {
        hits.push({ ...locStart(last), message: "Useless bare 'return' as the last statement — remove it" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 29. don-t-use-else-after-return-flat-is-better-than-nested
// ---------------------------------------------------------------------------

export const elseAfterReturnChecker: FileChecker = {
  ruleId: "stylistic/control-flow/don-t-use-else-after-return-flat-is-better-than-nested",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "IfStatement") return;
      const consequent = member(n, "consequent");
      const alternate = member(n, "alternate");
      if (!alternate || consequent?.type !== "BlockStatement") return;
      const stmts = (consequent as AstNode & { body?: AstNode[] }).body ?? [];
      const last = stmts[stmts.length - 1];
      if (last?.type === "ReturnStatement") {
        hits.push({ ...locStart(n), message: "'else' after a block ending in 'return' — flatten by removing the 'else'" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 30. prefer-destructuring-for-object-array-access
// ---------------------------------------------------------------------------

function scanRepeatedMemberAccess(stmts: AstNode[], hits: CheckHit[]): void {
  const byObject = new Map<string, AstNode[]>();
  for (const stmt of stmts) {
    if (stmt.type !== "VariableDeclaration") continue;
    const decls = (stmt as AstNode & { declarations?: AstNode[] }).declarations ?? [];
    for (const d of decls) {
      const init = member(d, "init");
      const computed = init ? (init as AstNode & { computed?: boolean }).computed : undefined;
      if (init?.type !== "MemberExpression" || computed) continue;
      const obj = member(init, "object");
      if (obj?.type !== "Identifier") continue;
      const name = identName(obj) ?? "";
      const list = byObject.get(name) ?? [];
      list.push(d);
      byObject.set(name, list);
    }
  }
  for (const [name, list] of byObject) {
    if (list.length < 2) continue;
    for (let i = 1; i < list.length; i++) {
      hits.push({ ...locStart(list[i] as AstNode), message: `Repeated property access on '${name}' — prefer destructuring ('const { ... } = ${name}')` });
    }
  }
}

export const repeatedMemberAccessChecker: FileChecker = {
  ruleId: "stylistic/destructuring/prefer-destructuring-for-object-array-access",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const body = (parsed.ast as AstNode & { body?: AstNode[] }).body ?? [];
    scanRepeatedMemberAccess(body, hits);
    walkAst(parsed.ast, (n) => {
      if (n.type === "BlockStatement") {
        scanRepeatedMemberAccess((n as AstNode & { body?: AstNode[] }).body ?? [], hits);
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 31. prefer-spread-over-function-prototype-apply
// ---------------------------------------------------------------------------

export const functionApplyChecker: FileChecker = {
  ruleId: "stylistic/destructuring/prefer-spread-over-function-prototype-apply",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (isMemberCall(n, "apply")) {
        hits.push({ ...locStart(n), message: "'fn.apply(thisArg, args)' — prefer 'fn(...args)' spread syntax" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 32. use-object-shorthand-syntax
// ---------------------------------------------------------------------------

export const objectShorthandChecker: FileChecker = {
  ruleId: "stylistic/destructuring/use-object-shorthand-syntax",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ObjectExpression") return;
      const props = (n as AstNode & { properties?: AstNode[] }).properties ?? [];
      for (const p of props) {
        if (p.type !== "Property") continue;
        if ((p as AstNode & { shorthand?: boolean }).shorthand) continue;
        if ((p as AstNode & { method?: boolean }).method) continue;
        if ((p as AstNode & { computed?: boolean }).computed) continue;
        const key = member(p, "key");
        const value = member(p, "value");
        if (value?.type === "Identifier" && keyNameOf(key) === identName(value)) {
          hits.push({ ...locStart(p), message: `'{ ${keyNameOf(key)}: ${identName(value)} }' — use shorthand '{ ${keyNameOf(key)} }'` });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 33. don-t-reassign-function-parameters
// ---------------------------------------------------------------------------

export const paramReassignChecker: FileChecker = {
  ruleId: "stylistic/functions/don-t-reassign-function-parameters",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node } of findFunctions(parsed.ast)) {
      const paramNames = new Set<string>();
      for (const p of node.params ?? []) {
        if (p.type === "Identifier") {
          const name = identName(p);
          if (name) paramNames.add(name);
        }
      }
      if (paramNames.size === 0 || !node.body) continue;
      walkAst(node.body, (n) => {
        if (n.type !== "AssignmentExpression") return;
        const left = member(n, "left");
        if (left?.type === "Identifier" && paramNames.has(identName(left) ?? "")) {
          hits.push({ ...locStart(n), message: `Parameter '${identName(left)}' is reassigned — copy to a local variable instead` });
        }
      });
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 34. prefer-arrow-callbacks-for-short-anonymous-functions
// ---------------------------------------------------------------------------

export const anonymousFunctionCallbackChecker: FileChecker = {
  ruleId: "stylistic/functions/prefer-arrow-callbacks-for-short-anonymous-functions",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const args = (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
      for (const arg of args) {
        if (arg.type === "FunctionExpression" && !identName(member(arg, "id"))) {
          hits.push({ ...locStart(arg), message: "Anonymous function callback — prefer an arrow function" });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 35. no-return-await-return-the-promise-directly
// ---------------------------------------------------------------------------

export const returnAwaitOutsideTryChecker: FileChecker = {
  ruleId: "stylistic/functions/no-return-await-return-the-promise-directly",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const parents = buildParentMap(parsed.ast);
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ReturnStatement") return;
      const arg = member(n, "argument");
      if (arg?.type !== "AwaitExpression") return;
      let cur: AstNode = n;
      let insideTryBlock = false;
      for (;;) {
        const info = parents.get(cur);
        if (!info) break;
        const { parent, key } = info;
        if (FUNCTION_TYPES.has(parent.type)) break;
        if (parent.type === "TryStatement" && key === "block") {
          insideTryBlock = true;
          break;
        }
        cur = parent;
      }
      if (!insideTryBlock) {
        hits.push({ ...locStart(n), message: "'return await x' outside a try block — return the promise directly ('return x')" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 36. no-restricted-syntax-codify-project-conventions (fixed starter set)
// ---------------------------------------------------------------------------

export const restrictedSyntaxChecker: FileChecker = {
  // Note: the selector list is fixed to the two examples from the rule's own
  // markdown (for-in and with) pending team input on additional
  // project-specific restricted-syntax selectors — not a configurable system yet.
  ruleId: "stylistic/functions/no-restricted-syntax-codify-project-conventions",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "ForInStatement") {
        hits.push({ ...locStart(n), message: "'for...in' is restricted — use 'Object.keys/entries' or 'for...of'" });
      } else if (n.type === "WithStatement") {
        hits.push({ ...locStart(n), message: "'with' is restricted — it is disallowed in strict mode and hurts readability" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 37. no-magic-numbers-extract-named-constants
// ---------------------------------------------------------------------------

const MAGIC_NUMBER_IGNORE = new Set([0, 1, -1, 2, 3, 4, 5, 6, 7, 8, 9]);

// Real HTTP status codes flagged as "magic numbers" measured at 34% of all
// real (non-parse-error) findings for this rule — recognizable, standard,
// and self-documenting in context, not the kind of arbitrary business
// constant this rule is meant to catch.
const HTTP_STATUS_CODES = new Set([
  100, 101, 200, 201, 202, 203, 204, 205, 206, 300, 301, 302, 303, 304, 307, 308,
  400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413, 414, 415,
  416, 417, 418, 422, 425, 426, 428, 429, 431, 451, 500, 501, 502, 503, 504, 505,
  506, 507, 508, 510, 511,
]);

const NAMED_CONSTANT_CONTAINER_RE = /CONST|CONFIG|LABELS?|MAP/i;

/** Every numeric-literal node nested anywhere inside `node` (values AND object/array keys). */
function collectNumericLiteralsIn(node: AstNode): AstNode[] {
  const found: AstNode[] = [];
  walkAst(node, (n) => {
    if (isNumericLiteral(n)) found.push(n);
  });
  return found;
}

export const magicNumberChecker: FileChecker = {
  ruleId: "stylistic/numbers/no-magic-numbers-extract-named-constants",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const safe = new Set<AstNode>();
    const body = (parsed.ast as AstNode & { body?: AstNode[] }).body ?? [];
    for (const stmt of body) {
      if (stmt.type !== "VariableDeclaration" || (stmt as AstNode & { kind?: string }).kind !== "const") continue;
      const decls = (stmt as AstNode & { declarations?: AstNode[] }).declarations ?? [];
      for (const d of decls) {
        const id = member(d, "id");
        const init = member(d, "init");
        if (isNumericLiteral(init)) {
          safe.add(init);
          continue;
        }
        if (init?.type === "UnaryExpression" && (init as AstNode & { operator?: string }).operator === "-") {
          const arg = member(init, "argument");
          if (isNumericLiteral(arg)) safe.add(arg);
          continue;
        }
        // A number already extracted into a named constants object/array/map
        // (`const AGE_INTERVAL_LABELS = { 6: '...' }`, `const TIME_BUDGET_MS
        // = 1800000 - 120000`) is exactly the fix this rule asks for — flag
        // it anyway only serves to punish having already done so. Scoped to
        // container names that look like a constants table, not every const.
        if (id?.type === "Identifier" && init && NAMED_CONSTANT_CONTAINER_RE.test(identName(id) ?? "")) {
          for (const lit of collectNumericLiteralsIn(init)) safe.add(lit);
        }
      }
    }
    walkAst(parsed.ast, (n) => {
      if (!isNumericLiteral(n)) return;
      if (safe.has(n)) return;
      if (MAGIC_NUMBER_IGNORE.has(n.value)) return;
      if (HTTP_STATUS_CODES.has(n.value) && /status/i.test(lineTextOf(ctx.content, locStart(n)?.line))) return;
      hits.push({ ...locStart(n), message: `Magic number '${n.value}' — extract a named constant` });
    });
    return hits;
  },
};

function lineTextOf(content: string, line: number | undefined): string {
  if (!line) return "";
  return content.split("\n")[line - 1] ?? "";
}

// ---------------------------------------------------------------------------
// 38. prefer-numeric-separators-on-long-literals
// ---------------------------------------------------------------------------

export const numericSeparatorChecker: FileChecker = {
  ruleId: "stylistic/numbers/prefer-numeric-separators-on-long-literals",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (!isNumericLiteral(n)) return;
      const raw = (n as AstNode & { raw?: string }).raw ?? String(n.value);
      if (raw.includes("_")) return;
      const digitCount = (raw.match(/[0-9]/g) ?? []).length;
      if (digitCount >= 5) {
        hits.push({ ...locStart(n), message: `Long numeric literal '${raw}' — use numeric separators (e.g. '1_000_000')` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 39. throw-error-objects-not-strings
// ---------------------------------------------------------------------------

export const throwNonErrorChecker: FileChecker = {
  ruleId: "stylistic/operators/throw-error-objects-not-strings",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ThrowStatement") return;
      const arg = member(n, "argument");
      if (isStringLiteral(arg) || arg?.type === "TemplateLiteral") {
        hits.push({ ...locStart(n), message: "Throwing a string/template literal — throw an Error object instead" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 40. don-t-use-implicit-coercion-x-x-x
// ---------------------------------------------------------------------------

export const implicitCoercionChecker: FileChecker = {
  ruleId: "stylistic/operators/don-t-use-implicit-coercion-x-x-x",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "UnaryExpression") {
        const op = (n as AstNode & { operator?: string }).operator;
        const arg = member(n, "argument");
        if (op === "!" && arg?.type === "UnaryExpression" && (arg as AstNode & { operator?: string }).operator === "!") {
          hits.push({ ...locStart(n), message: "'!!x' implicit boolean coercion — use 'Boolean(x)'" });
        } else if (op === "+" && !isNumericLiteral(arg)) {
          hits.push({ ...locStart(n), message: "'+x' implicit number coercion — use 'Number(x)'" });
        }
      }
      if (n.type === "BinaryExpression" && (n as AstNode & { operator?: string }).operator === "+") {
        const left = member(n, "left");
        const right = member(n, "right");
        const isEmptyStr = (x: AstNode | undefined) => isStringLiteral(x) && x.value === "";
        if (isEmptyStr(left) || isEmptyStr(right)) {
          hits.push({ ...locStart(n), message: "'x + \"\"' implicit string coercion — use 'String(x)'" });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 41. avoid-mixed-operators-without-parentheses (approximation)
// ---------------------------------------------------------------------------

export const mixedOperatorsChecker: FileChecker = {
  ruleId: "stylistic/operators/avoid-mixed-operators-without-parentheses",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    // Approximation: parentheses don't appear as AST nodes, so an
    // already-parenthesized `(a && b) || c` is indistinguishable from an
    // unparenthesized one at this level — known limitation, may over-flag.
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "LogicalExpression") return;
      const op = (n as AstNode & { operator?: string }).operator;
      const left = member(n, "left");
      const right = member(n, "right");
      const mixed = (x: AstNode | undefined) => x?.type === "LogicalExpression" && (x as AstNode & { operator?: string }).operator !== op;
      if (mixed(left) || mixed(right)) {
        hits.push({ ...locStart(n), message: "Mixed '&&'/'||' without parentheses — add parentheses to make precedence explicit" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 42. no-yoda-conditions-variable-on-the-left
// ---------------------------------------------------------------------------

export const yodaConditionChecker: FileChecker = {
  ruleId: "stylistic/operators/no-yoda-conditions-variable-on-the-left",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "BinaryExpression") return;
      const op = (n as AstNode & { operator?: string }).operator;
      if (op !== "===" && op !== "!==" && op !== "==" && op !== "!=") return;
      const left = member(n, "left");
      const right = member(n, "right");
      if (left?.type === "Literal" && (right?.type === "Identifier" || right?.type === "MemberExpression")) {
        hits.push({ ...locStart(n), message: "Yoda condition — put the variable on the left ('x === literal')" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 43. operator-assignment-shorthand-x-1-not-x-x-1
// ---------------------------------------------------------------------------

export const operatorAssignmentChecker: FileChecker = {
  ruleId: "stylistic/operators/operator-assignment-shorthand-x-1-not-x-x-1",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "AssignmentExpression") return;
      if ((n as AstNode & { operator?: string }).operator !== "=") return;
      const left = member(n, "left");
      const right = member(n, "right");
      if (left?.type !== "Identifier" || right?.type !== "BinaryExpression") return;
      const name = identName(left);
      const rLeft = member(right, "left");
      const rRight = member(right, "right");
      const matches = (x: AstNode | undefined) => x?.type === "Identifier" && identName(x) === name;
      if (matches(rLeft) || matches(rRight)) {
        const op = (right as AstNode & { operator?: string }).operator;
        hits.push({ ...locStart(n), message: `'${name} = ${name} ${op} ...' — use the shorthand '${name} ${op}= ...'` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 44. use-dot-notation-when-the-property-name-is-a-valid-identifier
// ---------------------------------------------------------------------------

const VALID_IDENT_RE = /^[A-Za-z_$][A-Za-z0-9_$]*$/;

export const bracketNotationValidIdentChecker: FileChecker = {
  ruleId: "stylistic/operators/use-dot-notation-when-the-property-name-is-a-valid-identifier",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "MemberExpression") return;
      if (!(n as AstNode & { computed?: boolean }).computed) return;
      const prop = member(n, "property");
      if (isStringLiteral(prop) && VALID_IDENT_RE.test(prop.value)) {
        hits.push({ ...locStart(n), message: `"['${prop.value}']" is a valid identifier — use dot notation ('.${prop.value}')` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 45. no-unused-expressions-they-re-dead-code
// ---------------------------------------------------------------------------

const UNUSED_EXPRESSION_TYPES = new Set(["Identifier", "MemberExpression", "LogicalExpression", "BinaryExpression"]);

export const unusedExpressionChecker: FileChecker = {
  ruleId: "stylistic/operators/no-unused-expressions-they-re-dead-code",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ExpressionStatement") return;
      const expr = member(n, "expression");
      if (expr && UNUSED_EXPRESSION_TYPES.has(expr.type)) {
        hits.push({ ...locStart(n), message: `Unused expression statement ('${expr.type}') — likely dead code` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 46. prefer-template-literals-over-string-concatenation
// ---------------------------------------------------------------------------

export const stringConcatChecker: FileChecker = {
  ruleId: "stylistic/strings/prefer-template-literals-over-string-concatenation",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "BinaryExpression" || (n as AstNode & { operator?: string }).operator !== "+") return;
      const left = member(n, "left");
      const right = member(n, "right");
      const isStrLike = (x: AstNode | undefined) => isStringLiteral(x) || x?.type === "TemplateLiteral";
      if (isStrLike(left) || isStrLike(right)) {
        hits.push({ ...locStart(n), message: "String concatenation via '+' — prefer a template literal" });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 47. no-useless-concatenation-of-string-literals
// ---------------------------------------------------------------------------

export const literalConcatChecker: FileChecker = {
  ruleId: "stylistic/strings/no-useless-concatenation-of-string-literals",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "BinaryExpression" || (n as AstNode & { operator?: string }).operator !== "+") return;
      const left = member(n, "left");
      const right = member(n, "right");
      if (isStringLiteral(left) && isStringLiteral(right)) {
        hits.push({ ...locStart(n), message: "Two adjacent string literals joined with '+' — merge into one literal" });
      }
    });
    return hits;
  },
};
