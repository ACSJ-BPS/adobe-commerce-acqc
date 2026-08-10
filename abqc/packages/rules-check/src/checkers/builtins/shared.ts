import type { CheckHit, CheckContext } from "../../types.js";
import {
  findFunctions,
  locStart,
  maxNestingDepth,
  parseSource,
  walkAst,
  type AstNode,
  type FunctionLike,
} from "../../utils/parse.js";

export type ParsedSource = NonNullable<ReturnType<typeof parseSource>>;

export function skipNonEntryAction(ctx: CheckContext): boolean {
  const entries = ctx.actionEntryFiles;
  return !!entries && entries.size > 0 && !entries.has(ctx.filePath);
}

export function requireParsed(ctx: CheckContext): ParsedSource | CheckHit[] {
  const parsed = parseSource(ctx.content, ctx.filePath);
  if (!parsed) {
    return [{ line: 1, message: "File could not be parsed — fix syntax errors before running AST checks" }];
  }
  return parsed;
}

export function isCheckHitArray(v: unknown): v is CheckHit[] {
  return Array.isArray(v);
}

export function member(n: AstNode, key: string): AstNode | undefined {
  const v = n[key];
  return v && typeof v === "object" && "type" in v ? (v as AstNode) : undefined;
}

export function identName(n: AstNode | undefined): string | undefined {
  if (!n || n.type !== "Identifier") return undefined;
  return (n as AstNode & { name: string }).name;
}

export function extractJsdocBlock(lines: string[], endLine: number): string {
  const parts: string[] = [];
  for (let i = endLine - 2; i >= 0 && i >= endLine - 20; i--) {
    const t = lines[i]?.trim() ?? "";
    parts.unshift(lines[i] ?? "");
    if (t.startsWith("/**")) break;
  }
  return parts.join("\n");
}

export function jsdocIsValid(block: string): boolean {
  if (!block.includes("/**") || !block.includes("*/")) return false;
  const inner = block.replace(/^\/\*\*|\*\/$/g, "").replace(/^\s*\*\s?/gm, "").trim();
  if (inner.length < 8) return false;
  if (!/@param\b/.test(block)) return false;
  if (!/@returns?\b/.test(block)) return false;
  return true;
}

export function complexityHits(
  ctx: CheckContext,
  pred: (fn: FunctionLike) => boolean,
  message: string,
): CheckHit[] {
  const parsed = requireParsed(ctx);
  if (isCheckHitArray(parsed)) return parsed;
  const hits: CheckHit[] = [];
  const seen = new Set<FunctionLike>();
  const consider = (name: string, node: FunctionLike): void => {
    if (seen.has(node)) return;
    seen.add(node);
    if (pred(node)) {
      hits.push({ ...locStart(node), message: `${message}${name ? ` (${name})` : ""}` });
    }
  };
  for (const { name, node } of findFunctions(parsed.ast)) {
    consider(name, node);
  }
  walkAst(parsed.ast, (n) => {
    if (n.type === "FunctionDeclaration") {
      const id = member(n, "id");
      const name = identName(id);
      if (name) consider(name, n as FunctionLike);
    }
  });
  return hits;
}

export { maxNestingDepth };
