import type { CheckHit, FileChecker } from "../../types.js";
import { requireParsed, isCheckHitArray, isJsxCapableFile } from "./shared.js";
import { walkAst, locStart, type AstNode } from "../../utils/parse.js";

function member(n: AstNode, key: string): AstNode | undefined {
  const v = (n as Record<string, unknown>)[key];
  return v && typeof v === "object" && "type" in (v as object) ? (v as AstNode) : undefined;
}

function jsxTagName(n: AstNode | undefined): string | undefined {
  if (!n || n.type !== "JSXIdentifier") return undefined;
  return (n as AstNode & { name?: string }).name;
}

// ---------------------------------------------------------------------------
// 25. a11y/content/anchors-must-have-href-and-meaningful-text — vague-link-text
// ---------------------------------------------------------------------------

const VAGUE_LINK_TEXTS = new Set(["click here", "here", "link", "read more"]);

export const vagueLinkTextChecker: FileChecker = {
  ruleId: "a11y/content/anchors-must-have-href-and-meaningful-text",
  kind: "file",
  appliesTo: isJsxCapableFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;

    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXElement") return;
      const opening = member(n, "openingElement");
      const nameNode = opening ? member(opening, "name") : undefined;
      if (jsxTagName(nameNode) !== "a") return;

      const children = (n as AstNode & { children?: AstNode[] }).children ?? [];
      const text = children
        .filter((c) => c.type === "JSXText")
        .map((c) => (c as AstNode & { value?: string }).value ?? "")
        .join("")
        .trim()
        .toLowerCase();

      if (VAGUE_LINK_TEXTS.has(text)) {
        hits.push({
          ...locStart(n),
          message: `<a> link text '${text}' is not meaningful out of context — use descriptive text (e.g. the destination or action)`,
        });
      }
    });
    return hits;
  },
};
