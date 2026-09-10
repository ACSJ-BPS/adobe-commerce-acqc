import { join } from "node:path";
import { readFile, readdir } from "node:fs/promises";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isActionFile } from "@abqc/core";

// ---------------------------------------------------------------------------
// Shared helpers (process / documentation P1 doc-artifact domain)
//
// P1 doc-artifact proxy — every checker in this file validates that a
// specific documentation artifact EXISTS and has plausible content, not that
// the underlying process/decision it represents is actually correct. A
// fabricated file with placeholder text that happens to contain the right
// keywords can still pass, and a real decision made entirely outside git
// (a Slack thread, a verbal sign-off) always fails until someone writes it
// down here. That's the intended trade-off for a P1 mechanical proxy: it
// forces the paper trail to exist, it can't verify the paper trail is true.
// ---------------------------------------------------------------------------

async function readSafe(p: string): Promise<string | null> {
  try {
    return await readFile(p, "utf8");
  } catch {
    return null;
  }
}

function stripTitleHeading(content: string): string {
  return content.replace(/^\s*#[^\n]*\n?/, "").trim();
}

interface DocArtifactConfig {
  ruleId: string;
  relPath: string;
  /** Content must match this pattern (checked against the full raw file, title included). */
  contentPattern?: RegExp;
  /** Minimum length of the body (title heading stripped) to count as non-trivial. Default 50. */
  minLength?: number;
  /** Short human description used in generated messages, e.g. "data residency decisions". */
  description: string;
}

function makeDocArtifactChecker(cfg: DocArtifactConfig): RepoChecker {
  const minLength = cfg.minLength ?? 50;
  return {
    ruleId: cfg.ruleId,
    kind: "repo",
    async check(ctx) {
      const abs = join(ctx.projectRoot, cfg.relPath);
      const content = await readSafe(abs);
      if (content === null) {
        return [{ line: 1, path: abs, message: `${cfg.relPath} is missing — document ${cfg.description}.` }];
      }
      const body = stripTitleHeading(content);
      if (body.length <= minLength) {
        return [
          {
            line: 1,
            path: abs,
            message: `${cfg.relPath} exists but is too sparse (placeholder-only) to document ${cfg.description}.`,
          },
        ];
      }
      if (cfg.contentPattern && !cfg.contentPattern.test(content)) {
        return [
          {
            line: 1,
            path: abs,
            message: `${cfg.relPath} exists but is missing expected content — document ${cfg.description}.`,
          },
        ];
      }
      return [];
    },
  };
}

// ---------------------------------------------------------------------------
// 21. data-residency-doc
// ---------------------------------------------------------------------------

export const dataResidencyDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/compliance/data-residency-confirmed-for-all-pii-and-regulated-data",
  relPath: "docs/compliance/data-residency.md",
  contentPattern: /region|encryption|retention/i,
  description: "data residency decisions (region, encryption, retention) for all PII/regulated data",
});

// ---------------------------------------------------------------------------
// 22. dpia-doc
// ---------------------------------------------------------------------------

export const dpiaDocChecker: RepoChecker = {
  ruleId: "process/compliance/dpia-filed-when-handling-eu-pii-or-regulated-data",
  kind: "repo",
  async check(ctx) {
    const candidates = ctx.allFiles.filter((f) => /docs\/compliance\/dpia-.*\.md$/i.test(f.replace(/\\/g, "/")));
    let target: string;
    let content: string | null;
    if (candidates.length > 0) {
      target = candidates[0]!;
      content = await readSafe(target);
    } else {
      target = join(ctx.projectRoot, "docs/compliance/dpia.md");
      content = await readSafe(target);
    }
    if (content === null) {
      return [
        {
          line: 1,
          path: target,
          message:
            "No DPIA document found (docs/compliance/dpia-*.md or docs/compliance/dpia.md) — file one before handling EU PII/regulated data.",
        },
      ];
    }
    const body = stripTitleHeading(content);
    if (body.length <= 50 || !/status|signoff|dpo/i.test(content)) {
      return [
        {
          line: 1,
          path: target,
          message: "DPIA document exists but is missing expected content (status/sign-off/DPO) or is too sparse.",
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 23. kpi-doc
// ---------------------------------------------------------------------------

export const kpiDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/discovery/document-business-kpis-and-seo-goals-before-kickoff",
  relPath: "docs/discovery/kpis.md",
  contentPattern: /kpi|target|baseline/i,
  description: "business KPIs and SEO goals with targets/baselines before kickoff",
});

// ---------------------------------------------------------------------------
// 24. missing-rum
// ---------------------------------------------------------------------------

const SPA_FILE_RE = /\.(jsx|tsx|js|ts)$/i;

function isSpaSourceFile(p: string): boolean {
  const norm = p.replace(/\\/g, "/");
  return SPA_FILE_RE.test(norm) && !/\/actions\//.test(norm);
}

/**
 * Repo-scoped rather than file-scoped: the underlying check is "is there
 * ZERO web-vitals usage anywhere in the SPA", which only makes sense as a
 * single repo-wide verdict — a per-file check would need to fire on every
 * SPA file that doesn't itself import web-vitals, which is nearly all of
 * them even in a compliant app (only the RUM bootstrap file needs the
 * import).
 */
export const missingRumChecker: RepoChecker = {
  ruleId: "process/discovery/configure-real-user-monitoring-before-ga",
  kind: "repo",
  async check(ctx) {
    const spaFiles = ctx.allFiles.filter(isSpaSourceFile);
    if (spaFiles.length === 0) return [];
    for (const f of spaFiles) {
      const content = await readSafe(f);
      if (content && /from\s+['"]web-vitals['"]/.test(content)) return [];
    }
    return [
      {
        line: 1,
        message: "No SPA source file imports 'web-vitals' — configure Real User Monitoring (Core Web Vitals beacon) before GA.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 25. rbac-doc
// ---------------------------------------------------------------------------

export const rbacDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/governance/rbac-roles-and-access-tiers-signed-off-by-security-before-ga",
  relPath: "docs/governance/rbac.md",
  contentPattern: /role|scope|security.?sign.?off/i,
  description: "RBAC roles/access tiers with a security sign-off before GA",
});

// ---------------------------------------------------------------------------
// 26. genai-policy-doc
// ---------------------------------------------------------------------------

export const genaiPolicyDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/governance/genai-usage-policy-no-proprietary-code-or-customer-data-submitte",
  relPath: "docs/governance/genai-policy.md",
  description: "the GenAI usage policy (approved tools, allowed/prohibited data)",
});

// ---------------------------------------------------------------------------
// 27. vendor-list-doc
// ---------------------------------------------------------------------------

/**
 * Staleness check is simplified per scope: `last_reviewed` date extraction
 * from a markdown table is best-effort (looks for a YYYY-MM-DD date near the
 * label on the same line); if no parseable date is found the staleness
 * comparison is skipped and only the field's presence is validated — actual
 * review cadences are often recorded as "2026-Q1" rather than an exact date,
 * which this regex deliberately doesn't try to parse.
 */
export const vendorListDocChecker: RepoChecker = {
  ruleId: "process/governance/vendor-and-saas-dependency-list-reviewed-by-procurement-and-secu",
  kind: "repo",
  async check(ctx) {
    const relPath = "docs/governance/vendors.md";
    const abs = join(ctx.projectRoot, relPath);
    const content = await readSafe(abs);
    if (content === null) {
      return [
        {
          line: 1,
          path: abs,
          message: `${relPath} is missing — list every vendor/SaaS dependency for procurement + security review.`,
        },
      ];
    }
    const body = stripTitleHeading(content);
    const tableLines = content.split("\n").filter((l) => /\|.*\|.*\|/.test(l));
    if (body.length <= 50 || tableLines.length < 2) {
      return [
        {
          line: 1,
          path: abs,
          message: `${relPath} exists but has no markdown table of vendors — too sparse to be a real artifact.`,
        },
      ];
    }
    if (!/last_reviewed/i.test(content)) {
      return [
        {
          line: 1,
          path: abs,
          message: `${relPath} table is missing a 'last_reviewed' column — add it so staleness can be tracked.`,
        },
      ];
    }
    const dateMatch =
      /last_reviewed[^\n]*?(\d{4}-\d{2}-\d{2})/i.exec(content) ?? /(\d{4}-\d{2}-\d{2})[^\n]*?last_reviewed/i.exec(content);
    if (dateMatch?.[1]) {
      const reviewed = new Date(dateMatch[1]);
      if (!Number.isNaN(reviewed.getTime())) {
        const ageDays = (Date.now() - reviewed.getTime()) / (1000 * 60 * 60 * 24);
        if (ageDays > 365) {
          return [
            {
              line: 1,
              path: abs,
              message: `${relPath} last_reviewed date (${dateMatch[1]}) is more than a year old — refresh the procurement/security review.`,
            },
          ];
        }
      }
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 28. runbook-doc
// ---------------------------------------------------------------------------

export const runbookDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/operations/incident-response-runbook-with-named-on-call-rotation",
  relPath: "docs/operations/runbook.md",
  contentPattern: /pagerduty|opsgenie|on-?call/i,
  description: "the incident-response runbook with a named on-call rotation",
});

// ---------------------------------------------------------------------------
// 29. review-log-doc
// ---------------------------------------------------------------------------

/** Staleness/go-live-date correlation intentionally skipped per scope — existence + content only. */
export const reviewLogDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/operations/post-go-live-monitoring-dashboards-reviewed-in-week-1-and-month-",
  relPath: "docs/operations/review-log.md",
  description: "the week-1/month-1 post-go-live monitoring dashboard review",
});

// ---------------------------------------------------------------------------
// 30. a11y-signoff-doc
// ---------------------------------------------------------------------------

export const a11ySignoffDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/quality/screen-reader-qa-sign-off-nvda-jaws-voiceover-before-ga",
  relPath: "docs/quality/a11y-signoff.md",
  contentPattern: /nvda|jaws|voiceover|signoff/i,
  description: "the screen-reader QA sign-off (NVDA/JAWS/VoiceOver)",
});

// ---------------------------------------------------------------------------
// 31. training-docs-dir
// ---------------------------------------------------------------------------

export const trainingDocsDirChecker: RepoChecker = {
  ruleId: "process/quality/author-and-admin-training-docs-delivered-before-content-team-han",
  kind: "repo",
  async check(ctx) {
    const dir = join(ctx.projectRoot, "docs/training");
    try {
      const entries = await readdir(dir);
      if (entries.length === 0) {
        return [
          {
            line: 1,
            path: dir,
            message: "docs/training/ exists but is empty — deliver author/admin training docs before content-team handoff.",
          },
        ];
      }
      return [];
    } catch {
      return [
        {
          line: 1,
          path: dir,
          message: "docs/training/ directory is missing — deliver author/admin training docs before content-team handoff.",
        },
      ];
    }
  },
};

// ---------------------------------------------------------------------------
// 32. load-test-doc
// ---------------------------------------------------------------------------

export const loadTestDocChecker: RepoChecker = makeDocArtifactChecker({
  ruleId: "process/quality/pre-go-live-load-test-against-expected-peak-traffic",
  relPath: "docs/quality/load-test-report.md",
  contentPattern: /rps|latency|p95|signoff/i,
  description: "the pre-go-live load test report (RPS/latency/p95/sign-off)",
});

// ---------------------------------------------------------------------------
// 33. data-access-doc
//   Canonical implementation for BOTH:
//     - security/supply-chain/document-what-data-your-app-accesses-or-stores-review-ready-data [info]
//     - documentation/project/docs-data-access-md-single-source-of-truth-for-what-data-the-app [warn]
//   (per CONVERSION-LEDGER.md #227/#384 — the documentation/project rule is
//   marked MERGED into the security/supply-chain one; implemented once here,
//   registered under both rule ids during integration.)
// ---------------------------------------------------------------------------

export const dataAccessDocChecker: RepoChecker = {
  ruleId: "security/supply-chain/document-what-data-your-app-accesses-or-stores-review-ready-data",
  kind: "repo",
  async check(ctx) {
    const relPath = "docs/data-access.md";
    const abs = join(ctx.projectRoot, relPath);
    const content = await readSafe(abs);
    if (content === null) {
      return [
        {
          line: 1,
          path: abs,
          message: `${relPath} is missing — document every data source the app reads/stores, retention, and third parties, in a markdown table.`,
        },
      ];
    }
    const tableLines = content.split("\n").filter((l) => /\|.*\|.*\|/.test(l));
    if (tableLines.length < 2) {
      return [
        {
          line: 1,
          path: abs,
          message: `${relPath} exists but has no markdown table — add a table of data sources/retention/third parties.`,
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 34. changelog-format
// ---------------------------------------------------------------------------

/** Per scope: only validates format when CHANGELOG.md exists — a missing CHANGELOG.md itself is not flagged by this checker. */
export const changelogFormatChecker: RepoChecker = {
  ruleId: "documentation/releases/maintain-changelog-md-every-release-entry-semver",
  kind: "repo",
  async check(ctx) {
    const abs = join(ctx.projectRoot, "CHANGELOG.md");
    const content = await readSafe(abs);
    if (content === null) return [];
    if (!/^##\s*\[\d+\.\d+\.\d+\]/m.test(content)) {
      return [
        {
          line: 1,
          path: abs,
          message:
            "CHANGELOG.md exists but has no '## [x.y.z]' version heading — follow Keep a Changelog format with a real release entry.",
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 35. action-no-schema
// ---------------------------------------------------------------------------

export const actionNoSchemaChecker: FileChecker = {
  ruleId: "documentation/releases/document-action-contracts-schema-for-inputs-outputs-errors",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    if (/zod|joi|ajv/i.test(ctx.content)) return [];
    const hasSchemaExport =
      /\bexport\s+(const|class|function)\s+\w*(InputSchema|OutputSchema|Schema)\w*/i.test(ctx.content) ||
      /module\.exports\s*=\s*\{[^}]*\b(InputSchema|OutputSchema|Schema)\b/i.test(ctx.content);
    if (hasSchemaExport) return [];
    return [
      {
        line: 1,
        message:
          "Action has no schema library (zod/joi/ajv) and no exported Schema/InputSchema/OutputSchema — document machine-readable input/output/error contracts.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 36. api-doc-missing-sample
// ---------------------------------------------------------------------------

export const apiDocMissingSampleChecker: RepoChecker = {
  ruleId: "documentation/releases/sample-request-response-in-every-api-doc-copy-pasteable",
  kind: "repo",
  async check(ctx) {
    const apiDocs = ctx.allFiles.filter((f) => /docs\/api\/[^/]+\.md$/i.test(f.replace(/\\/g, "/")));
    if (apiDocs.length === 0) return [];
    const hits: CheckHit[] = [];
    for (const doc of apiDocs) {
      const content = await readSafe(doc);
      if (content === null) continue;
      if (!/```(json|bash|curl)/i.test(content)) {
        hits.push({
          line: 1,
          path: doc,
          message: "API doc has no fenced json/bash/curl code sample — add a copy-pasteable request/response example.",
        });
      }
    }
    return hits;
  },
};
