import type { CheckHit, CheckContext } from "../../types.js";
import { isSourceFile } from "@abqc/core";
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

/**
 * True when the file lives under a Commerce/synchronous `webhook` package
 * (`actions/webhook/...`). Adobe Commerce "webhooks" are *synchronous* REST
 * hooks that return `{ statusCode, body: <patch ops | exception> }` — a
 * distinct contract from asynchronous Adobe I/O Events consumers. The I/O
 * Events-specific rules (challenge-response handshake, payload-HMAC
 * verification, idempotency keyed by CloudEvents `event_id`, journaling /
 * batch-vs-sync) do NOT apply to synchronous Commerce webhooks, so those
 * checkers must exclude these files to avoid false positives.
 */
export function isCommerceWebhookAction(ctx: CheckContext): boolean {
  const p = ctx.filePath.replace(/\\/g, "/");
  return /(^|\/)actions\/webhook\//i.test(p);
}

/**
 * True when the file returns an HTTP response object (`return { statusCode:
 * ... }`) — the shape of a synchronous web action / Commerce REST webhook,
 * as opposed to an asynchronous I/O Events consumer whose result is not an
 * HTTP envelope. Purely static (regex over source, no execution).
 */
export function returnsHttpEnvelope(content: string): boolean {
  return /\breturn\s*\{[\s\S]{0,400}?\bstatusCode\s*:/.test(content);
}

/**
 * True when the file reads the Adobe I/O Events / CloudEvents envelope id
 * (`params.event_id`, `params.eventid`, or bracket access). Only asynchronous
 * I/O Events delivery populates this field, so it is a reliable positive
 * signal that the action is an I/O Events consumer — unlike `params.data` /
 * `params.type`, which synchronous Commerce webhooks also read.
 */
export function readsIoEventEnvelope(content: string): boolean {
  return /params\s*(?:\.\s*event_?id\b|\[\s*['"]event_?id['"]\s*\])/i.test(content);
}

/**
 * Classify a file as a genuine asynchronous Adobe I/O Events consumer:
 * an action entry file (when config is in scope) that is NOT a synchronous
 * Commerce webhook and shows positive evidence of reading the I/O Events
 * envelope id. Conservative by design — under-flagging an exotic layout is
 * preferred over the false positives the old path-only heuristic produced.
 */
export function isIoEventsConsumer(ctx: CheckContext): boolean {
  if (skipNonEntryAction(ctx)) return false;
  const p = ctx.filePath.replace(/\\/g, "/");
  if (!/(^|\/)actions\//.test(p)) return false;
  if (isCommerceWebhookAction(ctx)) return false;
  const eventPath = /(^|\/)(events?|consumer|subscriber)(?:[/_.-]|$)/i.test(p);
  return readsIoEventEnvelope(ctx.content) || eventPath;
}

/**
 * Positive evidence that an action receives a RAW third-party webhook (Slack,
 * GitHub, Stripe, …) and therefore must implement its own challenge-response
 * handshake and HMAC signature verification. Keyed on the provider signature
 * headers those integrations send or an explicit `params.challenge` read.
 */
export function isRawWebhookReceiver(ctx: CheckContext): boolean {
  if (skipNonEntryAction(ctx)) return false;
  const c = ctx.content;
  return (
    /x-slack-signature|x-hub-signature|stripe-signature|x-webhook-signature|verification[_-]?token/i.test(c) ||
    /params\s*(?:\.\s*challenge\b|\[\s*['"]challenge['"]\s*\])/i.test(c)
  );
}

/**
 * Files that may contain JSX. `aio app init`'s standard scaffold and every
 * official Adobe sample write JSX inside plain `.js` files, not `.jsx` — a
 * `.jsx`/`.tsx`-only extension check (the historical convention this codebase
 * used to follow) misses the majority of real App Builder/Admin-UI-SDK
 * component files. Kept in sync with `shouldParseAsJsx` in `utils/parse.ts`.
 */
export const JSX_CAPABLE_FILE_RE = /\.(jsx|tsx|js|mjs|cjs)$/i;

export function isJsxCapableFile(filePath: string): boolean {
  return JSX_CAPABLE_FILE_RE.test(filePath);
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
  let i = endLine - 2;
  // Blank lines are allowed directly between the JSDoc's closing `*/` and the declaration.
  while (i >= 0 && (lines[i]?.trim() ?? "") === "") i--;
  // No fixed line cap: a docblock with `@example` sections can easily run past
  // 20 lines, and stopping early made a genuinely documented function look
  // undocumented. Walk back until the opening `/**` (block found) or until a
  // line that isn't a `*`-prefixed comment continuation (no docblock directly
  // above this declaration — stop rather than scanning past unrelated code).
  for (; i >= 0; i--) {
    const raw = lines[i] ?? "";
    const t = raw.trim();
    parts.unshift(raw);
    if (t.startsWith("/**")) break;
    if (!t.startsWith("*")) {
      parts.shift();
      break;
    }
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

// ---------------------------------------------------------------------------
// API Mesh path predicates — single canonical source. Previously duplicated
// independently in apimesh.ts and gap-fill-mesh.ts, which is exactly how they
// drifted: one recognized only `meshConfig.json` (missing Adobe's own
// `mesh.json` scaffolding default) and both matched "resolver"/"hook" as a
// bare substring anywhere in the path (flagging unrelated files — an I/O
// Events publisher, or a React `useXxx` data hook — that merely contain that
// English word).
// ---------------------------------------------------------------------------

/** Adobe API Mesh's own scaffolding names this `mesh.json`; older docs/templates call it
 * `meshConfig.json` — accept either, plus env-suffixed variants (`mesh.prod.json`). */
export function isMeshConfigPath(p: string): boolean {
  return /(^|\/)(?:meshConfig|mesh)(\..+)?\.json$/i.test(p.replace(/\\/g, "/"));
}

const REACT_HOOK_NAME_RE = /(^|\/)use[A-Z][^/]*\.[jt]sx?$/;

function hasMeshPathSegment(p: string, names: string[]): boolean {
  const norm = p.replace(/\\/g, "/");
  const segments = norm.split("/");
  const base = (segments[segments.length - 1] ?? "").replace(/\.[jt]sx?$/i, "");
  return segments.some((s) => names.includes(s.toLowerCase())) || names.includes(base.toLowerCase());
}

/** A real mesh resolver or hook file — under a `resolvers/`/`hooks/` directory, or
 * named `resolver.js`/`hook.js` outright. Excludes React-hook-named files (`useXxx.js`)
 * so client-side data-fetching hooks are never mistaken for API Mesh hooks. */
export function isResolverOrHookPath(p: string): boolean {
  return (
    !REACT_HOOK_NAME_RE.test(p.replace(/\\/g, "/")) &&
    isSourceFile(p) &&
    hasMeshPathSegment(p, ["resolver", "resolvers", "hook", "hooks"])
  );
}

export function isHookPath(p: string): boolean {
  return (
    !REACT_HOOK_NAME_RE.test(p.replace(/\\/g, "/")) && isSourceFile(p) && hasMeshPathSegment(p, ["hook", "hooks"])
  );
}

export function isResolverPath(p: string): boolean {
  return isSourceFile(p) && hasMeshPathSegment(p, ["resolver", "resolvers"]);
}
