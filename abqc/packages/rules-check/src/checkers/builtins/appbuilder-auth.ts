import type { CheckHit, FileChecker } from "../../types.js";
import { isActionFile } from "@abqc/core";

/**
 * STATIC (heuristic). Known limits: cannot cross-reference the action's web:yes/no
 * config from a JS-file-scoped check; flags any token-minting pattern in an
 * action file as a candidate finding regardless of web-action status —
 * recall-favoring, will over-flag non-web actions too.
 */
export const tokenActionNotWebChecker: FileChecker = {
  ruleId: "appbuilder/auth/token-generating-actions-must-be-non-web-actions",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    const mintCallRe = /\bjwt\.sign\s*\(|\bgetToken\s*\(|\bgenerateAccessToken\s*\(/;
    const randomAsTokenRe = /\b[\w.]*token[\w]*\s*[:=]\s*crypto\.(?:randomBytes|randomUUID)\s*\(/i;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (mintCallRe.test(line) || randomAsTokenRe.test(line)) {
        hits.push({
          line: i + 1,
          message:
            "Action appears to mint tokens (jwt.sign/getToken/generateAccessToken/crypto.randomBytes-as-token) — verify this is NOT a web action (web: 'no' + require-adobe-auth: false) since a web action minting tokens is directly exploitable.",
        });
      }
    }
    return hits;
  },
};

/**
 * STATIC (heuristic). Known limits: hand-rolled caching that doesn't match this exact
 * shape (e.g. cached in Redis) goes undetected; a legitimate non-IMS token
 * cache could match the shape, mitigated by requiring the file to also
 * reference /ims/i somewhere.
 */
export const imsAutoRenewalChecker: FileChecker = {
  ruleId: "appbuilder/auth/use-ims-sdk-auto-renewal-jwt-lifetime-is-24h",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const content = ctx.content;
    if (!/ims/i.test(content)) return [];
    // Legitimate SDK delegation — `getToken(...)`, `getImsAccessToken(...)`,
    // `.getAccessToken(...)` — is precisely the "use the IMS SDK's
    // auto-renewal" behavior this rule wants, not something to flag. But a
    // bare `.getAccessToken(` call is ambiguous: a file can hand-roll its OWN
    // method of that exact name (then call it via `this.getAccessToken()`),
    // which is the violation this rule exists to catch, not an exemption from
    // it. Only treat these calls as real SDK delegation when the file also
    // imports an actual IMS SDK package — otherwise a same-named hand-rolled
    // method's self-call would silently defeat this check.
    const importsImsSdk =
      /\bfrom\s+['"]@adobe\/aio-lib-ims['"]/.test(content) ||
      /require\(\s*['"]@adobe\/aio-lib-ims['"]\s*\)/.test(content) ||
      (/\bfrom\s+['"]@adobe\/aio-sdk['"]/.test(content) && /\bIms\b/.test(content));
    if (importsImsSdk && /\bgetToken\s*\(|\bgetImsAccessToken\s*\(|\.getAccessToken\s*\(/.test(content)) return [];
    const lines = content.split("\n");
    // Any ims-named call not already excluded above by the SDK-delegation
    // check — e.g. a hand-rolled `imsRawRequest()`. The known legitimate SDK
    // calls (getToken/getImsAccessToken/.getAccessToken) are already ruled
    // out by the early-exit above, so this can stay a simple substring match.
    const rawCallRe = /=\s*(?:await\s+)?[\w.]*ims[\w.]*\s*\(|=\s*(?:await\s+)?(?:fetch|axios(?:\.\w+)?)\s*\(/i;
    // `Date.now()` alone is also used for ordinary latency/perf logging —
    // only count it as an expiry check when actually compared against
    // something (`Date.now() > x`, `x < Date.now()`) or checked against an
    // `.expiresAt`/`.expiry` field.
    const hasExpiryComparison =
      /Date\.now\s*\(\)\s*[<>]=?|[<>]=?\s*Date\.now\s*\(\)|\.expiresAt\b|\.expiry\b/.test(content);
    if (!hasExpiryComparison) return [];
    const hits: CheckHit[] = [];
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (rawCallRe.test(line)) {
        hits.push({
          line: i + 1,
          message:
            "Hand-rolled token caching against a raw IMS/HTTP call with manual expiry comparison — use the IMS SDK's auto-renewal instead of reimplementing it (JWT lifetime is 24h).",
        });
      }
    }
    return hits;
  },
};

/**
 * STATIC (heuristic). Known limits: a differently-named local wrapper around the
 * same raw parsing evades the literal-name check.
 */
export const bearerExtractionChecker: FileChecker = {
  ruleId: "appbuilder/auth/bearer-extraction-via-getbearertoken-params-utility",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    if (/\bgetBearerToken\s*\(/.test(ctx.content)) return [];
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    const rawHeaderRe = /params\s*\??\.\s*__ow_headers\s*\??\.\s*authorization/i;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (rawHeaderRe.test(line)) {
        hits.push({
          line: i + 1,
          message:
            "Raw params.__ow_headers.authorization access/split — use the shared getBearerToken(params) utility instead of hand-parsing the header.",
        });
      }
    }
    return hits;
  },
};
