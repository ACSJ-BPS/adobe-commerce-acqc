import { readFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import type { CheckHit, CheckContext, FileChecker, RepoChecker } from "../../types.js";
import { isSourceFile } from "@abqc/core";
import { walkAst, findFunctions, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

// ---------------------------------------------------------------------------
// Gap-fill batch — API Mesh checkers (rules audit items 21-33).
// Self-contained: small local helpers duplicate a few conveniences that
// already exist (unexported) in apimesh.ts, rather than reaching into that
// file's internals.
// ---------------------------------------------------------------------------

const isMeshConfigPath = (p: string): boolean => /(^|\/)meshConfig(\..+)?\.json$/i.test(p.replace(/\\/g, "/"));
const isResolverOrHookPath = (p: string): boolean => isSourceFile(p) && /resolver|hook/i.test(p);
const isHookPath = (p: string): boolean => isSourceFile(p) && /hook/i.test(p);
const isResolverPath = (p: string): boolean => isSourceFile(p) && /resolver/i.test(p);

function parseJsonSafe(content: string): unknown | undefined {
  try {
    return JSON.parse(content);
  } catch {
    return undefined;
  }
}

/** Adobe API Mesh configs are commonly wrapped as `{ meshConfig: {...} }`; unwrap when present. */
function meshRoot(parsed: unknown): unknown {
  if (parsed && typeof parsed === "object" && "meshConfig" in (parsed as Record<string, unknown>)) {
    const inner = (parsed as Record<string, unknown>).meshConfig;
    if (inner && typeof inner === "object") return inner;
  }
  return parsed;
}

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

function hasKeyAnywhere(obj: unknown, keyName: string): boolean {
  let found = false;
  walkJsonKeys(obj, (k) => {
    if (k.toLowerCase() === keyName.toLowerCase()) found = true;
  });
  return found;
}

function lineOf(content: string, needle: string): number {
  const idx = content.indexOf(needle);
  if (idx === -1) return 1;
  return content.slice(0, idx).split("\n").length;
}

function offsetToLine(content: string, offset: number): number {
  let line = 1;
  const cap = Math.min(offset, content.length);
  for (let i = 0; i < cap; i++) if (content[i] === "\n") line++;
  return line;
}

function rangeOf(node: AstNode | undefined): [number, number] | undefined {
  return node ? (node as unknown as { range?: [number, number] }).range : undefined;
}

function sourceTextOf(content: string, node: AstNode | undefined): string {
  const range = rangeOf(node);
  if (!range) return "";
  return content.slice(range[0], range[1]);
}

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

// ---------------------------------------------------------------------------
// 21. mesh-hook-token-cache
// STATIC (heuristic). Known limits: caching implemented via an external cache
// library not matching this pattern evades detection.
// ---------------------------------------------------------------------------

const TOKEN_MINT_RE = /\b(getAccessToken|generateToken|refreshToken)\s*\(/g;
const STATE_GET_RE = /\bstate\.get\s*\(|\bState\.get\s*\(/;

export const meshHookTokenCacheChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/auth/cache-short-lived-oauth-ims-tokens-in-a-runtime-action-don-t-ref",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    const re = new RegExp(TOKEN_MINT_RE.source, "g");
    let m: RegExpExecArray | null;
    while ((m = re.exec(ctx.content))) {
      const before = ctx.content.slice(0, m.index);
      if (!STATE_GET_RE.test(before)) {
        hits.push({
          line: offsetToLine(ctx.content, m.index),
          message: `${m[1]}(...) call with no state.get(/State.get( cache lookup earlier in the file — cache short-lived OAuth/IMS tokens in a Runtime action instead of refreshing per-request.`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 22. mesh-cache-control-header
// MECHANICAL (proxy). Known limits: headers set programmatically inside a
// hook (JS source) rather than declaratively in JSON config are invisible.
// ---------------------------------------------------------------------------

export const meshCacheControlHeaderChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/caching/set-explicit-cache-control-headers-matching-response-semantics",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    // Adobe API Mesh's real schema key is camelCase `cacheControl` (e.g.
    // `"cache": { "cacheControl": "public, max-age=120" }`) — the hyphenated
    // `cache-control` form (an HTTP header spelling, not this JSON schema's
    // key) never appears in a real meshConfig.json, so checking only for it
    // was a 100% false positive on every compliant config.
    if (hasKeyAnywhere(meshRoot(parsed), "cacheControl") || hasKeyAnywhere(meshRoot(parsed), "cache-control")) return [];
    return [
      {
        line: 1,
        message:
          "No Cache-Control header transform found anywhere in meshConfig.json — set explicit Cache-Control headers matching response semantics.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 23. mesh-ttl-blanket
// STATIC (heuristic). Known limits: two fields correctly and intentionally sharing a
// TTL is indistinguishable from a lazy blanket value.
// ---------------------------------------------------------------------------

export const meshTtlBlanketChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/caching/align-field-level-cache-ttls-with-data-volatility-30s-3600s-typi",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const ttls: number[] = [];
    walkJsonKeys(meshRoot(parsed), (k, v) => {
      if (k.toLowerCase() === "ttl" && typeof v === "number") ttls.push(v);
    });
    if (ttls.length >= 2 && ttls.every((t) => t === ttls[0])) {
      return [
        {
          line: 1,
          message: `All ${ttls.length} cache-configured fields share the exact same TTL (${ttls[0]}s) — align field-level cache TTLs with data volatility instead of a blanket value.`,
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 24. caching-topology-doc
// ---------------------------------------------------------------------------

export const cachingTopologyDocChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/caching/understand-the-three-caching-layers-mesh-field-http-cdn-upstream",
  kind: "repo",
  async check(ctx) {
    const hasDoc = ctx.allFiles.some((f) => /docs\/caching-topology\.md$/i.test(f.replace(/\\/g, "/")));
    if (hasDoc) return [];
    return [
      {
        line: 1,
        message:
          "No docs/caching-topology.md found — document the three caching layers (mesh field, HTTP/CDN, upstream) and how their TTLs align.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 25. mesh-source-rename-history
// STATIC (heuristic). Known limits: a rename done as delete-old-entry + add-new-entry
// looks like two unrelated sources; a legitimate one-time correction looks
// identical to a casual rename.
// ---------------------------------------------------------------------------

const REMOVED_NAME_RE = /^-\s*"name"\s*:\s*"([^"]+)"/;
const ADDED_NAME_RE = /^\+\s*"name"\s*:\s*"([^"]+)"/;
const RENAME_LOOKAHEAD = 5;

export const meshSourceRenameHistoryChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/config/source-names-are-public-schema-choose-stable-names-never-rename-",
  kind: "repo",
  async check(ctx) {
    let log: string;
    try {
      log = execFileSync("git", ["log", "-p", "--follow", "--", "meshConfig.json"], {
        cwd: ctx.projectRoot,
        encoding: "utf8",
        stdio: ["ignore", "pipe", "ignore"],
      });
    } catch {
      return [];
    }
    if (!log.trim()) return [];
    const lines = log.split("\n");
    for (let i = 0; i < lines.length; i++) {
      const removed = REMOVED_NAME_RE.exec(lines[i] ?? "");
      if (!removed) continue;
      for (let j = i + 1; j < Math.min(i + 1 + RENAME_LOOKAHEAD, lines.length); j++) {
        const added = ADDED_NAME_RE.exec(lines[j] ?? "");
        if (added && added[1] !== removed[1]) {
          return [
            {
              line: 1,
              message: `meshConfig.json history shows source name "${removed[1]}" renamed to "${added[1]}" — source names are public schema; choose stable names and never rename casually.`,
            },
          ];
        }
      }
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 26. mesh-structured-log
// STATIC (heuristic). Known limits: structured logging done via an external wrapper
// whose field coverage can't be verified from a literal-object check.
// ---------------------------------------------------------------------------

export const meshStructuredLogChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/observability/log-correlation-id-source-name-operation-name-latency-status-per",
  kind: "file",
  appliesTo: isResolverOrHookPath,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      const text = calleeText(callee);
      if (text !== "console.log" && text !== "console.error") return;
      const args = ((n as AstNode & { arguments?: AstNode[] }).arguments as AstNode[] | undefined) ?? [];
      if (args.length !== 1) return;
      const arg = args[0];
      if (arg?.type === "Literal" && typeof (arg as AstNode & { value: unknown }).value === "string") {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "console.log/console.error called with a single plain string literal — log structured fields (correlation ID, source name, operation name, latency, status) instead.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 27. workspace-env-mapping
// STATIC (heuristic). Known limits: mapping done manually/interactively outside CI is
// invisible to a repo scan; non-standard naming (blue/green) that's actually
// correctly separated doesn't match the dev/stage/prod pattern.
// ---------------------------------------------------------------------------

export const workspaceEnvMappingChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/operations/map-dev-console-workspaces-to-environments-dev-stage-prod",
  kind: "repo",
  async check(ctx) {
    const meshFiles = ctx.allFiles.filter((f) => /meshConfig.*\.json$/i.test(f.replace(/\\/g, "/")));
    if (meshFiles.length === 0) return [];
    const envPatterns: Array<[string, RegExp]> = [
      ["dev", /^dev$/i],
      ["stage", /^stage$/i],
      ["prod", /^prod(uction)?$/i],
    ];
    const found = new Set<string>();
    for (const f of meshFiles) {
      const segments = f.replace(/\\/g, "/").split("/");
      for (const [label, re] of envPatterns) {
        if (segments.some((s) => re.test(s))) found.add(label);
      }
    }
    if (found.size < 3) {
      return [
        {
          line: 1,
          message: `Found meshConfig file(s) but only ${found.size}/3 dev/stage/prod environment-segment mesh configs — map Dev Console workspaces to environments (dev/stage/prod) explicitly.`,
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 28. mesh-local-dev-doc
// ---------------------------------------------------------------------------

export const meshLocalDevDocChecker: RepoChecker = {
  ruleId: "appbuilder/api-mesh/operations/use-aio-api-mesh-run-for-local-dev-with-graphiql-and-httpdetails",
  kind: "repo",
  async check(ctx) {
    const docFiles = ctx.allFiles.filter((f) => /(^|\/)(readme|contributing)\.md$/i.test(f.replace(/\\/g, "/")));
    for (const f of docFiles) {
      try {
        const content = await readFile(f, "utf8");
        if (content.includes("aio api-mesh:run")) return [];
      } catch {
        continue;
      }
    }
    return [
      {
        line: 1,
        message:
          "No README/CONTRIBUTING doc mentions 'aio api-mesh:run' — document the local-dev workflow (GraphiQL + httpDetails) for contributors.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 29. mesh-no-ratelimit-guard
// STATIC (heuristic). Known limits: rate limiting enforced by an external API
// gateway/CDN outside this repo is invisible.
// ---------------------------------------------------------------------------

const RATE_LIMIT_TERM_RE = /ratelimit|rate_limit|throttle/i;

export const meshNoRatelimitGuardChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/performance/don-t-rely-on-mesh-for-per-tenant-rate-limiting-enforce-upstream",
  kind: "file",
  appliesTo: isResolverOrHookPath,
  check(ctx) {
    const hasRateLimitTerm = RATE_LIMIT_TERM_RE.test(ctx.content);
    const hasStateGet = STATE_GET_RE.test(ctx.content);
    if (hasRateLimitTerm && hasStateGet) return [];
    return [
      {
        line: 1,
        message:
          "No State-backed rate-limit counter pattern (ratelimit/throttle + state.get() ) found — don't rely on mesh for per-tenant rate limiting, enforce upstream.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 30. mesh-hook-slow-call
// STATIC (heuristic). Known limits: always under-flags slow SDKs not in this
// denylist; a matched call might actually be reading a pre-warmed in-memory
// cache internally.
// ---------------------------------------------------------------------------

const SLOW_CALL_RE = /\bims\.\w+|\blaunchDarkly\.|\bnew\s+Vault\b|\baxios\s*\(|\baxios\.\w+|\bfetch\s*\(/;

export const meshHookSlowCallChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/performance/hooks-run-per-request-keep-fast-and-deterministic-no-slow-extern",
  kind: "file",
  appliesTo: isHookPath,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const fns = findFunctions(parsed.ast);
    walkAst(parsed.ast, (n) => {
      if (n.type !== "AwaitExpression") return;
      const arg = member(n, "argument");
      if (!arg) return;
      const text = sourceTextOf(ctx.content, arg);
      if (!SLOW_CALL_RE.test(text)) return;
      const idx = rangeOf(n)?.[0];
      const enclosing = fns.find((f) => {
        const r = rangeOf(f.node);
        return r && idx !== undefined && idx >= r[0] && idx <= r[1];
      });
      const scopeText = enclosing ? sourceTextOf(ctx.content, enclosing.node) : ctx.content;
      if (!STATE_GET_RE.test(scopeText)) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "Hook awaits a slow external call (ims/launchDarkly/Vault/axios/fetch) with no state.get( cache nearby — hooks run per-request; keep them fast and deterministic, no slow external calls.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 31. mesh-error-leak
// STATIC (heuristic). Known limits: leak via a passthrough schema field exposing raw
// upstream JSON, not an explicit error construction, is outside scope.
// ---------------------------------------------------------------------------

const ERROR_CONSTRUCT_RE = /\bnew\s+Error\s*\(|^\s*throw\b/;
const LEAK_INTERP_RE =
  /\$\{[^}]*\b(?:error\.message|err\.response|\w+\.stack)\b[^}]*\}|\b(?:error\.message|err\.response|\w+\.stack)\b\s*\+|\+\s*(?:error|err)\.(?:message|response|stack)\b/;

export const meshErrorLeakChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/security/normalize-error-messages-in-production-don-t-leak-schema-interna",
  kind: "file",
  appliesTo: isResolverPath,
  check(ctx) {
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (ERROR_CONSTRUCT_RE.test(line) && LEAK_INTERP_RE.test(line)) {
        hits.push({
          line: i + 1,
          message:
            "Error message built by interpolating error.message/err.response/.stack — normalize error messages in production, don't leak schema/upstream internals.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 32. mesh-raw-naming
// STATIC (heuristic). Known limits: always under-flags naming conventions outside
// this regex; a domain where snake_case is the accepted public convention
// gets flagged unnecessarily.
// ---------------------------------------------------------------------------

const RAW_NAME_RE = /_v\d+$|_t$|[a-z]+_[a-z]+/;

export const meshRawNamingChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/transforms/use-prefix-and-rename-for-a-coherent-frontend-friendly-schema",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const root = meshRoot(parsed);
    if (hasKeyAnywhere(root, "rename") || hasKeyAnywhere(root, "prefix")) return [];
    const seen = new Set<string>();
    const hits: CheckHit[] = [];
    walkJsonKeys(root, (k, v) => {
      const candidates = [k, ...(typeof v === "string" ? [v] : [])];
      for (const name of candidates) {
        if (RAW_NAME_RE.test(name) && !seen.has(name)) {
          seen.add(name);
          hits.push({
            line: lineOf(ctx.content, name),
            message: `Raw upstream-style name "${name}" found with no rename/prefix transform anywhere in config — use prefix and rename for a coherent, frontend-friendly schema.`,
          });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 33. mesh-typemerging-unbounded
// STATIC (heuristic). Known limits: fan-out risk from a nested resolver behind the
// merge point is invisible at this top-level config check.
// ---------------------------------------------------------------------------

function isPluralish(name: string): boolean {
  return /s$/i.test(name) && !/ss$/i.test(name);
}

export const meshTypeMergingUnboundedChecker: FileChecker = {
  ruleId: "appbuilder/api-mesh/transforms/use-federation-or-type-merging-only-with-clear-data-relationship",
  kind: "file",
  appliesTo: isMeshConfigPath,
  check(ctx) {
    const parsed = parseJsonSafe(ctx.content);
    if (parsed === undefined) return [];
    const root = meshRoot(parsed);
    let mergingBlock: unknown;
    walkJsonKeys(root, (k, v) => {
      if (!mergingBlock && /^(typeMerging|federationConfig)$/i.test(k)) mergingBlock = v;
    });
    if (!mergingBlock) return [];
    const hits: CheckHit[] = [];
    walkJsonKeys(mergingBlock, (k, v, parent) => {
      if (k !== "fieldName" || typeof v !== "string" || !isPluralish(v)) return;
      const hasBinding = "key" in parent || "argsFromKeys" in parent;
      if (!hasBinding) {
        hits.push({
          line: lineOf(ctx.content, v),
          message: `Type-merging binds a list-returning field "${v}" with no explicit key/argsFromKeys — use federation or type-merging only with clear data relationships.`,
        });
      }
    });
    return hits;
  },
};
