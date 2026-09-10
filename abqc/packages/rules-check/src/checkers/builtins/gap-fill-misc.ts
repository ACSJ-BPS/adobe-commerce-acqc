import { readFile } from "node:fs/promises";
import { join } from "node:path";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isActionFile, isSourceFile } from "@abqc/core";
import { walkAst, findFunctions, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName, isJsxCapableFile } from "./shared.js";

// ---------------------------------------------------------------------------
// Gap-fill batch — Admin UI SDK publish/version/sandbox, security/adobe data
// residency, supply-chain, and React boolean-prop-naming checkers (rules
// audit items 18-20, 36, 37, 44, 45).
// ---------------------------------------------------------------------------

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

async function readSafe(p: string): Promise<string | null> {
  try {
    return await readFile(p, "utf8");
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// 18. admin-ui-publish-workflow
// STATIC (heuristic). Known limits: publish done manually via Console UI, entirely
// outside the repo, is invisible — significant under-flag risk.
// ---------------------------------------------------------------------------

const PROD_WORKSPACE_RE = /--workspace[=\s]+Production\b/i;
const APP_PUBLISH_RE = /\baio\s+app\s+publish\b/i;

export const adminUiPublishWorkflowChecker: RepoChecker = {
  ruleId: "appbuilder/admin-ui-sdk/deploy/app-must-be-published-in-a-production-workspace-for-accs-to-rend",
  kind: "repo",
  async check(ctx) {
    const workflowFiles = ctx.allFiles.filter((f) => /\.github\/workflows\/[^/]*\.ya?ml$/i.test(f.replace(/\\/g, "/")));
    let anyProdWithPublish = false;
    for (const f of workflowFiles) {
      const content = await readSafe(f);
      if (content === null) continue;
      if (PROD_WORKSPACE_RE.test(content) && APP_PUBLISH_RE.test(content)) {
        anyProdWithPublish = true;
        break;
      }
    }
    if (anyProdWithPublish) return [];
    return [
      {
        line: 1,
        message:
          "No CI workflow found with both a --workspace Production step and an 'aio app publish' step — verify the app is PUBLISHED in a Production workspace so ACCS can render extensions (publishing done manually via Console UI is invisible to this check).",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 19. uix-version-pin
// STATIC (heuristic). Known limits: can't verify the pinned version actually matches
// the current external App Review Checklist value.
// ---------------------------------------------------------------------------

const UIX_PACKAGES = ["@adobe/uix-core", "@adobe/uix-guest"];

function isUnpinnedUixVersion(ver: string): boolean {
  const v = ver.trim();
  return v === "latest" || /^[\^~*]/.test(v);
}

export const uixVersionPinChecker: RepoChecker = {
  ruleId: "appbuilder/admin-ui-sdk/deploy/pin-adobe-uix-core-and-adobe-uix-guest-versions-to-the-app-revie",
  kind: "repo",
  async check(ctx) {
    const pkgPath = join(ctx.projectRoot, "package.json");
    const raw = await readSafe(pkgPath);
    if (raw === null) return [];
    let pkg: { dependencies?: Record<string, string>; devDependencies?: Record<string, string> };
    try {
      pkg = JSON.parse(raw);
    } catch {
      return [];
    }
    const all = { ...pkg.dependencies, ...pkg.devDependencies };
    const hits: CheckHit[] = [];
    for (const name of UIX_PACKAGES) {
      const ver = all[name];
      if (!ver) {
        hits.push({ path: pkgPath, line: 1, message: `${name} is not a declared dependency — pin it to the App Review Checklist version.` });
      } else if (isUnpinnedUixVersion(ver)) {
        hits.push({
          path: pkgPath,
          line: 1,
          message: `${name} uses unpinned version "${ver}" — pin it to the exact App Review Checklist version.`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 20. extension-sandbox-attr
// STATIC (heuristic). Known limits: always under-flags new browser APIs not in this
// denylist; can't verify the sandbox attribute actually covers the specific
// extension point using these APIs.
// ---------------------------------------------------------------------------

const BROWSER_CAP_CALL_RE = /\bwindow\.print\s*\(|\bwindow\.open\s*\(|\bdocument\.execCommand\s*\(|\bnavigator\.clipboard\./;
const SANDBOX_LITERAL_RE = /['"`]sandbox['"`]/;

export const extensionSandboxAttrChecker: FileChecker = {
  ruleId: "appbuilder/admin-ui-sdk/integration/set-explicit-sandbox-attributes-on-extension-points-that-need-br",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    if (!BROWSER_CAP_CALL_RE.test(ctx.content)) return [];
    if (SANDBOX_LITERAL_RE.test(ctx.content)) return [];
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    for (let i = 0; i < lines.length; i++) {
      if (BROWSER_CAP_CALL_RE.test(lines[i] ?? "")) {
        hits.push({
          line: i + 1,
          message:
            "Call to a sandboxed browser capability (window.print/window.open/document.execCommand/navigator.clipboard) with no 'sandbox' attribute string literal anywhere in the file — set explicit sandbox attributes on extension points that need it.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 36. data-residency-unpinned
// STATIC (heuristic). Known limits: a residency-sensitive field with no naming hint
// is invisible; a coincidentally-named non-sensitive field gets flagged.
// ---------------------------------------------------------------------------

const RESIDENCY_HINT_RE = /eu|gdpr|pii|resident/i;
const REGION_PIN_RE = /\bregion\s*:|new\s+S3Client\s*\(\s*\{\s*region/;

function collectDeclaredNames(fn: AstNode): string[] {
  const names: string[] = [];
  const params = ((fn as AstNode & { params?: AstNode[] }).params as AstNode[] | undefined) ?? [];
  for (const p of params) {
    if (p.type === "Identifier") {
      const nm = identName(p);
      if (nm) names.push(nm);
    }
  }
  const body = (fn as AstNode & { body?: AstNode | null }).body;
  if (body) {
    walkAst(body, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const id = member(n, "id");
      if (id?.type === "Identifier") {
        const nm = identName(id);
        if (nm) names.push(nm);
      }
    });
  }
  return names;
}

export const dataResidencyUnpinnedChecker: FileChecker = {
  ruleId: "security/adobe/validate-data-residency-for-stored-files-especially-sensitive-da",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const fns = findFunctions(parsed.ast);
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      if (calleeText(member(n, "callee")) !== "files.write") return;
      const idx = rangeOf(n)?.[0];
      const enclosing = fns.find((f) => {
        const r = rangeOf(f.node);
        return r && idx !== undefined && idx >= r[0] && idx <= r[1];
      });
      if (!enclosing) return;
      const names = collectDeclaredNames(enclosing.node);
      const hasResidencyHint = names.some((nm) => RESIDENCY_HINT_RE.test(nm));
      if (!hasResidencyHint) return;
      const scopeText = sourceTextOf(ctx.content, enclosing.node);
      if (!REGION_PIN_RE.test(scopeText)) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message:
            "files.write( call in a function with a residency-hinting name (eu/gdpr/pii/resident) and no region-pinning config nearby — validate data residency for stored files, especially sensitive data.",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 37. release-approval-doc
// ---------------------------------------------------------------------------

export const releaseApprovalDocChecker: RepoChecker = {
  ruleId: "security/supply-chain/app-builder-apps-require-admin-approval-before-wide-release",
  kind: "repo",
  async check(ctx) {
    const hasApprovalDoc = ctx.allFiles.some((f) => /docs\/release-approvals\//i.test(f.replace(/\\/g, "/")));
    if (hasApprovalDoc) return [];
    return [
      {
        line: 1,
        message:
          "No docs/release-approvals/ directory with any file found — App Builder apps require admin approval before wide release; document the approval trail.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 44. boolean-prop-naming
// STATIC (heuristic). Known limits: a boolean prop passed as a plain identifier
// reference without type info (untyped JS) is invisible to this
// literal/shorthand-only detector.
// ---------------------------------------------------------------------------

const BOOL_PREFIX_RE = /^(is|has|should)[A-Z]/;
const EXCLUDED_BOOL_ATTRS = new Set([
  "disabled", "checked", "required", "readOnly", "hidden", "open",
  "multiple", "autoFocus", "selected", "defaultChecked",
]);

export const booleanPropNamingChecker: FileChecker = {
  ruleId: "react/components/boolean-props-are-isx-hasx-shouldx-never-just-x",
  kind: "file",
  appliesTo: isJsxCapableFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "JSXAttribute") return;
      const nameNode = member(n, "name");
      const attrName = nameNode ? (nameNode as AstNode & { name?: string }).name : undefined;
      if (!attrName || typeof attrName !== "string") return;
      if (EXCLUDED_BOOL_ATTRS.has(attrName) || BOOL_PREFIX_RE.test(attrName)) return;

      const valueNode = member(n, "value");
      let isBoolLiteral = false;
      if (valueNode === undefined) {
        isBoolLiteral = true; // shorthand `<Foo disabled />` implies `{true}`
      } else if (valueNode.type === "JSXExpressionContainer") {
        const expr = member(valueNode, "expression");
        if (expr?.type === "Literal" && typeof (expr as AstNode & { value: unknown }).value === "boolean") {
          isBoolLiteral = true;
        }
      }
      if (isBoolLiteral) {
        hits.push({
          ...(locStart(n) ?? { line: 1 }),
          message: `Boolean prop "${attrName}" doesn't follow isX/hasX/shouldX naming — boolean props are isX, hasX, shouldX, never just X.`,
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 45. npm-audit
// The ONE check in this entire rule set with a network dependency: it shells
// out to `npm audit`, which queries npm's remote advisory database. There is
// no way to determine vulnerability status from the lockfile alone without
// either a live registry query (this) or a bundled offline vulnerability
// database — neither of which exists elsewhere in this architecture. Any
// non-vulnerability failure (no network, registry down, no npm on PATH) is
// swallowed and treated as "nothing to report" rather than a false positive.
// ---------------------------------------------------------------------------

interface NpmAuditJson {
  metadata?: { vulnerabilities?: Record<string, number> };
}

/**
 * Pure interpretation of `npm audit --json` output — split out from the
 * `execFileSync` call so it's unit-testable with a canned payload instead of
 * requiring a live registry query (the one network dependency in this whole
 * checker set; nothing else needs mocking to exercise this logic).
 */
export function interpretNpmAuditOutput(stdout: string | undefined): CheckHit[] {
  if (!stdout) return [];
  let parsed: NpmAuditJson;
  try {
    parsed = JSON.parse(stdout);
  } catch {
    return [];
  }
  const vulns = parsed.metadata?.vulnerabilities;
  const highCrit = (vulns?.high ?? 0) + (vulns?.critical ?? 0);
  if (highCrit > 0) {
    return [
      {
        line: 1,
        message: `npm audit found ${highCrit} high/critical vulnerabilit${highCrit === 1 ? "y" : "ies"} — resolve before deploy.`,
      },
    ];
  }
  return [];
}

export const npmAuditChecker: RepoChecker = {
  ruleId: "security/supply-chain/npm-audit-must-be-clean-of-high-critical-before-deploy",
  kind: "repo",
  async check(ctx) {
    const hasLock = ctx.allFiles.some((f) => /(^|[\\/])package-lock\.json$/.test(f));
    if (!hasLock) return [];

    // STATIC / OFFLINE: never shell out to `npm audit` at check time — that
    // makes a live network call to the registry, which violates the pure
    // static-analysis contract (no network/AI/exec during a check). Instead we
    // consume a *pre-generated* audit report committed/produced in CI
    // (`npm audit --json > <file>`). If no such artifact exists, stay silent —
    // the CI pipeline is responsible for generating it before this runs.
    const auditArtifact = ctx.allFiles.find((f) =>
      /(^|[\\/])(npm-audit\.json|\.npm-audit\.json|audit-report\.json)$/i.test(f),
    );
    if (!auditArtifact) return [];

    let stdout: string | undefined;
    try {
      stdout = await readFile(auditArtifact, "utf8");
    } catch {
      return [];
    }
    return interpretNpmAuditOutput(stdout).map((hit) => ({ ...hit, path: auditArtifact }));
  },
};
