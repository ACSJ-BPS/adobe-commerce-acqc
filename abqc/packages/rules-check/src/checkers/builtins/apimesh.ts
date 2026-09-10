import { basename } from "node:path";
import { readFile, stat } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import type { CheckHit, CheckContext, FileChecker, RepoChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import {
  walkAst,
  findFunctions,
  locStart,
  cyclomaticComplexity,
  countFunctionLines,
  type AstNode,
} from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

// ---------------------------------------------------------------------------
// Shared JSON/mesh helpers
// ---------------------------------------------------------------------------

const isMeshConfigPath = (p: string): boolean => /(^|\/)meshConfig(\..+)?\.json$/i.test(p.replace(/\\/g, "/"));
// Matches a whole path SEGMENT, not a substring anywhere in the path — an
// unrelated ancestor directory whose name merely contains "prod" (e.g.
// "my-productivity-tools") must not count as a production-scoped file.
const isProdPath = (p: string): boolean =>
  p.replace(/\\/g, "/").split("/").some((part) => /^prod(uction)?$/i.test(part));
const isResolverSourceFile = (p: string): boolean => isSourceFile(p) && /resolver/i.test(p);

function parseJsonSafe(content: string): unknown | undefined {
  try {
    return JSON.parse(content);
  } catch {
    return undefined;
  }
}

/** Adobe API Mesh configs are commonly wrapped as `{ meshConfig: {...} }`; unwrap when present. */
function meshRoot(parsed: unknown): Record<string, unknown> {
  if (parsed && typeof parsed === "object" && "meshConfig" in (parsed as Record<string, unknown>)) {
    const inner = (parsed as Record<string, unknown>).meshConfig;
    if (inner && typeof inner === "object") return inner as Record<string, unknown>;
  }
  return (parsed as Record<string, unknown>) ?? {};
}

/** Depth-first walk over a parsed JSON/YAML value, visiting every (key, value, parent) pair. */
function walkJsonKeys(obj: unknown, visit: (key: string, value: unknown, parent: Record<string, unknown>) => void): void {
  if (Array.isArray(obj)) {
    for (const item of obj) walkJsonKeys(item, visit);
    return;
  }
  if (obj && typeof obj === "object") {
    const rec = obj as Record<string, unknown>;
    for (const [k, v] of Object.entries(rec)) {
      visit(k, v, rec);
      walkJsonKeys(v, visit);
    }
  }
}

function collectByKey(obj: unknown, keyName: string, out: unknown[]): void {
  walkJsonKeys(obj, (k, v) => {
    if (k === keyName) out.push(v);
  });
}

function hasKeyAnywhere(obj: unknown, keyName: string): boolean {
  let found = false;
  walkJsonKeys(obj, (k) => {
    if (k.toLowerCase() === keyName.toLowerCase()) found = true;
  });
  return found;
}

/** Approximate line number of the first occurrence of a quoted key/value in the raw source text. */
function lineOf(content: string, needle: string): number {
  const idx = content.indexOf(`"${needle}"`);
  if (idx === -1) return 1;
  return content.slice(0, idx).split("\n").length;
}

function findWorkflowFiles(allFiles: string[]): string[] {
  return allFiles.filter((f) => /\.github\/workflows\/[^/]*\.ya?ml$/i.test(f.replace(/\\/g, "/")));
}

// ---------------------------------------------------------------------------
// 1. mesh-header-secrets
// ---------------------------------------------------------------------------

const SECRET_HEADER_RE = /^(authorization|x-api-key|x-auth-token|apikey|api-key)$/i;
const ENV_TEMPLATE_RE = /^\{\{env\.[A-Z0-9_]+\}\}$/;

export const meshHeaderSecretsChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/auth/use-operationheaders-schemaheaders-for-upstream-credentials-sour",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const hits: CheckHit[] = [];
    walkJsonKeys(meshRoot(parsed), (k, v) => {
      if (!/^(operationHeaders|schemaHeaders)$/.test(k)) return;
      if (!v || typeof v !== "object" || Array.isArray(v)) return;
      for (const [hk, hv] of Object.entries(v as Record<string, unknown>)) {
        if (SECRET_HEADER_RE.test(hk) && typeof hv === "string" && !ENV_TEMPLATE_RE.test(hv)) {
          hits.push({
            line: lineOf(ctx.content, hk),
            message: `Header "${hk}" in ${k} is a literal credential — reference upstream credentials via {{env.VAR_NAME}} instead`,
          });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 2. no-ims-token-in-response (STATIC (heuristic))
// Known limits: a token returned under a renamed/obfuscated key or via spread
// evades detection; an unrelated CSRF/session-token property with a similar
// name is a false positive.
// ---------------------------------------------------------------------------

const RETURNED_TOKEN_KEY_RE = /^(token|access_token|imsAccessToken|bearer)$/i;

export const noImsTokenInResponseChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/auth/never-expose-ims-tokens-to-the-browser-mesh-or-runtime-must-hold",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ReturnStatement") return;
      const arg = member(n, "argument");
      if (!arg || arg.type !== "ObjectExpression") return;
      walkAst(arg, (inner) => {
        if (inner.type !== "Property") return;
        const key = member(inner, "key");
        const name = identName(key) ?? (key?.type === "Literal" ? String((key as AstNode & { value: unknown }).value) : undefined);
        if (name && RETURNED_TOKEN_KEY_RE.test(name)) {
          hits.push({ ...locStart(inner), message: `Response object exposes "${name}" — never return IMS tokens to the browser; hold them in the mesh/runtime` });
        }
      });
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 3. mesh-cache-personalized (STATIC (heuristic), P3)
// Known limits: a personalized field named non-obviously (basket, myItems)
// evades this; a field like customerGroup that's actually static reference
// data is a false positive.
// ---------------------------------------------------------------------------

const PERSONALIZED_FIELD_RE = /cart|customer|order|wishlist|session|checkout/i;

function findPersonalizedCacheFields(obj: unknown, inCache: boolean, content: string, hits: CheckHit[]): void {
  if (Array.isArray(obj)) {
    for (const item of obj) findPersonalizedCacheFields(item, inCache, content, hits);
    return;
  }
  if (obj && typeof obj === "object") {
    for (const [k, v] of Object.entries(obj as Record<string, unknown>)) {
      const nowInCache = inCache || /^(cache|ttl)$/i.test(k);
      if (nowInCache && PERSONALIZED_FIELD_RE.test(k)) {
        hits.push({
          line: lineOf(content, k),
          message: `Field "${k}" looks personalized but appears under cache/ttl config — cache only static reference data, never personalized responses`,
        });
      }
      findPersonalizedCacheFields(v, nowInCache, content, hits);
    }
  }
}

export const meshCachePersonalizedChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/caching/cache-only-static-reference-data-never-personalized-responses",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const hits: CheckHit[] = [];
    findPersonalizedCacheFields(meshRoot(parsed), false, ctx.content, hits);
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 4. mesh-literal-secret
// ---------------------------------------------------------------------------

const HIGH_ENTROPY_SECRET_RE = /^(sk|pk|xox|ghp|glpat)_[A-Za-z0-9]{10,}$/;
const SECRET_KEY_NAME_RE = /api[_-]?key|secret|token|password/i;

export const meshLiteralSecretChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/config/reference-secrets-via-env-vars-never-literal-api-keys-in-meshcon",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const hits: CheckHit[] = [];
    walkJsonKeys(parsed, (k, v) => {
      if (typeof v !== "string") return;
      if (HIGH_ENTROPY_SECRET_RE.test(v)) {
        hits.push({ line: lineOf(ctx.content, v), message: `Literal secret-shaped value for "${k}" — reference secrets via {{env.VAR}}, never a literal in meshConfig.json` });
        return;
      }
      if (SECRET_KEY_NAME_RE.test(k) && !/^\{\{env\./.test(v)) {
        hits.push({ line: lineOf(ctx.content, k), message: `Key "${k}" holds a literal value — reference secrets via {{env.VAR}}, never a literal in meshConfig.json` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 5. mesh-config-size (repo)
// ---------------------------------------------------------------------------

const MESH_CONFIG_MAX_BYTES = 375 * 1024;

export const meshConfigSizeChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/config/keep-meshconfig-json-under-375-kb-pre-filter-large-openapi-schem",
  kind: "repo",
  async check(ctx) {
    const meshFile = ctx.allFiles.find((f) => /^meshConfig(\..+)?\.json$/i.test(basename(f)));
    if (!meshFile) return [];
    try {
      const info = await stat(meshFile);
      if (info.size >= MESH_CONFIG_MAX_BYTES) {
        return [{ line: 1, path: meshFile, message: `meshConfig.json is ${Math.round(info.size / 1024)} KB (>= 375 KB) — pre-filter large OpenAPI schemas` }];
      }
    } catch {
      // unreadable — nothing to report
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 6 & 18. mesh-admin-unfiltered / mesh-filterschema-missing (shared logic, STATIC (heuristic) P3)
// Simplified per spec: flag any admin/internal/backoffice/debug-ish
// operation/type/resolver name when the config has no `filterSchema`
// transform at all.
// ---------------------------------------------------------------------------

const ADMIN_NAME_RE = /^(admin|internal|backoffice|debug)/i;
const ADMIN_TEXT_RE = /\b(admin|internal|backoffice|debug)\w*/i;

function adminOperationsUnfiltered(ctx: CheckContext, message: string): CheckHit[] {
  const parsed = parseJsonSafe(ctx.content);
  if (parsed === undefined) return [];
  const root = meshRoot(parsed);
  const names: string[] = [];
  const sources = root.sources;
  if (Array.isArray(sources)) {
    for (const s of sources) {
      const name = (s as Record<string, unknown> | undefined)?.name;
      if (typeof name === "string") names.push(name);
    }
  }
  const resolvers = root.additionalResolvers;
  if (Array.isArray(resolvers)) {
    for (const r of resolvers) {
      if (typeof r === "string") names.push(r);
      else if (r && typeof r === "object") {
        const ro = r as Record<string, unknown>;
        if (typeof ro.type === "string") names.push(ro.type);
        if (typeof ro.field === "string") names.push(ro.field);
      }
    }
  }
  const typeDefs = root.additionalTypeDefs;
  const typeDefsFlag = typeof typeDefs === "string" && ADMIN_TEXT_RE.test(typeDefs);
  const nameFlag = names.some((n) => ADMIN_NAME_RE.test(n));
  if (!typeDefsFlag && !nameFlag) return [];
  const transforms = root.transforms;
  const hasFilterSchema = Array.isArray(transforms) && transforms.some((t) => t && typeof t === "object" && "filterSchema" in (t as object));
  if (hasFilterSchema) return [];
  return [{ line: 1, message }];
}

export const meshAdminUnfilteredChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/config/separate-public-core-mesh-from-internal-backoffice-mesh",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    return adminOperationsUnfiltered(
      ctx,
      "Admin/internal/backoffice operation exposed on the same mesh as the public core mesh — separate the internal/backoffice mesh out",
    );
  },
};

export const meshFilterSchemaMissingChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/transforms/use-filterschema-to-remove-admin-or-debug-operations-from-the-pu",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    return adminOperationsUnfiltered(
      ctx,
      "Admin/debug operation exposed without a filterSchema transform removing it from the public schema",
    );
  },
};

// ---------------------------------------------------------------------------
// 7. mesh-config-git-ci (repo, STATIC (heuristic) P4+P1)
// ---------------------------------------------------------------------------

export const meshConfigGitCiChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/config/treat-meshconfig-json-as-code-git-versioned-pr-reviewed-ci-deplo",
  kind: "repo",
  async check(ctx) {
    const anyMeshFile = ctx.allFiles.find((f) => /^meshConfig(\..+)?\.json$/i.test(basename(f)));
    if (!anyMeshFile) return [];
    const hits: CheckHit[] = [];
    try {
      execFileSync("git", ["ls-files", "--error-unmatch", "meshConfig.json"], {
        cwd: ctx.projectRoot,
        stdio: ["ignore", "pipe", "ignore"],
      });
    } catch {
      hits.push({ line: 1, path: anyMeshFile, message: "meshConfig.json is not tracked in git — treat mesh config as code (git-versioned, PR-reviewed)" });
    }
    const workflowFiles = findWorkflowFiles(ctx.allFiles);
    let deploysViaCi = false;
    for (const wf of workflowFiles) {
      try {
        const content = await readFile(wf, "utf8");
        if (content.includes("aio api-mesh:update")) {
          deploysViaCi = true;
          break;
        }
      } catch {
        // unreadable workflow file — ignore
      }
    }
    if (!deploysViaCi) {
      hits.push({ line: 1, message: "No CI workflow deploys meshConfig.json via `aio api-mesh:update` — treat mesh config as code (CI-deployed)" });
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 8. mesh-log-forwarding (STATIC (heuristic) P5)
// ---------------------------------------------------------------------------

export const meshLogForwardingChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/observability/set-up-log-forwarding-to-new-relic-or-your-siem-before-go-live",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const root = meshRoot(parsed);
    if (!hasKeyAnywhere(root, "logForwarding")) {
      return [{ line: 1, message: "No logForwarding configuration found — set up log forwarding to New Relic/your SIEM before go-live" }];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 9. mesh-httpdetails-prod (MECHANICAL, P5)
// ---------------------------------------------------------------------------

export const meshHttpDetailsProdChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/observability/use-httpdetails-only-in-non-prod-exposes-payloads-with-potential",
  kind: "file",
  appliesTo: (p) => isMeshConfigPath(p) && isProdPath(p),
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const root = meshRoot(parsed);
    const responseConfig = root.responseConfig as Record<string, unknown> | undefined;
    if (responseConfig && responseConfig.includeHTTPDetails === true) {
      return [{ line: lineOf(ctx.content, "includeHTTPDetails"), message: "includeHTTPDetails is true in a prod mesh config — this exposes upstream payloads; use it only in non-prod" }];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 10. mesh-dev-prod-endpoint (repo)
// ---------------------------------------------------------------------------

function extractEndpoints(root: Record<string, unknown>): string[] {
  const out: string[] = [];
  walkJsonKeys(root, (k, v) => {
    if (k === "endpoint" && typeof v === "string") out.push(v);
  });
  return out;
}

function isEnvTemplate(v: string): boolean {
  return /^\{\{env\./.test(v);
}

function hostOf(url: string): string | null {
  try {
    return new URL(url).host;
  } catch {
    return null;
  }
}

export const meshDevProdEndpointChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/operations/never-point-a-dev-mesh-at-production-commerce-or-aem-endpoints",
  kind: "repo",
  async check(ctx) {
    const meshFiles = ctx.allFiles.filter((f) => /^meshConfig(\..+)?\.json$/i.test(basename(f)));
    const hits: CheckHit[] = [];
    // Match whole path SEGMENTS (not a substring test on the full path) so an
    // unrelated ancestor directory name that merely contains "dev"/"prod" as a
    // substring (e.g. a repo checked out under .../my-devprod-fixtures/...)
    // can't misclassify a file — only a real .../dev/... or .../prod/...
    // directory segment counts.
    const hasSegment = (f: string, seg: RegExp) => f.replace(/\\/g, "/").split("/").some((part) => seg.test(part));
    const devFiles = meshFiles.filter((f) => hasSegment(f, /^dev$/i));
    const prodFiles = meshFiles.filter((f) => hasSegment(f, /^prod(uction)?$/i));
    if (devFiles.length > 0 && prodFiles.length > 0) {
      const prodHosts = new Set<string>();
      for (const pf of prodFiles) {
        try {
          const parsed = JSON.parse(await readFile(pf, "utf8"));
          for (const ep of extractEndpoints(meshRoot(parsed))) {
            const h = hostOf(ep);
            if (h) prodHosts.add(h);
          }
        } catch {
          // unreadable/invalid prod config — skip
        }
      }
      for (const df of devFiles) {
        try {
          const parsed = JSON.parse(await readFile(df, "utf8"));
          for (const ep of extractEndpoints(meshRoot(parsed))) {
            if (isEnvTemplate(ep)) continue;
            const h = hostOf(ep);
            if (/production/i.test(ep) || (h && prodHosts.has(h))) {
              hits.push({ line: 1, path: df, message: `Dev mesh endpoint "${ep}" points at a production host — never point a dev mesh at production Commerce/AEM endpoints` });
            }
          }
        } catch {
          // unreadable/invalid dev config — skip
        }
      }
    } else if (meshFiles.length === 1) {
      const f = meshFiles[0];
      if (f) {
        try {
          const parsed = JSON.parse(await readFile(f, "utf8"));
          for (const ep of extractEndpoints(meshRoot(parsed))) {
            if (typeof ep === "string" && !isEnvTemplate(ep) && /production/i.test(ep)) {
              hits.push({ line: 1, path: f, message: `Endpoint "${ep}" contains "production" — verify this mesh isn't a dev mesh pointed at prod` });
            }
          }
        } catch {
          // unreadable/invalid config — skip
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 11. mesh-diff-before-update (repo)
// ---------------------------------------------------------------------------

export const meshDiffBeforeUpdateChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/operations/use-aio-api-mesh-get-to-diff-deployed-config-vs-local-before-upd",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const wf of findWorkflowFiles(ctx.allFiles)) {
      try {
        const content = await readFile(wf, "utf8");
        if (content.includes("aio api-mesh:update") && !content.includes("aio api-mesh:get")) {
          hits.push({ line: 1, path: wf, message: "CI runs `aio api-mesh:update` without first running `aio api-mesh:get` to diff deployed config vs local" });
        }
      } catch {
        // unreadable workflow file — skip
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 12. mesh-resolver-n-plus-1
// ---------------------------------------------------------------------------

function isFetchOrAxiosCallee(callee: AstNode | undefined): boolean {
  if (!callee) return false;
  if (callee.type === "Identifier") {
    const nm = identName(callee);
    return nm === "fetch" || nm === "axios";
  }
  if (callee.type === "MemberExpression") {
    const objName = identName(member(callee, "object"));
    return objName === "axios" || objName === "fetch";
  }
  return false;
}

function containsFetchOrAxiosCall(node: AstNode): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (found || n.type !== "CallExpression") return;
    if (isFetchOrAxiosCallee(member(n, "callee"))) found = true;
  });
  return found;
}

export const meshResolverNPlusOneChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/performance/avoid-n-1-in-additionalresolvers-batch-upstream-calls-or-add-bul",
  kind: "file",
  appliesTo: isResolverSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee?.type === "MemberExpression") {
          const propName = identName(member(callee, "property"));
          if (propName === "map" || propName === "forEach") {
            const args = (n as AstNode & { arguments?: AstNode[] }).arguments;
            const cb = args?.[0];
            if (cb && containsFetchOrAxiosCall(cb)) {
              hits.push({ ...locStart(n), message: `Possible N+1: ${propName}() callback makes an upstream fetch/axios call per item — batch upstream calls instead` });
            }
          }
        }
      } else if (n.type === "ForOfStatement") {
        const body = member(n, "body");
        if (body && containsFetchOrAxiosCall(body)) {
          hits.push({ ...locStart(n), message: "Possible N+1: for...of loop body makes an upstream fetch/axios call per iteration — batch upstream calls instead" });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 13. mesh-resolver-pagesize-guard (STATIC (heuristic))
// ---------------------------------------------------------------------------

const PAGE_SIZE_PROPS = new Set(["pageSize", "limit", "first"]);

function functionHasGuardFor(fn: AstNode, propName: string): boolean {
  let guarded = false;
  walkAst(fn, (n) => {
    if (guarded || n.type !== "IfStatement") return;
    const test = member(n, "test");
    if (!test) return;
    let refsProp = false;
    walkAst(test, (t) => {
      if (t.type === "Identifier" && identName(t) === propName) refsProp = true;
      if (t.type === "MemberExpression" && identName(member(t, "property")) === propName) refsProp = true;
    });
    if (refsProp) guarded = true;
  });
  return guarded;
}

export const meshResolverPageSizeGuardChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/performance/validate-args-in-resolvers-reject-extreme-pagesize-limit",
  kind: "file",
  appliesTo: isResolverSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node: fn } of findFunctions(parsed.ast)) {
      const accesses: Array<{ node: AstNode; prop: string }> = [];
      walkAst(fn, (n) => {
        if (n.type !== "MemberExpression") return;
        const objName = identName(member(n, "object"));
        const propName = identName(member(n, "property"));
        if (objName === "args" && propName && PAGE_SIZE_PROPS.has(propName)) {
          accesses.push({ node: n, prop: propName });
        }
      });
      for (const { node, prop } of accesses) {
        if (!functionHasGuardFor(fn, prop)) {
          hits.push({ ...locStart(node), message: `args.${prop} is used without a bounding check — validate args in resolvers and reject extreme pageSize/limit values` });
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 14. mesh-no-depth-guard (STATIC (heuristic))
// ---------------------------------------------------------------------------

export const meshNoDepthGuardChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/performance/don-t-rely-on-mesh-for-query-depth-or-cost-limits-they-aren-t-en",
  kind: "file",
  appliesTo: isResolverSourceFile,
  check(ctx) {
    if (!/depth\(|checkDepth|maxDepth/i.test(ctx.content)) {
      return [{ line: 1, message: "No depth/cost guard (depth(), checkDepth, maxDepth) found in this resolver file — don't rely on the mesh for query depth or cost limits, they aren't enforced there" }];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 15. mesh-introspection-prod
// ---------------------------------------------------------------------------

export const meshIntrospectionProdChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/security/disable-graphql-introspection-and-field-suggestions-in-productio",
  kind: "file",
  appliesTo: (p) => isMeshConfigPath(p) && isProdPath(p),
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const root = meshRoot(parsed);
    const disableVals: unknown[] = [];
    collectByKey(root, "disableIntrospection", disableVals);
    const introspectionVals: unknown[] = [];
    collectByKey(root, "introspection", introspectionVals);
    const hasDisableTrue = disableVals.some((v) => v === true);
    const explicitlyEnabled = disableVals.some((v) => v === false) || introspectionVals.some((v) => v === true);
    if (!hasDisableTrue || explicitlyEnabled) {
      return [{ line: 1, message: "GraphQL introspection is not explicitly disabled in this prod mesh config — set disableIntrospection: true" }];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 16. mesh-ssrf-endpoint
// ---------------------------------------------------------------------------

export const meshSsrfEndpointChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/security/no-user-controlled-hostnames-or-path-segments-in-source-endpoint",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const hits: CheckHit[] = [];
    walkJsonKeys(meshRoot(parsed), (k, v) => {
      if ((k === "endpoint" || k === "baseUrl") && typeof v === "string") {
        if (/\{\{(headers|context|request|args)\./.test(v)) {
          hits.push({ line: lineOf(ctx.content, v), message: `${k} "${v}" is user-controlled — never build source endpoints/paths from headers/context/request/args` });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 17. mesh-endpoint-https
// ---------------------------------------------------------------------------

export const meshEndpointHttpsChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/security/mesh-sources-must-be-public-https-with-valid-ssl-no-local-or-sel",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const hits: CheckHit[] = [];
    walkJsonKeys(meshRoot(parsed), (k, v) => {
      if (k !== "endpoint" || typeof v !== "string") return;
      if (/^http:\/\//i.test(v) || /localhost|127\.0\.0\.1|0\.0\.0\.0/i.test(v)) {
        hits.push({ line: lineOf(ctx.content, v), message: `Endpoint "${v}" is not a public HTTPS endpoint — mesh sources must be public HTTPS with valid SSL, no local/self-signed hosts` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 19. mesh-resolver-thin (STATIC (heuristic))
// ---------------------------------------------------------------------------

const RESOLVER_COMPLEXITY_MAX = 5;
const RESOLVER_LINES_MAX = 20;

export const meshResolverThinChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/transforms/keep-additionalresolvers-thin-orchestrate-don-t-implement-busine",
  kind: "file",
  appliesTo: isResolverSourceFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { name, node } of findFunctions(parsed.ast)) {
      const cc = cyclomaticComplexity(node);
      const len = countFunctionLines(node);
      if (cc > RESOLVER_COMPLEXITY_MAX || len > RESOLVER_LINES_MAX) {
        hits.push({
          ...locStart(node),
          message: `Resolver function${name ? ` '${name}'` : ""} is too complex (cyclomatic=${cc}, lines=${len}) — keep additionalResolvers thin, orchestrate rather than implement business logic`,
        });
      }
    }
    return hits;
  },
};
