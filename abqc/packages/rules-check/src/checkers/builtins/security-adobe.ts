import { readFile } from "node:fs/promises";
import { parse as parseYaml } from "yaml";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isActionFile, isSourceFile } from "@abqc/core";
import { requireParsed, isCheckHitArray } from "./shared.js";
import { walkAst, locStart, findFunctions, type AstNode } from "../../utils/parse.js";

function member(n: AstNode, key: string): AstNode | undefined {
  const v = n[key];
  return v && typeof v === "object" && "type" in v ? (v as AstNode) : undefined;
}

function identName(n: AstNode | undefined): string | undefined {
  if (!n || n.type !== "Identifier") return undefined;
  return (n as AstNode & { name: string }).name;
}

function keyNameOf(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier") return identName(n);
  if (n.type === "Literal") return String((n as AstNode & { value: unknown }).value);
  return undefined;
}

function rootIdentifier(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier") return identName(n);
  if (n.type === "MemberExpression") return rootIdentifier(member(n, "object"));
  return undefined;
}

function isParamsRef(n: AstNode | undefined): boolean {
  if (!n) return false;
  if (n.type === "Identifier") return identName(n) === "params";
  if (n.type === "MemberExpression") return isParamsRef(member(n, "object"));
  return false;
}

function templateLiteralText(n: AstNode): string {
  const quasis = (n as AstNode & { quasis: AstNode[] }).quasis ?? [];
  return quasis
    .map((q) => String((q as AstNode & { value?: { raw?: string; cooked?: string } }).value?.raw ?? ""))
    .join(" ");
}

/**
 * STATIC (heuristic) (P1). Parses the YAML with the `yaml` package to find an
 * explicit `annotations.require-adobe-auth: false` (an absent annotation is
 * a different, separately-covered rule). Comments aren't preserved by
 * `parse()`, so "is this documented" is answered by scanning the raw text
 * lines around the matched setting for a review/ticket/approved/documented
 * comment (inline or on one of the two lines above), the same pattern used
 * by the disable-download checker. Known limits: gameable by adding any
 * matching comment text regardless of whether a real review happened; a
 * `require-adobe-auth: false` set via a YAML anchor/merge key referenced
 * from elsewhere in the file won't have a nearby comment to find and will
 * always be flagged even if documented at the anchor site.
 */
export const requireAuthFalseUndocumentedChecker: FileChecker = {
  ruleId: "security/adobe/do-not-disable-require-adobe-auth-without-a-documented-review",
  kind: "file",
  appliesTo: (p) => /\.(config\.)?ya?ml$/i.test(p),
  check(ctx) {
    let doc: unknown;
    try {
      doc = parseYaml(ctx.content);
    } catch {
      return [];
    }
    if (!hasExplicitRequireAuthFalse(doc)) return [];

    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    const falseLineRe = /require-adobe-auth\s*:\s*['"]?false['"]?/i;
    const reviewRe = /review|ticket|approved|documented/i;
    for (let i = 0; i < lines.length; i++) {
      const raw = lines[i] ?? "";
      if (raw.trim().startsWith("#")) continue;
      if (!falseLineRe.test(raw)) continue;
      const inlineComment = raw.includes("#") ? raw.slice(raw.indexOf("#")) : "";
      const above1 = lines[i - 1] ?? "";
      const above2 = lines[i - 2] ?? "";
      const documented =
        (inlineComment && reviewRe.test(inlineComment)) ||
        (above1.trim().startsWith("#") && reviewRe.test(above1)) ||
        (above2.trim().startsWith("#") && reviewRe.test(above2));
      if (!documented) {
        hits.push({
          line: i + 1,
          message:
            "require-adobe-auth: false disables Adobe IMS auth validation for this web action — document the reviewed threat model with a nearby comment (review/ticket/approved/documented) before shipping.",
        });
      }
    }
    return hits;
  },
};

function hasExplicitRequireAuthFalse(node: unknown): boolean {
  if (!node || typeof node !== "object") return false;
  if (Array.isArray(node)) return node.some((v) => hasExplicitRequireAuthFalse(v));
  const rec = node as Record<string, unknown>;
  const annotations = rec["annotations"];
  if (annotations && typeof annotations === "object" && !Array.isArray(annotations)) {
    if ((annotations as Record<string, unknown>)["require-adobe-auth"] === false) return true;
  }
  return Object.values(rec).some((v) => hasExplicitRequireAuthFalse(v));
}

/** MECHANICAL. `state.put(key, ...)` / `State.put(key, ...)` where `key` names a secret. */
export const secretInStatePutChecker: FileChecker = {
  ruleId: "security/adobe/never-store-secrets-in-state-sdk-use-secret-vault",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const secretKeyRe = /password|secret|privatekey|apikey|token/i;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      const objName = identName(member(callee, "object"));
      const propName = identName(member(callee, "property"));
      if ((objName !== "state" && objName !== "State") || propName !== "put") return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const key = args[0];
      if (!key) return;
      let keyText: string | undefined;
      if (key.type === "Literal" && typeof (key as AstNode & { value: unknown }).value === "string") {
        keyText = (key as AstNode & { value: string }).value;
      } else if (key.type === "TemplateLiteral") {
        keyText = templateLiteralText(key);
      }
      if (keyText && secretKeyRe.test(keyText)) {
        hits.push({
          ...locStart(n),
          message: `state.put('${keyText}', ...) stores a secret-shaped key in the State SDK — Adobe's Security Overview says never to store secrets there. Use a Secret Vault instead.`,
        });
      }
    });
    return hits;
  },
};

/**
 * MECHANICAL (simplified). Flags direct `params`/`params.*` logging. Known
 * limits: a call wrapped through a locally-defined `redact()` helper (as
 * shown in this rule's "Good" example) still gets flagged if `redact(params)`
 * itself is passed as a bare argument shaped like `params.*`-rooted access —
 * only a literal identifier reference to `params` (or a member/spread of it)
 * as the logged argument is recognized, so `logger.debug('x', { params:
 * redact(params) })` is correctly clean, but any other redaction shape that
 * still textually contains a `params`-rooted expression as a direct argument
 * may still false-positive.
 */
export const logRawParamsChecker: FileChecker = {
  ruleId: "security/adobe/default-params-are-encrypted-at-rest-never-log-them",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      const objName = identName(member(callee, "object"));
      const propName = identName(member(callee, "property"));
      const isConsole = objName === "console" && (propName === "log" || propName === "error");
      const isLogger = objName === "logger";
      if (!isConsole && !isLogger) return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const flagged = args.some((a) => {
        if (isParamsRef(a)) return true;
        if (a.type === "SpreadElement") return isParamsRef(member(a, "argument"));
        if (a.type === "ObjectExpression") {
          const props = (a as AstNode & { properties: AstNode[] }).properties ?? [];
          return props.some((p) => {
            if (p.type === "SpreadElement") return isParamsRef(member(p, "argument"));
            if (p.type === "Property") return isParamsRef(member(p, "value"));
            return false;
          });
        }
        return false;
      });
      if (flagged) {
        hits.push({
          ...locStart(n),
          message:
            "Logging params (or a member/spread of it) directly — default params are decrypted before execution and logging them leaks secrets into the activation log. Redact known secret keys first.",
        });
      }
    });
    return hits;
  },
};

/** MECHANICAL. `Access-Control-Allow-Origin: '*'` object-literal property. */
export const corsWildcardAuthenticatedChecker: FileChecker = {
  ruleId: "security/adobe/cors-access-control-allow-origin-must-not-be-on-authenticated-ac",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "Property") return;
      const keyName = keyNameOf(member(n, "key"));
      if (!keyName || keyName.toLowerCase() !== "access-control-allow-origin") return;
      const val = member(n, "value");
      if (val?.type === "Literal" && (val as AstNode & { value: unknown }).value === "*") {
        hits.push({
          ...locStart(n),
          message:
            "Access-Control-Allow-Origin: '*' on a response — fine for public CDN assets, but a real gap for authenticated/cross-org data. Use an explicit origin allowlist instead.",
        });
      }
    });
    return hits;
  },
};

/**
 * STATIC (heuristic). Serves BOTH `security/adobe/validate-x-gw-ims-org-id-for-tenant-isolation-in-multi-org-deplo`
 * and `security/owasp-api/verify-resource-ownership-before-returning-it-idor-defense` — same
 * detection logic. Known limits: only recognizes a narrow fetch-by-id shape
 * (a `params.xxxId`-derived value, or the member expression itself, passed
 * directly into a `get*`/`find*`/`fetch*`/`load*`/`query*`-named method call
 * inside `main`); a lookup routed through an intermediate helper function
 * (e.g. `orderRepo.byId(orderId)` called from a separate function that
 * `main` merely invokes) is invisible. Because the org-id check is a
 * whole-file text search, a file that references `x-gw-ims-org-id` anywhere
 * — even in an unrelated action, or in a comment — suppresses the finding
 * even if THIS particular lookup never validates it.
 */
export const missingTenantIsolationChecker: FileChecker = {
  ruleId: "security/adobe/validate-x-gw-ims-org-id-for-tenant-isolation-in-multi-org-deplo",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    if (/x-gw-ims-org-id|imsOrgId/i.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const mainFns = findFunctions(parsed.ast).filter((f) => f.name === "main");
    if (mainFns.length === 0) return [];

    const hits: CheckHit[] = [];
    const idSuffixRe = /Id$/;
    const lookupMethodRe = /^(get|find|fetch|load|query)/i;

    for (const { node: mainNode } of mainFns) {
      const idVars = new Set<string>();
      walkAst(mainNode, (n) => {
        if (n.type !== "VariableDeclarator") return;
        const id = member(n, "id");
        const init = member(n, "init");
        if (id?.type !== "Identifier" || init?.type !== "MemberExpression") return;
        const propName = keyNameOf(member(init, "property"));
        if (rootIdentifier(member(init, "object")) === "params" && propName && idSuffixRe.test(propName)) {
          idVars.add(identName(id) as string);
        }
      });

      walkAst(mainNode, (n) => {
        if (n.type !== "CallExpression") return;
        const callee = member(n, "callee");
        if (callee?.type !== "MemberExpression") return;
        const method = identName(member(callee, "property"));
        if (!method || !lookupMethodRe.test(method)) return;
        const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
        const flagged = args.some((a) => {
          if (a.type === "Identifier") return idVars.has(identName(a) ?? "");
          if (a.type === "MemberExpression") {
            const propName = keyNameOf(member(a, "property"));
            return rootIdentifier(member(a, "object")) === "params" && !!propName && idSuffixRe.test(propName);
          }
          return false;
        });
        if (flagged) {
          hits.push({
            ...locStart(n),
            message:
              "Fetch-by-id lookup with no x-gw-ims-org-id / imsOrgId check anywhere in this file — validate the caller's org against the record's owning org before returning it (multi-org tenant isolation / IDOR defense).",
          });
        }
      });
    }
    return hits;
  },
};

/**
 * MECHANICAL. Scoped to SPA source (excludes anything under actions/, which
 * is server-side runtime code, not a browser SPA).
 */
export const implicitFlowOrNoPkceChecker: FileChecker = {
  ruleId: "security/adobe/browser-spas-use-authorization-code-pkce-never-the-implicit-flow",
  kind: "file",
  appliesTo: (p) => isSourceFile(p) && !/\/actions\//.test(p),
  check(ctx) {
    const hits: CheckHit[] = [];
    const hasCodeChallenge = /code_challenge/i.test(ctx.content);
    const lines = ctx.content.split("\n");
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (/response_type=token\b/i.test(line)) {
        hits.push({
          line: i + 1,
          message:
            "OAuth implicit flow (response_type=token) — deprecated for SPAs, the token lands in the URL fragment/browser history. Use Authorization Code + PKCE instead.",
        });
        continue;
      }
      if (/\/authorize\b/.test(line) && !hasCodeChallenge) {
        hits.push({
          line: i + 1,
          message:
            "Authorize-URL built with no code_challenge (PKCE) anywhere in this file — SPAs must use Authorization Code + PKCE, never the implicit flow.",
        });
      }
    }
    return hits;
  },
};

/** MECHANICAL. `localStorage.setItem(...)` / `sessionStorage.setItem(...)` with a token-shaped key. */
export const tokenInLocalStorageChecker: FileChecker = {
  ruleId: "security/adobe/tokens-stored-in-memory-or-bff-managed-cookies-never-localstorag",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const tokenKeyRe = /token|jwt|access_token|auth/i;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      const objName = identName(member(callee, "object"));
      const propName = identName(member(callee, "property"));
      if ((objName !== "localStorage" && objName !== "sessionStorage") || propName !== "setItem") return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const key = args[0];
      if (!key) return;
      let keyText: string | undefined;
      if (key.type === "Literal" && typeof (key as AstNode & { value: unknown }).value === "string") {
        keyText = (key as AstNode & { value: string }).value;
      } else if (key.type === "Identifier") {
        keyText = identName(key);
      } else if (key.type === "TemplateLiteral") {
        keyText = templateLiteralText(key);
      }
      if (keyText && tokenKeyRe.test(keyText)) {
        hits.push({
          ...locStart(n),
          message: `${objName}.setItem('${keyText}', ...) — a single XSS exfiltrates every token in localStorage/sessionStorage. Keep tokens in memory, or use an HttpOnly BFF-managed session cookie.`,
        });
      }
    });
    return hits;
  },
};

async function findAppConfigYaml(allFiles: string[]): Promise<string | undefined> {
  return allFiles.find((f) => /(^|[\\/])app\.config\.ya?ml$/.test(f.replace(/\\/g, "/")));
}

/**
 * MECHANICAL (repo). Looks only at app.config.yaml's top-level `services:`
 * list (or `.aio`/console.json, if present among the scanned files — kept
 * simple: only app.config.yaml's `services` is actually parsed). If no such
 * file/field is found at all, this returns no hits rather than guessing.
 */
export const missingIoMgmtScopeChecker: RepoChecker = {
  ruleId: "security/adobe/add-i-o-management-api-service-for-read-organizations-scope",
  kind: "repo",
  async check(ctx) {
    const configFile = await findAppConfigYaml(ctx.allFiles);
    if (!configFile) return [];
    let content: string;
    try {
      content = await readFile(configFile, "utf8");
    } catch {
      return [];
    }
    let doc: unknown;
    try {
      doc = parseYaml(content);
    } catch {
      return [];
    }
    if (!doc || typeof doc !== "object") return [];
    const services = (doc as Record<string, unknown>)["services"];
    if (!Array.isArray(services)) return [];
    const hasMgmtScope = services.some((s) => {
      const code = typeof s === "string" ? s : s && typeof s === "object" ? (s as Record<string, unknown>)["code"] : undefined;
      return typeof code === "string" && /IOManagementSDK|read_organizations/i.test(code);
    });
    if (hasMgmtScope) return [];
    return [
      {
        line: 1,
        path: configFile,
        message:
          "No IOManagementSDK service (grants read_organizations) declared in app.config.yaml services — headless integrations requiring read_organizations will silently 401 without it.",
      },
    ];
  },
};

/**
 * STATIC (heuristic) (P5, repo). Deliberately simple and bounded: reads app.config.yaml's
 * `services`/`scopes` declarations, then greps every source file's raw text
 * for a case-insensitive substring match of each declared name. Known
 * limits: a scope referenced only via a dynamically-built string (e.g.
 * string concatenation or a config value read at runtime) is invisible and
 * will false-positive as "unused"; a scope name that happens to be a
 * substring of an unrelated identifier (e.g. a short/generic code) can
 * false-negative as "used" when it isn't really.
 */
export const unusedOauthScopeChecker: RepoChecker = {
  ruleId: "security/adobe/request-only-the-minimum-ims-oauth-scopes-needed",
  kind: "repo",
  async check(ctx) {
    const configFile = await findAppConfigYaml(ctx.allFiles);
    if (!configFile) return [];
    let content: string;
    try {
      content = await readFile(configFile, "utf8");
    } catch {
      return [];
    }
    let doc: unknown;
    try {
      doc = parseYaml(content);
    } catch {
      return [];
    }
    if (!doc || typeof doc !== "object") return [];
    const rec = doc as Record<string, unknown>;
    const declared: string[] = [];
    const collect = (v: unknown): void => {
      if (typeof v === "string") declared.push(v);
      else if (v && typeof v === "object" && "code" in (v as Record<string, unknown>)) {
        const code = (v as Record<string, unknown>)["code"];
        if (typeof code === "string") declared.push(code);
      }
    };
    if (Array.isArray(rec["services"])) (rec["services"] as unknown[]).forEach(collect);
    if (Array.isArray(rec["scopes"])) (rec["scopes"] as unknown[]).forEach(collect);
    if (declared.length === 0) return [];

    const sourceFiles = ctx.allFiles.filter((f) => isSourceFile(f) && f !== configFile);
    const hits: CheckHit[] = [];
    for (const scope of declared) {
      let used = false;
      for (const f of sourceFiles) {
        let text: string;
        try {
          text = await readFile(f, "utf8");
        } catch {
          continue;
        }
        if (text.toLowerCase().includes(scope.toLowerCase())) {
          used = true;
          break;
        }
      }
      if (!used) {
        hits.push({
          line: 1,
          path: configFile,
          message: `Declared scope/service "${scope}" in app.config.yaml has no reference anywhere in the scanned source — request only the minimum IMS/OAuth scopes actually used.`,
        });
      }
    }
    return hits;
  },
};
