import { parse } from "@typescript-eslint/parser";

/** Minimal ESTree-compatible node shape from @typescript-eslint/parser. */
export type AstNode = {
  type: string;
  loc?: { start: { line: number; column: number }; end: { line: number; column: number } } | null;
  [key: string]: unknown;
};

export type FunctionLike = AstNode & {
  type: "FunctionDeclaration" | "FunctionExpression" | "ArrowFunctionExpression";
  params: AstNode[];
  body?: AstNode | null;
};

export type ParsedSource = {
  ast: AstNode;
  lines: string[];
};

export function parseSource(content: string, filePath: string): ParsedSource | null {
  try {
    const isJsx = /\.(jsx|tsx)$/.test(filePath);
    const ast = parse(content, {
      ecmaVersion: "latest",
      sourceType: "module",
      ecmaFeatures: { jsx: isJsx },
      loc: true,
      range: true,
      comment: true,
    }) as unknown as AstNode;
    return { ast, lines: content.split("\n") };
  } catch {
    return null;
  }
}

export function locStart(node: AstNode): { line: number; column: number } | undefined {
  if (!node.loc) return undefined;
  return { line: node.loc.start.line, column: node.loc.start.column + 1 };
}

export function walkAst(node: AstNode, visit: (n: AstNode) => void): void {
  visit(node);
  for (const key of Object.keys(node)) {
    if (key === "loc" || key === "range" || key === "parent") continue;
    const val = node[key];
    if (!val || typeof val !== "object") continue;
    if (Array.isArray(val)) {
      for (const item of val) {
        if (item && typeof item === "object" && "type" in item) walkAst(item as AstNode, visit);
      }
    } else if ("type" in val) {
      walkAst(val as AstNode, visit);
    }
  }
}

function asFunctionLike(n: AstNode | null | undefined): FunctionLike | null {
  if (!n) return null;
  if (n.type === "FunctionDeclaration" || n.type === "FunctionExpression" || n.type === "ArrowFunctionExpression") {
    return n as FunctionLike;
  }
  return null;
}

export function findFunctions(ast: AstNode): Array<{ name: string; node: FunctionLike }> {
  const fns: Array<{ name: string; node: FunctionLike }> = [];
  walkAst(ast, (n) => {
    if (n.type === "FunctionDeclaration" && (n as AstNode & { id?: { name?: string } }).id?.name) {
      const fn = asFunctionLike(n);
      if (fn) fns.push({ name: (n as AstNode & { id: { name: string } }).id.name, node: fn });
    }
    // Arrow functions and function expressions bound to a name — `const f = () => {}`,
    // `let g = function() {}`, and (since ExportNamedDeclaration's declaration is a
    // VariableDeclaration containing these same VariableDeclarators) `export const
    // main = async () => {}`. Without this, ALL arrow-function code — including the
    // common App Builder action style `export const main = async () => {}` — was
    // invisible to every complexity/length/params/nesting check and to main-handler
    // detection (hasMainFunction/hasExportsMain), silently skipping real code.
    if (n.type === "VariableDeclarator") {
      const d = n as AstNode & { id?: AstNode; init?: AstNode };
      if (d.id?.type === "Identifier") {
        const fn = asFunctionLike(d.init);
        if (fn) fns.push({ name: (d.id as AstNode & { name: string }).name, node: fn });
      }
    }
    // Object-literal / class methods — `{ handler() {} }`, `{ run: () => {} }`,
    // `class X { handle() {} }` — named by their key/property identifier.
    if (n.type === "Property" || n.type === "MethodDefinition") {
      const p = n as AstNode & { key?: AstNode; value?: AstNode };
      const fn = asFunctionLike(p.value);
      const name = keyName(p.key);
      if (fn && name) fns.push({ name, node: fn });
    }
    if (
      n.type === "AssignmentExpression" &&
      (n as AstNode & { left: AstNode; right: AstNode }).left.type === "MemberExpression"
    ) {
      const left = (n as AstNode & { left: AstNode & { object?: AstNode; property?: AstNode } }).left;
      const right = (n as AstNode & { right: AstNode }).right;
      if (
        left.object?.type === "Identifier" &&
        (left.object as AstNode & { name: string }).name === "exports" &&
        left.property?.type === "Identifier" &&
        (left.property as AstNode & { name: string }).name === "main"
      ) {
        const fn =
          right.type === "Identifier"
            ? findFunctionByName(ast, (right as AstNode & { name: string }).name)
            : asFunctionLike(right);
        if (fn) fns.push({ name: "main", node: fn });
      }
    }
    if (
      n.type === "ExportNamedDeclaration" &&
      (n as AstNode & { declaration?: AstNode }).declaration?.type === "FunctionDeclaration"
    ) {
      const decl = (n as AstNode & { declaration: AstNode & { id?: { name?: string } } }).declaration;
      if (decl.id?.name === "main") {
        const fn = asFunctionLike(decl);
        if (fn) fns.push({ name: "main", node: fn });
      }
    }
  });
  // De-dupe by node identity — a `main` reached both by its declaration site
  // (e.g. `export const main = ...`) and by a special-cased lookup (e.g. an
  // `exports.main = main` assignment referencing the same node) must not be
  // reported — and counted for length/complexity/nesting — twice.
  const seen = new Set<FunctionLike>();
  return fns.filter(({ node }) => {
    if (seen.has(node)) return false;
    seen.add(node);
    return true;
  });
}

function keyName(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier") return (n as AstNode & { name: string }).name;
  if (n.type === "Literal") return String((n as AstNode & { value: unknown }).value);
  return undefined;
}

function findFunctionByName(ast: AstNode, name: string): FunctionLike | null {
  let found: FunctionLike | null = null;
  walkAst(ast, (n) => {
    if (found) return;
    if (n.type === "FunctionDeclaration" && (n as AstNode & { id?: { name?: string } }).id?.name === name) {
      found = asFunctionLike(n);
    }
    if (n.type === "VariableDeclarator") {
      const d = n as AstNode & { id?: AstNode; init?: AstNode };
      if (d.id?.type === "Identifier" && (d.id as AstNode & { name: string }).name === name) {
        found = asFunctionLike(d.init ?? null);
      }
    }
  });
  return found;
}

export function functionBodyHasTryCatch(fn: FunctionLike): boolean {
  const body = fn.body;
  if (!body || body.type !== "BlockStatement") return true;
  const stmts = (body as AstNode & { body: AstNode[] }).body ?? [];
  if (stmts.some((s) => s.type === "TryStatement")) return true;
  let hasTry = false;
  walkAst(body, (n) => {
    if (n.type === "TryStatement") hasTry = true;
  });
  return hasTry;
}

export function countFunctionLines(fn: FunctionLike): number {
  if (!fn.loc) return 0;
  return fn.loc.end.line - fn.loc.start.line + 1;
}

export function countParams(fn: FunctionLike): number {
  return fn.params.length;
}

// CatchClause is deliberately excluded: it's a sibling branch of the same
// TryStatement, not an additional nesting level — counting both meant every
// try/catch was penalized twice as deep as an equivalent if/for/while.
const NESTING_TYPES = new Set([
  "IfStatement", "ForStatement", "ForInStatement", "ForOfStatement",
  "WhileStatement", "DoWhileStatement", "SwitchStatement", "TryStatement",
]);

const FUNCTION_TYPES = new Set([
  "FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression",
]);

/**
 * Walks `root`'s subtree like `walkAst`, but does NOT descend into a nested
 * function's body (a closure defined inside `root`) — only `root` itself is
 * ever treated as a function boundary that's walked through. Shared by
 * `maxNestingDepth` and `cyclomaticComplexity` so a function's score reflects
 * its OWN branching, not the branching of every callback/closure it contains.
 */
function walkWithinFunction(root: AstNode, visit: (n: AstNode) => void): void {
  const go = (n: AstNode) => {
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

export function maxNestingDepth(root: AstNode): number {
  return maxNestingDepthRecursive(root, root, 0);
}

// Needs per-branch depth (not a single running counter — a running counter
// can't decrement back down when a recursive call returns to a sibling
// branch), so this doesn't reuse the visit-only walkWithinFunction helper.
function maxNestingDepthRecursive(node: AstNode, root: AstNode, depth: number): number {
  let max = depth;
  for (const key of Object.keys(node)) {
    if (key === "loc" || key === "range" || key === "parent") continue;
    const val = node[key];
    if (!val || typeof val !== "object") continue;
    const children = Array.isArray(val) ? val : [val];
    for (const item of children) {
      if (!item || typeof item !== "object" || !("type" in item)) continue;
      const child = item as AstNode;
      if (FUNCTION_TYPES.has(child.type) && child !== root) continue;
      const childDepth = NESTING_TYPES.has(child.type) ? depth + 1 : depth;
      const sub = maxNestingDepthRecursive(child, root, childDepth);
      if (sub > max) max = sub;
    }
  }
  return max;
}

export function cyclomaticComplexity(fn: FunctionLike): number {
  let complexity = 1;
  walkWithinFunction(fn, (n) => {
    if (n.type === "IfStatement") complexity++;
    else if (["ForStatement", "ForInStatement", "ForOfStatement", "WhileStatement", "DoWhileStatement"].includes(n.type)) complexity++;
    else if (n.type === "CatchClause") complexity++;
    else if (n.type === "ConditionalExpression") complexity++;
    else if (n.type === "LogicalExpression") {
      const op = (n as AstNode & { operator?: string }).operator;
      if (op === "&&" || op === "||") complexity++;
    } else if (n.type === "SwitchCase" && (n as AstNode & { test?: AstNode | null }).test) complexity++;
  });
  return complexity;
}

export function countNonBlankNonCommentLines(content: string): number {
  const withoutBlock = content.replace(/\/\*[\s\S]*?\*\//g, "");
  return withoutBlock
    .split("\n")
    .filter((line) => {
      const t = line.trim();
      return t.length > 0 && !t.startsWith("//");
    }).length;
}

export function hasExportsMain(content: string, ast: AstNode): boolean {
  if (
    /exports\.main\s*=/.test(content) ||
    /module\.exports\.main\s*=/.test(content) ||
    // Object-literal export form: `module.exports = { main }` / `{ main: main }` / `{ main, ...other }`.
    /module\.exports\s*=\s*\{[^}]*\bmain\b/.test(content) ||
    // ESM named export: `export { main }`.
    /export\s*\{[^}]*\bmain\b/.test(content)
  ) {
    return true;
  }
  let found = false;
  walkAst(ast, (n) => {
    if (n.type === "ExportNamedDeclaration") {
      const decl = (n as AstNode & { declaration?: AstNode }).declaration;
      if (decl?.type === "FunctionDeclaration" && (decl as AstNode & { id?: { name?: string } }).id?.name === "main") {
        found = true;
      }
    }
  });
  return found;
}

export function hasMainFunction(content: string, ast: AstNode): boolean {
  if (/\b(async\s+)?function\s+main\s*\(/.test(content)) return true;
  if (/exports\.main\s*=/.test(content)) return true;
  return findFunctions(ast).some((f) => f.name === "main");
}
