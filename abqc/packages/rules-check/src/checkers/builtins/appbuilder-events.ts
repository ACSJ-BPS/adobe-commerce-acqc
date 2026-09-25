import type { FileChecker } from "../../types.js";
import { isActionFile } from "@abqc/core";
import { findFunctions, walkAst, type AstNode } from "../../utils/parse.js";
import {
  requireParsed,
  isCheckHitArray,
  member,
  identName,
  skipNonEntryAction,
  isIoEventsConsumer,
  isRawWebhookReceiver,
} from "./shared.js";

/**
 * Coarse path pre-filter used only as a cheap `appliesTo` gate. It can only
 * see the file path (not resolved config), so every checker that uses it MUST
 * additionally narrow inside `check()` — via `isIoEventsConsumer(ctx)` for
 * asynchronous I/O Events consumer rules, or `isRawWebhookReceiver(ctx)` for
 * raw third-party webhook rules — to avoid the false positives a path-only
 * heuristic produces on synchronous Commerce webhooks and plain lib files.
 */
export function isEventsHandlerPath(filePath: string): boolean {
  const p = filePath.replace(/\\/g, "/");
  if (!/(^|\/)actions\//.test(p)) return false;
  return /events?|webhook|handler/i.test(p);
}

function referencesMemberPath(node: AstNode, objName: string, propName: string): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (found) return;
    if (n.type !== "MemberExpression") return;
    const obj = member(n, "object");
    const prop = member(n, "property");
    const objectMatches =
      identName(obj) === objName ||
      (obj?.type === "MemberExpression" && identName(member(obj, "object")) === objName);
    const propMatches =
      identName(prop) === propName ||
      (prop?.type === "Literal" && (prop as AstNode & { value?: unknown }).value === propName);
    if (objectMatches && propMatches) found = true;
  });
  return found;
}

function findMainBody(ast: AstNode): AstNode | null {
  const main = findFunctions(ast).find((f) => f.name === "main");
  return main?.node.body ?? null;
}

/**
 * STATIC. Challenge-response is a *raw third-party webhook* handshake (Slack
 * URL verification, etc.), not something Adobe-authenticated I/O Events
 * consumers implement. Gated to files that show raw-webhook evidence
 * (`isRawWebhookReceiver`) so it no longer fires on synchronous Commerce
 * webhooks or Adobe I/O Events consumers.
 */
export const eventsChallengeResponseChecker: FileChecker = {
  ruleId: "appbuilder/events/implement-challenge-response-for-i-o-events-handler-actions",
  kind: "file",
  appliesTo: isEventsHandlerPath,
  check(ctx) {
    if (skipNonEntryAction(ctx)) return [];
    if (!isRawWebhookReceiver(ctx)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const body = findMainBody(parsed.ast);
    if (!body) return [];
    let hasChallengeIf = false;
    walkAst(body, (n) => {
      if (hasChallengeIf) return;
      if (n.type !== "IfStatement") return;
      const test = member(n, "test");
      if (test && referencesMemberPath(test, "params", "challenge")) hasChallengeIf = true;
    });
    if (!hasChallengeIf) {
      return [
        {
          line: 1,
          message:
            "Raw third-party webhook receiver has no conditional checking params.challenge — implement the challenge-response handshake required by the webhook registration flow.",
        },
      ];
    }
    return [];
  },
};

/**
 * STATIC. HMAC signature verification applies to *raw third-party webhooks*
 * that deliver a provider signature header — not to Adobe-authenticated I/O
 * Events consumers or synchronous Commerce webhooks. Gated to
 * `isRawWebhookReceiver`, which keys on the provider signature headers, so it
 * no longer fires on every events/webhook-path file.
 */
export const eventsHmacVerifyChecker: FileChecker = {
  ruleId: "appbuilder/events/verify-i-o-events-payload-hmac-signature-before-processing",
  kind: "file",
  appliesTo: isEventsHandlerPath,
  check(ctx) {
    if (skipNonEntryAction(ctx)) return [];
    if (!isRawWebhookReceiver(ctx)) return [];
    if (/\btimingSafeEqual\s*\(|\bcreateHmac\s*\(/.test(ctx.content)) return [];
    return [
      {
        line: 1,
        message:
          "Raw third-party webhook receiver has no timingSafeEqual(/createHmac( call anywhere — verify the payload HMAC signature before processing the webhook payload.",
      },
    ];
  },
};

/**
 * STATIC. Flags an action that performs a network call inside a loop (AST:
 * loop node containing a fetch/axios/http.request call) or lives on a
 * poll-named path — a deterministic signal of polling. Fully static, no AI.
 */
export const eventsVsPollingChecker: FileChecker = {
  ruleId: "appbuilder/events/prefer-i-o-events-over-polling-actions-on-cron-schedules",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const networkCallRe = /^fetch$|axios|^http\.request$|^https\.request$/i;
    if (!/\b(fetch|axios(?:\.\w+)?|http\.request|https\.request)\s*\(/i.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    let hasLoopWithNetworkCall = false;
    if (!isCheckHitArray(parsed)) {
      const loopTypes = new Set(["ForStatement", "ForInStatement", "ForOfStatement", "WhileStatement", "DoWhileStatement"]);
      walkAst(parsed.ast, (n) => {
        if (hasLoopWithNetworkCall || !loopTypes.has(n.type)) return;
        let hasCall = false;
        walkAst(n, (inner) => {
          if (hasCall) return;
          if (inner.type === "CallExpression") {
            const callee = member(inner, "callee");
            const text = calleeText(callee);
            if (networkCallRe.test(text)) hasCall = true;
          }
        });
        if (hasCall) hasLoopWithNetworkCall = true;
      });
    }
    const isPollPath = /poll/i.test(ctx.filePath);
    if (hasLoopWithNetworkCall || isPollPath) {
      return [
        {
          line: 1,
          message:
            "Action polls an external source in a loop/cron-scheduled action — prefer I/O Events over polling actions on cron schedules.",
        },
      ];
    }
    return [];
  },
};

function calleeText(node: AstNode | undefined): string {
  if (!node) return "";
  if (node.type === "Identifier") return identName(node) ?? "";
  if (node.type === "MemberExpression") {
    const obj = calleeText(member(node, "object"));
    const prop = member(node, "property");
    const propName = prop?.type === "Identifier" ? (identName(prop) ?? "") : "";
    return obj ? `${obj}.${propName}` : propName;
  }
  return "";
}

/**
 * STATIC. Gated on a concrete third-party provider signature header
 * (x-slack-signature / x-hub-signature / stripe-signature); flags only when
 * the handler that receives such a webhook is missing the challenge-response
 * and/or HMAC verification. Fully static, no AI.
 */
export const webhookProxyChecker: FileChecker = {
  ruleId: "appbuilder/events/proxy-external-3rd-party-webhooks-through-an-action-that-handles",
  kind: "file",
  appliesTo: isEventsHandlerPath,
  check(ctx) {
    if (skipNonEntryAction(ctx)) return [];
    const has3rdPartySignatureHeader = /x-slack-signature|x-hub-signature|stripe-signature/i.test(ctx.content);
    if (!has3rdPartySignatureHeader) return [];
    const parsed = requireParsed(ctx);
    let hasChallenge = false;
    if (!isCheckHitArray(parsed)) {
      const body = findMainBody(parsed.ast);
      if (body) {
        walkAst(body, (n) => {
          if (hasChallenge) return;
          if (n.type !== "IfStatement") return;
          const test = member(n, "test");
          if (test && referencesMemberPath(test, "params", "challenge")) hasChallenge = true;
        });
      }
    }
    const hasHmac = /\btimingSafeEqual\s*\(|\bcreateHmac\s*\(/.test(ctx.content);
    if (!hasChallenge || !hasHmac) {
      return [
        {
          line: 1,
          message:
            "3rd-party webhook signature header referenced but this handler doesn't also implement challenge-response and HMAC verification — proxy external webhooks through an action that handles the auth handshake and signature check.",
        },
      ];
    }
    return [];
  },
};

/**
 * STATIC. Idempotency keyed by CloudEvents `event_id` applies to genuine
 * asynchronous I/O Events consumers (which read `params.event_id`), not to
 * synchronous Commerce webhooks. Gated to `isIoEventsConsumer` so it no
 * longer fires on `actions/webhook/*` files. Detects State-SDK idempotency
 * (`state.get(...)`); a consumer that records processed ids elsewhere (DB)
 * is intentionally not flagged.
 */
export const eventsIdempotentChecker: FileChecker = {
  ruleId: "appbuilder/events/i-o-events-handlers-must-be-idempotent-keyed-by-event-id",
  kind: "file",
  appliesTo: isEventsHandlerPath,
  check(ctx) {
    if (!isIoEventsConsumer(ctx)) return [];
    if (/\b[Ss]tate\.get\s*\(/.test(ctx.content)) return [];
    return [
      {
        line: 1,
        message:
          "I/O Events consumer action has no state.get( call — handlers must be idempotent, keyed by event id, using the State SDK to record processed events.",
      },
    ];
  },
};

/**
 * STATIC. Batch-vs-per-event applies to asynchronous I/O Events consumers.
 * Gated to `isIoEventsConsumer` AND requires actual per-event synchronous
 * HTTP evidence (a `fetch`/`axios` call) — a consumer that only writes to
 * State or logs is not flagged. No longer fires on every handler-path file.
 */
export const eventsBatchVsSyncChecker: FileChecker = {
  ruleId: "appbuilder/events/batch-high-volume-event-streams-via-journaling-not-per-event-syn",
  kind: "file",
  appliesTo: isEventsHandlerPath,
  check(ctx) {
    if (!isIoEventsConsumer(ctx)) return [];
    if (/batch|journal/i.test(ctx.filePath)) return [];
    // Require evidence of a per-event synchronous HTTP call; otherwise there is
    // nothing to batch and flagging would be noise.
    if (!/\b(fetch|axios(?:\.\w+)?|http\.request|https\.request)\s*\(/i.test(ctx.content)) return [];
    return [
      {
        line: 1,
        message:
          "I/O Events consumer makes a per-event synchronous HTTP call — for high-volume streams, batch via journaling (I/O Events journaling API) rather than per-event synchronous HTTP.",
      },
    ];
  },
};
