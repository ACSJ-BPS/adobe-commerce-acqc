import { readFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { parse as parseYaml } from "yaml";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isActionFile, isSourceFile } from "@abqc/core";
import { walkAst, parseSource, locStart, findFunctions, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

const isAppOrExtConfigYaml = (p: string): boolean => /(^|\/)(app|ext)\.config\.yaml$/i.test(p.replace(/\\/g, "/"));
const isAppConfigYaml = (p: string): boolean => /(^|\/)app\.config\.yaml$/i.test(p.replace(/\\/g, "/"));

/**
 * `ext.config.yaml` is exclusively an Admin UI SDK / Commerce-extensibility
 * construct — a plain, non-Admin-UI-SDK App Builder app never has one. Its
 * presence next to this `app.config.yaml` is a reliable, purely static proxy
 * for "this app IS meant to be an Admin UI SDK extension", used to gate
 * structure rules that otherwise misfire on ordinary apps whose
 * `application:`/`actions:` top-level shape is the CORRECT pattern for them,
 * not a "legacy" one.
 */
function hasSiblingExtConfigYaml(appConfigPath: string): boolean {
  return existsSync(join(dirname(appConfigPath), "ext.config.yaml"));
}

/**
 * The real, structural Admin UI SDK signal for a root `app.config.yaml` that
 * doesn't have `ext.config.yaml` sitting directly next to it (the common real
 * shape: `extensions: { commerce/backend-ui/1: { $include: src/.../ext.config.yaml } }`).
 * Deliberately NOT based on `productDependencies` — a plain, non-Admin-UI-SDK
 * App Builder app declaring `productDependencies: [{code: COMMC}]` for unrelated
 * Commerce-integration reasons (e.g. calling Commerce REST/GraphQL from backend
 * actions) is not itself evidence of an Admin UI SDK extension, and using it as
 * one made this gate circular for the `product-dependencies` rule specifically
 * (the condition it exists to validate would satisfy its own applicability check).
 */
function hasExtensionsBlock(doc: unknown): boolean {
  return !!doc && typeof doc === "object" && "extensions" in (doc as object);
}

function referencesExtConfigYaml(doc: unknown): boolean {
  const includes: unknown[] = [];
  collectByKey(doc, "$include", includes);
  return includes.some((v) => typeof v === "string" && /ext\.config\.ya?ml$/i.test(v));
}

function isAdminUiSdkApp(appConfigPath: string, doc: unknown): boolean {
  return hasSiblingExtConfigYaml(appConfigPath) || hasExtensionsBlock(doc) || referencesExtConfigYaml(doc);
}

function parseYamlSafe(content: string): unknown | undefined {
  try {
    return parseYaml(content);
  } catch {
    return undefined;
  }
}

function collectByKey(obj: unknown, keyName: string, out: unknown[]): void {
  if (Array.isArray(obj)) {
    for (const item of obj) collectByKey(item, keyName, out);
    return;
  }
  if (obj && typeof obj === "object") {
    for (const [k, v] of Object.entries(obj as Record<string, unknown>)) {
      if (k === keyName) out.push(v);
      collectByKey(v, keyName, out);
    }
  }
}

function parseVersion(v: string): number[] {
  return v.split(".").map((n) => parseInt(n, 10) || 0);
}

function versionGte(a: number[], b: number[]): boolean {
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const x = a[i] ?? 0;
    const y = b[i] ?? 0;
    if (x > y) return true;
    if (x < y) return false;
  }
  return true;
}

// ---------------------------------------------------------------------------
// 20. product-dependencies
// ---------------------------------------------------------------------------

const MIN_COMMC_VERSION = [2, 4, 5];

export const productDependenciesChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/deploy/declare-productdependencies-with-explicit-commerce-min-version-2",
  kind: "file",
  appliesTo: isAppOrExtConfigYaml,
  check(ctx) {
    const doc = parseYamlSafe(ctx.content);
    if (doc === undefined) return [];
    // Every app.config.yaml gets flagged for missing a Commerce-specific
    // COMMC productDependency, regardless of whether the app is Admin UI
    // SDK/Commerce-integrated at all — gate on the same "is this actually an
    // Admin UI SDK app" signal used elsewhere in this file. A plain,
    // non-Commerce App Builder app has no reason to declare one.
    if (!isAdminUiSdkApp(ctx.filePath, doc)) return [];
    const depsArrays: unknown[] = [];
    collectByKey(doc, "productDependencies", depsArrays);
    const ok = depsArrays.some(
      (arr) =>
        Array.isArray(arr) &&
        arr.some((d) => {
          if (!d || typeof d !== "object") return false;
          const dep = d as Record<string, unknown>;
          return dep.code === "COMMC" && typeof dep.minVersion === "string" && versionGte(parseVersion(dep.minVersion), MIN_COMMC_VERSION);
        }),
    );
    if (!ok) {
      return [{ line: 1, message: "Declare productDependencies with an explicit code: COMMC entry and minVersion >= 2.4.5" }];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 21. no-hand-rolled-postmessage
// ---------------------------------------------------------------------------

const UIX_GUEST_IMPORT_RE = /from\s*['"]@adobe\/uix-guest['"]|require\(\s*['"]@adobe\/uix-guest['"]\s*\)/;

export const noHandRolledPostMessageChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/integration/use-adobe-uix-guest-register-attach-never-hand-roll-postmessage-",
  kind: "file",
  appliesTo: (p) => isSourceFile(p) && !isActionFile(p),
  check(ctx) {
    if (UIX_GUEST_IMPORT_RE.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!callee || callee.type !== "MemberExpression") return;
      const propName = identName(member(callee, "property"));
      if (propName === "postMessage") {
        const obj = member(callee, "object");
        const isWindowParent =
          obj?.type === "MemberExpression" &&
          identName(member(obj, "object")) === "window" &&
          identName(member(obj, "property")) === "parent";
        const isParent = obj?.type === "Identifier" && identName(obj) === "parent";
        if (isWindowParent || isParent) {
          hits.push({ ...locStart(n), message: "Hand-rolled window.parent.postMessage() — use @adobe/uix-guest register()/attach() instead" });
        }
      } else if (propName === "addEventListener") {
        const args = (n as AstNode & { arguments?: AstNode[] }).arguments;
        const first = args?.[0];
        if (first?.type === "Literal" && (first as AstNode & { value: unknown }).value === "message") {
          hits.push({ ...locStart(n), message: "Hand-rolled addEventListener('message') — use @adobe/uix-guest register()/attach() instead" });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 22. extension-id-consistency (repo)
// ---------------------------------------------------------------------------

export const extensionIdConsistencyChecker: RepoChecker = {
  ruleId: "appbuilder/admin-ui-sdk/registration/use-a-vendor-prefixed-extension-id-consistently-across-register-",
  kind: "repo",
  async check(ctx) {
    const found: Array<{ file: string; value: string; line?: number }> = [];
    for (const f of ctx.allFiles.filter((p) => isSourceFile(p))) {
      let content: string;
      try {
        content = await readFile(f, "utf8");
      } catch {
        continue;
      }
      const parsed = parseSource(content, f);
      if (!parsed) continue;
      // Every real sample writes `const extensionId = '...'; register({ id:
      // extensionId })` — the id argument is a same-file identifier
      // reference, not an inline string literal. Resolve it via a one-pass
      // scan for top-level `const <name> = '<literal>'` declarations before
      // looking at register()/attach() calls.
      const stringConstByName = new Map<string, string>();
      walkAst(parsed.ast, (n) => {
        if (n.type !== "VariableDeclarator") return;
        const id = member(n, "id");
        const init = member(n, "init");
        if (id?.type !== "Identifier" || init?.type !== "Literal") return;
        const value = (init as AstNode & { value: unknown }).value;
        if (typeof value === "string") {
          const name = identName(id);
          if (name) stringConstByName.set(name, value);
        }
      });
      walkAst(parsed.ast, (n) => {
        if (n.type !== "CallExpression") return;
        const callee = member(n, "callee");
        const calleeName = identName(callee);
        if (calleeName !== "register" && calleeName !== "attach") return;
        const args = (n as AstNode & { arguments?: AstNode[] }).arguments;
        const arg0 = args?.[0];
        if (arg0?.type !== "ObjectExpression") return;
        const props = (arg0 as AstNode & { properties?: AstNode[] }).properties;
        for (const p of props ?? []) {
          if (p.type !== "Property") continue;
          const key = member(p, "key");
          if (identName(key) !== "id") continue;
          const value = member(p, "value");
          if (value?.type === "Literal" && typeof (value as AstNode & { value: unknown }).value === "string") {
            found.push({ file: f, value: (value as AstNode & { value: string }).value, line: locStart(p)?.line });
          } else if (value?.type === "Identifier") {
            const name = identName(value);
            const resolved = name ? stringConstByName.get(name) : undefined;
            if (resolved) found.push({ file: f, value: resolved, line: locStart(p)?.line });
          }
        }
      });
    }
    const distinct = Array.from(new Set(found.map((x) => x.value)));
    if (distinct.length > 1) {
      const second = distinct[1];
      const offender = found.find((x) => x.value === second) ?? found[0];
      return [
        {
          line: offender?.line ?? 1,
          path: offender?.file,
          message: `Inconsistent extension id across register()/attach() calls — found ${distinct.map((d) => `"${d}"`).join(", ")}; use one vendor-prefixed id consistently`,
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 23. one-menu-entry
// ---------------------------------------------------------------------------

function findMenuItemArrays(obj: unknown, out: unknown[][]): void {
  if (Array.isArray(obj)) {
    for (const item of obj) findMenuItemArrays(item, out);
    return;
  }
  if (obj && typeof obj === "object") {
    for (const [k, v] of Object.entries(obj as Record<string, unknown>)) {
      if (k === "menuItems" && Array.isArray(v)) out.push(v);
      findMenuItemArrays(v, out);
    }
  }
}

/** Count of a JSX-literal-array's elements that are object literals without a `parent` property. */
function countTopLevelMenuItems(arr: AstNode): number {
  const elements = ((arr as AstNode & { elements?: (AstNode | null)[] }).elements ?? []).filter(
    (e): e is AstNode => e != null,
  );
  const hasParentField = elements.some((el) => {
    if (el.type !== "ObjectExpression") return false;
    const props = (el as AstNode & { properties?: AstNode[] }).properties ?? [];
    return props.some((p) => p.type === "Property" && identName(member(p, "key")) === "parent");
  });
  if (!hasParentField) return elements.length;
  return elements.filter((el) => {
    if (el.type !== "ObjectExpression") return true;
    const props = (el as AstNode & { properties?: AstNode[] }).properties ?? [];
    return !props.some((p) => p.type === "Property" && identName(member(p, "key")) === "parent");
  }).length;
}

export const oneMenuEntryChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/registration/one-menu-entry-per-app-use-spa-internal-routing-for-multiple-scr",
  kind: "file",
  // Real samples build `menuItems` inside the registration action's JS
  // return payload (`return { menuItems: [...] }`), not only declaratively
  // in app.config.yaml/ext.config.yaml — scanning YAML only missed the
  // common case entirely.
  appliesTo: (p) => isAppOrExtConfigYaml(p) || isSourceFile(p),
  check(ctx) {
    const hits: CheckHit[] = [];
    if (isAppOrExtConfigYaml(ctx.filePath)) {
      const doc = parseYamlSafe(ctx.content);
      if (doc === undefined) return [];
      const menuArrays: unknown[][] = [];
      findMenuItemArrays(doc, menuArrays);
      for (const items of menuArrays) {
        const hasParentField = items.some((it) => it && typeof it === "object" && "parent" in (it as object));
        const topLevelCount = hasParentField
          ? items.filter((it) => !(it && typeof it === "object" && "parent" in (it as object))).length
          : items.length;
        if (topLevelCount > 1) {
          hits.push({ line: 1, message: `menuItems declares ${topLevelCount} top-level entries — use one menu entry per app and SPA-internal routing for multiple screens` });
        }
      }
      return hits;
    }
    const parsed = parseSource(ctx.content, ctx.filePath);
    if (!parsed) return [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "Property") return;
      if (identName(member(n, "key")) !== "menuItems") return;
      const value = member(n, "value");
      if (value?.type !== "ArrayExpression") return;
      const topLevelCount = countTopLevelMenuItems(value);
      if (topLevelCount > 1) {
        hits.push({ ...locStart(n), message: `menuItems declares ${topLevelCount} top-level entries — use one menu entry per app and SPA-internal routing for multiple screens` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 24. registration-payload-pure
// ---------------------------------------------------------------------------

function nodeStart(n: AstNode): number {
  const r = (n as AstNode & { range?: [number, number] }).range;
  if (r) return r[0];
  return n.loc ? n.loc.start.line * 1_000_000 + n.loc.start.column : 0;
}

export const registrationPayloadPureChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/registration/keep-the-registration-payload-small-and-stable-commerce-caches-i",
  kind: "file",
  appliesTo: (p) => isActionFile(p) && /registration/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const mainFns = findFunctions(parsed.ast).filter((f) => f.name === "main");
    for (const { node } of mainFns) {
      const awaits: AstNode[] = [];
      const returns: AstNode[] = [];
      walkAst(node, (n) => {
        if (n.type === "AwaitExpression") awaits.push(n);
        if (n.type === "ReturnStatement") returns.push(n);
      });
      if (awaits.length === 0 || returns.length === 0) continue;
      const firstReturnPos = Math.min(...returns.map(nodeStart));
      if (awaits.some((a) => nodeStart(a) < firstReturnPos)) {
        hits.push({ ...locStart(node), message: "main() performs an await before returning — keep the registration payload construction free of awaited work so Commerce cache stays stable" });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 25. no-log-ims-token (STATIC (heuristic))
// Known limits: token concatenated via an intermediate unrelated-named
// variable evades direct-reference matching; a variable named similarly but
// numeric (tokenCount) is a false positive.
// ---------------------------------------------------------------------------

const SENSITIVE_TOKEN_ID_RE = /imstoken|accesstoken/i;

function memberChainNames(n: AstNode): string[] {
  const parts: string[] = [];
  let cur: AstNode | undefined = n;
  while (cur && cur.type === "MemberExpression") {
    const prop = member(cur, "property");
    parts.unshift(identName(prop) ?? "");
    cur = member(cur, "object");
  }
  if (cur) parts.unshift(identName(cur) ?? "");
  return parts;
}

function referencesSensitiveToken(n: AstNode): boolean {
  if (n.type === "Identifier") return SENSITIVE_TOKEN_ID_RE.test(identName(n) ?? "");
  if (n.type === "MemberExpression") {
    const chain = memberChainNames(n).join(".").toLowerCase();
    if (chain.includes("__ow_headers") && chain.includes("authorization")) return true;
    return SENSITIVE_TOKEN_ID_RE.test(chain);
  }
  if (n.type === "TemplateLiteral") {
    const exprs = (n as AstNode & { expressions?: AstNode[] }).expressions;
    return (exprs ?? []).some((e) => referencesSensitiveToken(e));
  }
  if (n.type === "BinaryExpression") {
    const left = member(n, "left");
    const right = member(n, "right");
    return (left ? referencesSensitiveToken(left) : false) || (right ? referencesSensitiveToken(right) : false);
  }
  return false;
}

/**
 * Logging the action's WHOLE `params` object (the conventional, universal name
 * for an App Builder action's sole handler argument — `async function main(params)`)
 * evades `referencesSensitiveToken`'s narrow identifier-name matching entirely, even
 * though `params` always carries `__ow_headers.authorization` (the caller's IMS
 * bearer token) for a web action, plus any secret/token input parameters. This
 * checker only applies to files matched by `isActionFile`, so a bare identifier
 * named `params`/`event.params` there is reliably the actual request payload, not
 * an unrelated same-named local variable.
 */
function referencesWholeParamsObject(n: AstNode): boolean {
  if (n.type === "Identifier") return (identName(n) ?? "").toLowerCase() === "params";
  if (n.type === "MemberExpression") {
    const chain = memberChainNames(n).join(".").toLowerCase();
    return chain === "event.params" || chain.endsWith(".params");
  }
  return false;
}

export const noLogImsTokenChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/security/never-log-ims-tokens-authorization-headers-or-oauth-secrets",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (!callee || callee.type !== "MemberExpression") return;
      const objName = identName(member(callee, "object"));
      const propName = identName(member(callee, "property"));
      const isConsole = objName === "console" && ["log", "info", "warn", "error", "debug"].includes(propName ?? "");
      const isLogger = objName === "logger";
      if (!isConsole && !isLogger) return;
      const args = (n as AstNode & { arguments?: AstNode[] }).arguments;
      for (const arg of args ?? []) {
        if (referencesSensitiveToken(arg)) {
          hits.push({ ...locStart(n), message: "Do not log IMS tokens, Authorization headers, or OAuth secrets" });
          break;
        }
        if (referencesWholeParamsObject(arg)) {
          hits.push({
            ...locStart(n),
            message:
              "Logging the entire params object also logs __ow_headers.authorization (the caller's IMS token) and any secret input params — log only the specific, non-sensitive fields you need",
          });
          break;
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 26. legacy-application-pattern
// ---------------------------------------------------------------------------

/**
 * The real, common App Builder shape nests `actions:` several levels deep —
 * `application.runtimeManifest.packages.<name>.actions` — not directly under
 * `application`. A one-level-deep check structurally can never see this,
 * making the rule blind on virtually every real (and Adobe-generated) app.
 */
function hasKeyAtAnyDepth(obj: unknown, key: string, maxDepth = 8): boolean {
  if (maxDepth < 0 || !obj || typeof obj !== "object") return false;
  if (Array.isArray(obj)) return obj.some((item) => hasKeyAtAnyDepth(item, key, maxDepth - 1));
  const rec = obj as Record<string, unknown>;
  if (key in rec) return true;
  return Object.values(rec).some((v) => hasKeyAtAnyDepth(v, key, maxDepth - 1));
}

export const legacyApplicationPatternChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/structure/use-the-extensions-pattern-in-app-config-yaml-not-actions-applic",
  kind: "file",
  appliesTo: isAppConfigYaml,
  check(ctx) {
    const doc = parseYamlSafe(ctx.content);
    if (!doc || typeof doc !== "object") return [];
    const d = doc as Record<string, unknown>;
    const hasApplication = "application" in d;
    const applicationObj = d.application;
    const hasActionsTopLevel = "actions" in d;
    const hasActionsNested = hasKeyAtAnyDepth(applicationObj, "actions");
    const hasExtensions = "extensions" in d;
    // `application:`/`actions:` at the top level is the CORRECT, standard
    // shape for an ordinary (non-Admin-UI-SDK) App Builder app — it's only
    // "legacy" for an app that's actually meant to register as an Admin UI
    // SDK extension. Without this gate, every plain backend-actions app in
    // the sample corpus was flagged for using its own correct pattern.
    if (hasApplication && (hasActionsTopLevel || hasActionsNested) && !hasExtensions && isAdminUiSdkApp(ctx.filePath, doc)) {
      return [{ line: 1, message: "app.config.yaml uses the legacy application/actions pattern — use the extensions pattern instead" }];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 27. admin-ui-code-location (repo)
// ---------------------------------------------------------------------------

export const adminUiCodeLocationChecker: RepoChecker = {
  ruleId: "appbuilder/admin-ui-sdk/structure/place-all-admin-ui-sdk-code-under-src-commerce-backend-ui-1-not-",
  kind: "repo",
  async check(ctx) {
    const appConfig = ctx.allFiles.find((f) => isAppConfigYaml(f));
    if (!appConfig) return [];
    let content: string;
    try {
      content = await readFile(appConfig, "utf8");
    } catch {
      return [];
    }
    const doc = parseYamlSafe(content);
    if (!doc || typeof doc !== "object" || !("extensions" in (doc as object))) return [];
    const projectRootNorm = ctx.projectRoot.replace(/\\/g, "/").replace(/\/$/, "");
    const hits: CheckHit[] = [];
    for (const f of ctx.allFiles) {
      const norm = f.replace(/\\/g, "/");
      const rel = norm.startsWith(projectRootNorm + "/") ? norm.slice(projectRootNorm.length + 1) : norm;
      if (/^actions\//.test(rel) || /^web-src\//.test(rel)) {
        hits.push({ line: 1, path: f, message: `Admin UI SDK code found at repo-root '${rel}' — place all Admin UI SDK code under src/commerce-backend-ui-<N>/, not actions/ or web-src/ at repo root` });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 28. application-block-location
// ---------------------------------------------------------------------------

export const applicationBlockLocationChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/structure/application-block-belongs-in-ext-config-yaml-not-app-config-yaml",
  kind: "file",
  appliesTo: isAppConfigYaml,
  check(ctx) {
    const doc = parseYamlSafe(ctx.content);
    // An `application:` block in app.config.yaml is completely ordinary and
    // correct for a non-Admin-UI-SDK app — only misplaced when the app IS an
    // Admin UI SDK extension (where it belongs in ext.config.yaml instead).
    if (doc && typeof doc === "object" && "application" in (doc as object) && isAdminUiSdkApp(ctx.filePath, doc)) {
      return [{ line: 1, message: "application: block found in app.config.yaml — it belongs in ext.config.yaml, not app.config.yaml" }];
    }
    return [];
  },
};
