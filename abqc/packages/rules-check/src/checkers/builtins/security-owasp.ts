import { readFile } from "node:fs/promises";
import { dirname, resolve as resolvePath } from "node:path";
import type { CheckHit, FileChecker } from "../../types.js";
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

function isParamsRef(n: AstNode | undefined): boolean {
  if (!n) return false;
  if (n.type === "Identifier") return identName(n) === "params";
  if (n.type === "MemberExpression") return isParamsRef(member(n, "object"));
  return false;
}

function calleeIsFetchOrAxios(callee: AstNode | undefined): boolean {
  if (!callee) return false;
  if (callee.type === "Identifier") {
    const name = identName(callee);
    return name === "fetch" || name === "axios";
  }
  if (callee.type === "MemberExpression") {
    return identName(member(callee, "object")) === "axios";
  }
  return false;
}

/**
 * STATIC (heuristic). Flags `===`/`!==` where either side's constituent identifier
 * names suggest a secret comparison (token/hmac/signature/secret/password),
 * unless the file has a `timingSafeEqual(` call anywhere. Known limits: a
 * secret compared under a fully generic name (`x === y`) has no naming
 * signal at all and is invisible; a non-secret UI flag coincidentally named
 * `sessionToken` gets flagged.
 */
export const timingUnsafeCompareChecker: FileChecker = {
  ruleId: "security/eslint-security/use-crypto-timingsafeequal-for-token-hmac-comparison",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    if (/timingSafeEqual\s*\(/.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const secretNameRe = /token|hmac|signature|secret|password/i;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "BinaryExpression") return;
      const op = (n as AstNode & { operator?: string }).operator;
      if (op !== "===" && op !== "!==") return;
      const left = member(n, "left");
      const right = member(n, "right");
      const leftNames = left ? collectIdentifierNames(left) : [];
      const rightNames = right ? collectIdentifierNames(right) : [];
      if (leftNames.some((x) => secretNameRe.test(x)) || rightNames.some((x) => secretNameRe.test(x))) {
        hits.push({
          ...locStart(n),
          message: `Comparing a secret-shaped value with ${op} — timing-unsafe. Use crypto.timingSafeEqual() for token/HMAC/signature/password comparisons.`,
        });
      }
    });
    return hits;
  },
};

function collectIdentifierNames(node: AstNode): string[] {
  const names: string[] = [];
  walkAst(node, (n) => {
    if (n.type === "Identifier") names.push((n as AstNode & { name: string }).name);
  });
  return names;
}

const BIDI_CODEPOINTS = new Set([0x202a, 0x202b, 0x202c, 0x202d, 0x202e, 0x2066, 0x2067, 0x2068, 0x2069]);

/** MECHANICAL. Operates directly on raw source text — no AST needed. */
export const bidiCharsChecker: FileChecker = {
  ruleId: "security/eslint-security/reject-unicode-bidi-characters-in-source-trojan-source-attack",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    const content = ctx.content;
    let line = 1;
    for (let i = 0; i < content.length; i++) {
      const cp = content.codePointAt(i);
      if (cp === undefined) continue;
      if (cp === 0x0a) {
        line++;
        continue;
      }
      if (BIDI_CODEPOINTS.has(cp)) {
        hits.push({
          line,
          message: `Unicode bidi control character U+${cp.toString(16).toUpperCase()} in source — can hide malicious code reordering (Trojan Source / CVE-2021-42574). Remove it.`,
        });
      }
    }
    return hits;
  },
};

/** MECHANICAL. `_.merge`/`merge`/`deepmerge`/`Object.assign` called with a `params`-derived argument, with no schema validation anywhere in the file. */
export const unvalidatedDeepMergeChecker: FileChecker = {
  ruleId: "security/owasp-api/no-deep-merge-of-user-input-into-plain-objects-prototype-polluti",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    if (/\.parse\s*\(|\.safeParse\s*\(|Joi\.|ajv/i.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!isMergeCallee(callee)) return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const flagged = args.some((a) => {
        if (isParamsRef(a)) return true;
        if (a.type === "SpreadElement") return isParamsRef(member(a, "argument"));
        return false;
      });
      if (flagged) {
        hits.push({
          ...locStart(n),
          message:
            "Deep-merging params (or a spread of it) into a plain object with no schema validation in this file — a __proto__/constructor.prototype key in user input can pollute the prototype. Validate with a schema first (zod .parse/.safeParse, Joi, ajv).",
        });
      }
    });
    return hits;
  },
};

function isMergeCallee(callee: AstNode | undefined): boolean {
  if (!callee) return false;
  if (callee.type === "Identifier") {
    const name = identName(callee);
    return name === "merge" || name === "deepmerge";
  }
  if (callee.type === "MemberExpression") {
    const obj = identName(member(callee, "object"));
    const prop = identName(member(callee, "property"));
    if (obj === "_" && prop === "merge") return true;
    if (obj === "Object" && prop === "assign") return true;
  }
  return false;
}

/** MECHANICAL. `fetch(`/`axios(` called with a `params`-derived URL (direct or 1-hop via a variable), with no allowlist identifier anywhere in the file. */
export const ssrfUserUrlChecker: FileChecker = {
  ruleId: "security/owasp-api/ssrf-defense-never-make-outbound-http-to-user-supplied-urls",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    if (/allowlist|allowedHosts|isAllowedUrl/i.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];

    const paramsVars = new Set<string>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const id = member(n, "id");
      const init = member(n, "init");
      if (id?.type === "Identifier" && init && isParamsRef(init)) {
        paramsVars.add(identName(id) as string);
      }
    });

    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!calleeIsFetchOrAxios(callee)) return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const urlArg = args[0];
      if (!urlArg) return;
      const flagged = isParamsRef(urlArg) || (urlArg.type === "Identifier" && paramsVars.has(identName(urlArg) ?? ""));
      if (flagged) {
        hits.push({
          ...locStart(n),
          message:
            "Outbound HTTP call to a URL derived from params with no allowlist check anywhere in this file — SSRF: a malicious params-supplied URL can reach internal services. Validate against an allowlist (allowedHosts/isAllowedUrl) first.",
        });
      }
    });
    return hits;
  },
};

function textOf(content: string, node: AstNode | undefined): string {
  if (!node) return "";
  const range = (node as unknown as { range?: [number, number] }).range;
  if (!range) return "";
  return content.slice(range[0], range[1]);
}

/**
 * MECHANICAL, proxy for `eslint-plugin-security`'s `detect-object-injection`.
 * That rule only recognizes a bare Identifier as the computed bracket key —
 * it silently misses `obj[params.key]` (property-of-property as the key),
 * which is the idiomatic shape user input takes in an Adobe I/O action. This
 * flags that specific shape directly via AST instead of relying on the
 * upstream rule's narrower pattern match.
 *
 * Known limits (documented, not silently swallowed): the "guarded" allowlist
 * check must be a `.has(`/`.includes(` call on the identical key expression
 * appearing earlier in the same function body (source order, not real control-
 * flow analysis) — a differently-shaped guard (custom function, switch-case,
 * regex test) is not recognized (false positive risk), and a guard that exists
 * but doesn't actually gate the access (e.g. an unrelated `if`) still
 * suppresses the finding (false negative risk).
 */
export const objectInjectionMemberKeyChecker: FileChecker = {
  ruleId: "security/eslint-security/no-bracket-access-on-user-controlled-keys-object-injection",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const guardedKeyTexts = new Set<string>();
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee?.type === "MemberExpression") {
          const method = identName(member(callee, "property"));
          if (method === "has" || method === "includes") {
            const args = (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
            const arg = args[0];
            if (arg) guardedKeyTexts.add(textOf(ctx.content, arg));
          }
        }
        return;
      }
      if (n.type !== "MemberExpression") return;
      if (!(n as AstNode & { computed?: boolean }).computed) return;
      const prop = member(n, "property");
      if (prop?.type !== "MemberExpression") return;
      const keyText = textOf(ctx.content, prop);
      if (guardedKeyTexts.has(keyText)) return;
      hits.push({
        ...locStart(n),
        message: `Bracket access keyed by a nested property (${keyText}) can write/read any property, including __proto__ — eslint-plugin-security's detect-object-injection does not catch this shape. Validate the key against an allowlist before this access.`,
      });
    });
    return hits;
  },
};

/** MECHANICAL. `.update(/.insert(/.create(` called with `params` (or `{...params}`) as the write payload. */
export const massAssignmentChecker: FileChecker = {
  ruleId: "security/owasp-api/never-spread-params-directly-into-a-database-write-mass-assignme",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const writeMethodRe = /(update|insert|create)$/;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      const method = identName(member(callee, "property"));
      if (!method || !writeMethodRe.test(method)) return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const flagged = args.some((a) => {
        if (isParamsRef(a)) return true;
        if (a.type === "ObjectExpression") {
          const props = (a as AstNode & { properties: AstNode[] }).properties ?? [];
          return props.some((p) => p.type === "SpreadElement" && isParamsRef(member(p, "argument")));
        }
        return false;
      });
      if (flagged) {
        hits.push({
          ...locStart(n),
          message:
            "Spreading params directly into a database write — mass assignment: any field the caller sends (e.g. `role`, `isAdmin`) is written verbatim. Pick an explicit allowlist of fields instead.",
        });
      }
    });
    return hits;
  },
};

/** MECHANICAL. `fetch(/axios(` with a literal `http://` URL argument. */
export const httpLiteralUrlChecker: FileChecker = {
  ruleId: "security/owasp-web/force-https-for-all-third-party-integrations",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!calleeIsFetchOrAxios(callee)) return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const urlArg = args[0];
      if (urlArg?.type !== "Literal") return;
      const val = (urlArg as AstNode & { value: unknown }).value;
      if (typeof val === "string" && /^http:\/\//i.test(val)) {
        hits.push({
          ...locStart(n),
          message: `Third-party call to a plain http:// URL ("${val}") — force HTTPS for all third-party integrations.`,
        });
      }
    });
    return hits;
  },
};

/** MECHANICAL. `.query(/.execute(` with a template literal (with interpolation) or `+`-concatenated string containing SQL keywords. */
export const sqlStringConcatChecker: FileChecker = {
  ruleId: "security/owasp-web/use-parameterized-queries-prepared-statements-only",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const sqlRe = /select|insert|update|delete/i;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      const method = identName(member(callee, "property"));
      if (method !== "query" && method !== "execute") return;
      const args = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const arg = args[0];
      if (!arg) return;
      if (arg.type === "TemplateLiteral") {
        const exprs = (arg as AstNode & { expressions: AstNode[] }).expressions ?? [];
        const quasis = (arg as AstNode & { quasis: AstNode[] }).quasis ?? [];
        const text = quasis
          .map((q) => String((q as AstNode & { value?: { raw?: string } }).value?.raw ?? ""))
          .join(" ");
        if (exprs.length > 0 && sqlRe.test(text)) {
          hits.push({ ...locStart(n), message: sqlMessage(method) });
        }
      } else if (arg.type === "BinaryExpression" && (arg as AstNode & { operator?: string }).operator === "+") {
        const text = collectBinaryLiterals(arg);
        if (sqlRe.test(text)) {
          hits.push({ ...locStart(n), message: sqlMessage(method) });
        }
      }
    });
    return hits;
  },
};

function sqlMessage(method: string): string {
  return `${method}() called with an interpolated/concatenated SQL string — use parameterized queries / prepared statements only, never string-build SQL from input.`;
}

function collectBinaryLiterals(node: AstNode): string {
  const parts: string[] = [];
  walkAst(node, (n) => {
    if (n.type === "Literal") {
      const v = (n as AstNode & { value: unknown }).value;
      if (typeof v === "string") parts.push(v);
    }
  });
  return parts.join(" ");
}

/** MECHANICAL. `createHash('sha1'|'sha256'|'md5').update(<password-shaped>)`. */
export const weakPasswordHashChecker: FileChecker = {
  ruleId: "security/owasp-web/hash-passwords-with-bcrypt-or-argon2-never-sha-md5",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const weakAlgoRe = /^(sha1|sha256|md5)$/i;
    const pwdRe = /password|passwd|pwd/i;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression") return;
      const method = identName(member(callee, "property"));
      if (method !== "update") return;
      const hashCall = member(callee, "object");
      if (!hashCall || hashCall.type !== "CallExpression") return;
      const hashCallee = member(hashCall, "callee");
      const hashCalleeName =
        hashCallee?.type === "Identifier"
          ? identName(hashCallee)
          : hashCallee?.type === "MemberExpression"
            ? identName(member(hashCallee, "property"))
            : undefined;
      if (hashCalleeName !== "createHash") return;
      const hashArgs = (hashCall as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const algoArg = hashArgs[0];
      const algo = algoArg?.type === "Literal" ? String((algoArg as AstNode & { value: unknown }).value) : undefined;
      if (!algo || !weakAlgoRe.test(algo)) return;
      const updateArgs = (n as AstNode & { arguments: AstNode[] }).arguments ?? [];
      const updArg = updateArgs[0];
      if (!updArg) return;
      const name =
        updArg.type === "Identifier"
          ? identName(updArg)
          : updArg.type === "MemberExpression"
            ? identName(member(updArg, "property"))
            : undefined;
      if (name && pwdRe.test(name)) {
        hits.push({
          ...locStart(n),
          message: `createHash('${algo}').update(${name}) — ${algo} is a fast general-purpose hash, not a password hash. Use bcrypt or argon2 instead.`,
        });
      }
    });
    return hits;
  },
};

/** MECHANICAL. Within `main`, a `return`ed headers object that's present but missing a Content-Security-Policy key. */
export const missingCspHeaderChecker: FileChecker = {
  ruleId: "security/owasp-web/set-content-security-policy-on-web-responses",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const mainFns = findFunctions(parsed.ast).filter((f) => f.name === "main");
    const hits: CheckHit[] = [];
    for (const { node: mainNode } of mainFns) {
      walkAst(mainNode, (n) => {
        if (n.type !== "ReturnStatement") return;
        const arg = member(n, "argument");
        if (!arg || arg.type !== "ObjectExpression") return;
        const props = (arg as AstNode & { properties: AstNode[] }).properties ?? [];
        const headersProp = props.find((p) => p.type === "Property" && keyNameOf(member(p, "key")) === "headers");
        if (!headersProp) return;
        const headersVal = member(headersProp, "value");
        if (headersVal?.type !== "ObjectExpression") return;
        const headerProps = (headersVal as AstNode & { properties: AstNode[] }).properties ?? [];
        const hasCsp = headerProps.some(
          (p) => p.type === "Property" && /content-security-policy/i.test(keyNameOf(member(p, "key")) ?? ""),
        );
        if (!hasCsp) {
          hits.push({
            ...locStart(n),
            message: "Response headers object present with no Content-Security-Policy key — set a CSP on web responses.",
          });
        }
      });
    }
    return hits;
  },
};

/** Resolve a `require('./relative')` specifier against the importing file's directory, and read it. */
async function readRequiredFile(fromFile: string, spec: string): Promise<string | undefined> {
  if (!spec.startsWith(".")) return undefined;
  for (const candidate of [spec, `${spec}.js`, `${spec}.ts`, `${spec}.cjs`, `${spec}.mjs`]) {
    try {
      return await readFile(resolvePath(dirname(fromFile), candidate), "utf8");
    } catch {
      // try next candidate extension
    }
  }
  return undefined;
}

/**
 * `rejectUnauthorized: false` / `verify: false` (AST) plus
 * `NODE_TLS_REJECT_UNAUTHORIZED` set to `'0'` (line-scoped text scan), PLUS a
 * one-hop cross-file trace for `rejectUnauthorized: someIdentifier` where
 * `someIdentifier` is itself assigned from (or defaults to) a same-file
 * identifier that was imported via a relative `require(...)` — resolving
 * that import and checking whether IT declares the traced name as a literal
 * `false`. Real production shape this closes: `constants.js` exports `const
 * X_DEFAULT = false`; `client.js` does `verifySSL = X_DEFAULT` and then
 * `new https.Agent({ rejectUnauthorized: verifySSL })` — neither file alone
 * contains the literal `rejectUnauthorized: false` the single-file version
 * of this check required. Still bounded (one hop, relative requires only,
 * not full dataflow) — a second re-export hop or a non-relative import
 * still evades it.
 */
export const tlsVerificationDisabledChecker: FileChecker = {
  ruleId: "security/owasp-web/tls-certificate-verification-must-never-be-disabled",
  kind: "file",
  appliesTo: isSourceFile,
  async check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];

    // Same-file locals initialized to a literal `false` — `const X = false`.
    const localFalse = new Set<string>();
    // Same-file locals whose value traces to an identifier imported from a
    // relative require — `name -> { importedName, specifier }`.
    const importedFrom = new Map<string, { importedName: string; specifier: string }>();
    // `name -> sourceIdentifier` for a same-file re-assignment/default-value
    // link (`let verifySSL = SAP_VERIFY_SSL_DEFAULT`, or a default parameter
    // `function f(verifySSL = SAP_VERIFY_SSL_DEFAULT)`).
    const aliasOf = new Map<string, string>();

    function recordDestructureFromRequire(pattern: AstNode | undefined, init: AstNode | undefined): void {
      if (!pattern || pattern.type !== "ObjectPattern" || !init || init.type !== "CallExpression") return;
      const callee = member(init, "callee");
      if (keyNameOf(callee) !== "require") return;
      const args = (init as AstNode & { arguments?: AstNode[] }).arguments ?? [];
      const specNode = args[0];
      if (specNode?.type !== "Literal") return;
      const spec = (specNode as AstNode & { value: unknown }).value;
      if (typeof spec !== "string") return;
      const props = (pattern as AstNode & { properties?: AstNode[] }).properties ?? [];
      for (const p of props) {
        const localName = keyNameOf(member(p, "value")) ?? keyNameOf(member(p, "key"));
        const importedName = keyNameOf(member(p, "key"));
        if (localName && importedName) importedFrom.set(localName, { importedName, specifier: spec });
      }
    }

    walkAst(parsed.ast, (n) => {
      if (n.type === "VariableDeclarator") {
        const id = member(n, "id");
        const init = member(n, "init");
        recordDestructureFromRequire(id, init);
        if (id?.type === "Identifier") {
          const name = keyNameOf(id);
          if (name && init?.type === "Literal" && (init as AstNode & { value: unknown }).value === false) {
            localFalse.add(name);
          }
          if (name && init?.type === "Identifier") {
            const source = keyNameOf(init);
            if (source) aliasOf.set(name, source);
          }
        }
      }
      // A default parameter value that's itself an identifier — `function
      // f(verifySSL = SAP_VERIFY_SSL_DEFAULT)` — is the same alias shape as
      // a variable initializer.
      if (n.type === "AssignmentPattern") {
        const left = member(n, "left");
        const right = member(n, "right");
        if (left?.type === "Identifier" && right?.type === "Identifier") {
          const name = keyNameOf(left);
          const source = keyNameOf(right);
          if (name && source) aliasOf.set(name, source);
        }
      }
    });

    async function resolvesToFalse(name: string, depth = 0): Promise<boolean> {
      if (depth > 3) return false;
      if (localFalse.has(name)) return true;
      const alias = aliasOf.get(name);
      if (alias) {
        if (localFalse.has(alias) || (await resolvesToFalse(alias, depth + 1))) return true;
        const imported = importedFrom.get(alias);
        if (imported) return checkImportedNameIsFalse(ctx.filePath, imported.specifier, imported.importedName);
      }
      const imported = importedFrom.get(name);
      if (imported) return checkImportedNameIsFalse(ctx.filePath, imported.specifier, imported.importedName);
      return false;
    }

    async function checkImportedNameIsFalse(fromFile: string, specifier: string, importedName: string): Promise<boolean> {
      const content = await readRequiredFile(fromFile, specifier);
      if (!content) return false;
      const re = new RegExp(`\\b(?:const|let)\\s+${importedName}\\s*=\\s*false\\b`);
      return re.test(content);
    }

    const identifierHits: Array<{ node: AstNode; name: string; keyName: string }> = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "Property") return;
      const keyName = keyNameOf(member(n, "key"));
      if (keyName !== "rejectUnauthorized" && keyName !== "verify") return;
      const val = member(n, "value");
      if (val?.type === "Literal" && (val as AstNode & { value: unknown }).value === false) {
        hits.push({
          ...locStart(n),
          message: `${keyName}: false disables TLS certificate verification — never safe, even for local dev/self-signed certs in an internal tool. Remove it and fix the actual cert trust chain.`,
        });
      } else if (val?.type === "Identifier") {
        const name = keyNameOf(val);
        if (name) identifierHits.push({ node: n, name, keyName });
      }
    });
    for (const { node, name, keyName } of identifierHits) {
      if (await resolvesToFalse(name)) {
        hits.push({
          ...locStart(node),
          message: `${keyName}: ${name} traces back to a literal 'false' default — this disables TLS certificate verification — never safe, even for local dev/self-signed certs in an internal tool. Remove it and fix the actual cert trust chain.`,
        });
      }
    }
    const lines = ctx.content.split("\n");
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (/NODE_TLS_REJECT_UNAUTHORIZED/.test(line) && /['"]0['"]/.test(line)) {
        hits.push({
          line: i + 1,
          message:
            "NODE_TLS_REJECT_UNAUTHORIZED = '0' globally disables TLS certificate verification for the whole process — never do this, even for local dev.",
        });
      }
    }
    return hits;
  },
};

/** MECHANICAL. Within `main`, a `return`ed 401/403 statusCode with no logger/console call earlier in the same function. */
export const unloggedAuthDenialChecker: FileChecker = {
  ruleId: "security/supply-chain/log-security-relevant-events-authentication-outcomes-and-authori",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const mainFns = findFunctions(parsed.ast).filter((f) => f.name === "main");
    const hits: CheckHit[] = [];
    for (const { node: mainNode } of mainFns) {
      const logCallLines: number[] = [];
      walkAst(mainNode, (n) => {
        if (n.type !== "CallExpression") return;
        const callee = member(n, "callee");
        if (callee?.type !== "MemberExpression") return;
        const obj = identName(member(callee, "object"));
        if (obj === "logger" || obj === "console") {
          const line = n.loc?.start.line;
          if (line) logCallLines.push(line);
        }
      });
      walkAst(mainNode, (n) => {
        if (n.type !== "ReturnStatement") return;
        const arg = member(n, "argument");
        if (!arg || arg.type !== "ObjectExpression") return;
        const statusCode = findStatusCode(arg);
        if (statusCode !== 401 && statusCode !== 403) return;
        const returnLine = n.loc?.start.line ?? 0;
        const loggedBefore = logCallLines.some((l) => l < returnLine);
        if (!loggedBefore) {
          hits.push({
            ...locStart(n),
            message: `Returning statusCode: ${statusCode} (auth denial) with no logger/console call earlier in main() — log security-relevant auth outcomes for audit/incident response.`,
          });
        }
      });
    }
    return hits;
  },
};

function findStatusCode(obj: AstNode): number | undefined {
  const props = (obj as AstNode & { properties: AstNode[] }).properties ?? [];
  for (const p of props) {
    if (p.type !== "Property") continue;
    const key = keyNameOf(member(p, "key"));
    const val = member(p, "value");
    if (key === "statusCode" && val?.type === "Literal") {
      const v = (val as AstNode & { value: unknown }).value;
      if (typeof v === "number") return v;
    }
    if (key === "error" && val?.type === "ObjectExpression") {
      const nested = findStatusCode(val);
      if (nested !== undefined) return nested;
    }
  }
  return undefined;
}

/** MECHANICAL. A URL template literal or `+`-concatenation with a `?api_key=`/`?token=`/`?access_token=` literal segment immediately followed by an interpolated (non-literal) part. */
export const secretInUrlQueryChecker: FileChecker = {
  ruleId: "security/supply-chain/never-put-api-keys-or-tokens-in-url-query-parameters",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const queryKeyRe = /[?&](api_key|token|access_token)=$/i;
    walkAst(parsed.ast, (n) => {
      if (n.type === "TemplateLiteral") {
        const quasis = (n as AstNode & { quasis: AstNode[] }).quasis ?? [];
        const exprs = (n as AstNode & { expressions: AstNode[] }).expressions ?? [];
        for (let i = 0; i < exprs.length; i++) {
          const preceding = String((quasis[i] as AstNode & { value?: { raw?: string } })?.value?.raw ?? "");
          if (queryKeyRe.test(preceding)) {
            hits.push({
              ...locStart(n),
              message:
                "URL built with a secret (api_key/token/access_token) interpolated into the query string — query params are logged in access logs/proxies/browser history. Send it as a header instead.",
            });
            return;
          }
        }
      } else if (n.type === "BinaryExpression" && (n as AstNode & { operator?: string }).operator === "+") {
        const parts = flattenConcat(n);
        for (let i = 0; i < parts.length - 1; i++) {
          const part = parts[i];
          if (!part || part.type !== "Literal" || typeof (part as AstNode & { value: unknown }).value !== "string") continue;
          const val = (part as AstNode & { value: string }).value;
          if (!queryKeyRe.test(val)) continue;
          const next = parts[i + 1];
          if (next && next.type !== "Literal") {
            hits.push({
              ...locStart(n),
              message:
                "URL built with a secret (api_key/token/access_token) concatenated into the query string — query params are logged in access logs/proxies/browser history. Send it as a header instead.",
            });
            break;
          }
        }
      }
    });
    return hits;
  },
};

function flattenConcat(node: AstNode): AstNode[] {
  if (node.type === "BinaryExpression" && (node as AstNode & { operator?: string }).operator === "+") {
    const left = member(node, "left");
    const right = member(node, "right");
    return [...(left ? flattenConcat(left) : []), ...(right ? [right] : [])];
  }
  return [node];
}
