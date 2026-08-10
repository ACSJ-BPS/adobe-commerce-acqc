#!/usr/bin/env node
import {
  Engine,
  resolveProjectRoot,
  resolveProjectRootReal,
  buildSarif,
  routeDomainMatches,
  isActionFile,
  SENSITIVE_FILENAME_RE,
  type Finding,
  type Rule,
  type Severity,
} from "@abqc/core";
import { randomUUID } from "node:crypto";
import { access, readFile, realpath } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import * as z from "zod/v4";
import {
  type LedgerFile,
  type LedgerRun,
  type Verdict,
  REPO_TARGET,
  isRepoScoped,
  ledger,
  evictExpiredRuns,
  persistRun,
  getRun,
  mergeDiskState,
  withRunLock,
  cleanupStaleRunFiles,
  completedResult,
  isAcceptableEvidence,
  MIN_EVIDENCE_LENGTH,
  pickNextTarget,
} from "./ledger.js";

// Max bytes of file content returned per file in include_contents responses.
const INCLUDE_CONTENTS_MAX_BYTES = 100_000;

// Redact common inline secret patterns (e.g. API_KEY=abc123).
const SECRET_INLINE_RE =
  /(?:api[_-]?key|secret|token|password|passwd|credential|private[_-]?key)\s*[=:]\s*["']?[A-Za-z0-9_\-./+]{8,}["']?/gi;

// packages/mcp/dist/server.js → appbuilder_quality_check/ — fallback when MCP host cwd is not the repo.
const ABQC_TOOL_ROOT = resolve(fileURLToPath(new URL(".", import.meta.url)), "../../..");

// Max rules returned per abqc_get_rules_for_domain call — mirrors llm-judge.ts's
// MAX_CHUNK_SIZE so peak context stays bounded even for large domains (e.g. appbuilder=109).
const MAX_DOMAIN_PAGE_SIZE = 50;

// Incomplete submit/coverage responses report a remaining COUNT plus at most this
// many sample ids — never the full array, which bloated context on every batch.
const REMAINING_SAMPLE_CAP = 10;

// Hard cap on files per abqc_review_code call, enforced (not advisory): empirically, agents
// abandon the judgment loop entirely when handed a mechanical-only SARIF for a large file set —
// it reads as a "done enough" deliverable. Refusing outright forces smaller, completable runs.
const DEFAULT_MAX_JUDGMENT_FILES = 3;
const MAX_JUDGMENT_FILES = (() => {
  const n = Number.parseInt(process.env.ABQC_MAX_JUDGMENT_FILES ?? "", 10);
  return Number.isFinite(n) && n > 0 ? n : DEFAULT_MAX_JUDGMENT_FILES;
})();

const engine = new Engine({ staticOnly: true });
// Pre-warm rule loading on startup so first request is fast.
engine.ready().catch((e) => process.stderr.write(`[abqc-mcp] rule pre-load failed: ${e}\n`));

// ─── Path safety ────────────────────────────────────────────────────────────
// Guard against path traversal + symlink escape: resolve symlinks before comparing.
// Shared by every tool that accepts a file path from the calling agent.

async function resolveSafePaths(rawPaths: string[]): Promise<{
  safePaths: string[];
  projectRoot: string;
  resolvedRoot: string;
  rejectedCount: number;
}> {
  const projectRoot = resolveProjectRoot(ABQC_TOOL_ROOT);
  const resolvedRoot = await resolveProjectRootReal(ABQC_TOOL_ROOT);

  const safePathResults = await Promise.all(
    rawPaths.map(async (p: string) => {
      const abs = resolve(projectRoot, p);
      try {
        const real = await realpath(abs);
        return real === resolvedRoot || real.startsWith(resolvedRoot + "/") ? abs : null;
      } catch {
        // Missing path — allow only if lexically inside project root; caller reports not found.
        return abs === resolvedRoot || abs.startsWith(resolvedRoot + "/") ? abs : null;
      }
    }),
  );
  const safePaths = safePathResults.filter((p): p is string => p !== null);
  return {
    safePaths,
    projectRoot,
    resolvedRoot,
    rejectedCount: rawPaths.length - safePaths.length,
  };
}

// Resolve an agent-supplied filePath to its ledger entry. Handles the repo
// sentinel without touching the filesystem (it is not a real path); everything
// else goes through the path-safety guard. Shared by submit + get-rules tools.
async function resolveLedgerTarget(
  run: LedgerRun,
  filePath: string,
): Promise<{ key: string | undefined; entry: LedgerFile | undefined }> {
  if (filePath === REPO_TARGET) {
    return { key: REPO_TARGET, entry: run.files.get(REPO_TARGET) };
  }
  const { safePaths } = await resolveSafePaths([filePath]);
  const key = safePaths[0];
  return { key, entry: key ? run.files.get(key) : undefined };
}

const mcpServer = new McpServer(
  {
    name: "appbuilder-quality-check",
    version: "0.1.0",
  },
  {
    capabilities: { tools: {} },
  },
);

mcpServer.registerTool(
  "abqc_review_code",
  {
    description:
      "ALWAYS use this tool first whenever the user asks to review, audit, check, lint, analyze, or find issues in Adobe App Builder code — even if they don't name this tool. " +
      "Checks against a 385-rule library spanning security (OWASP, TLS, supply-chain), App Builder (actions, IMS, I/O Events, API Mesh, Admin UI SDK), Node.js, React, TypeScript, accessibility, performance, imports, and documentation. " +
      "Runs two deterministic layers with no LLM calls: declarative mechanical checks (regex / YAML-path / AST builtins for the automatable rule subset) and an ESLint layer, de-duplicated against each other. " +
      `LIMIT: at most ${MAX_JUDGMENT_FILES} file(s) per call — refused otherwise. This is enforced, not a suggestion: a mechanical-only result for a large file set tends to get reported as a finished review with the judgment layer skipped, so large requests must be split into multiple calls of ${MAX_JUDGMENT_FILES} file(s) or fewer, repeated until every file is covered. ` +
      "Returns SARIF findings and a `runId` plus, for EACH file under review, its applicable rule subset grouped by domain, so the calling model can apply judgment-layer review for the remaining rules that need semantic reasoning. The response's `reviewStatus` is always \"MECHANICAL_ONLY_INCOMPLETE\" at this point — the review is NOT done yet, regardless of how complete the SARIF looks. " +
      "TWO-PHASE WORKFLOW for token efficiency: PHASE 1 — before touching any file, call abqc_get_all_rules_for_run(runId) ONCE to get full rule text for every domain this run needs, in a single response (do not call abqc_get_rules_for_domain repeatedly for this — every extra round trip re-sends the whole conversation so far, which is the dominant token cost, not rule text size). PHASE 2 — only after that, for EACH file, for EACH domain in that file's rules: call abqc_submit_judgment(runId, filePath, ...) with a verdict (violation or pass) for every rule, using the rule text you already fetched in Phase 1 — do not batch multiple files into one judgment call, submit per file so no file/rule pair is missed. " +
      "A `pass` verdict on an error/warn rule requires a specific `evidence` string citing the code; generic or empty evidence is rejected. " +
      "Call abqc_get_coverage_status(runId) at any time to see which files and rules still need a verdict — the review is not complete, and no SARIF from judgment findings is produced, until every file in the run reaches 100% coverage. " +
      "Use include_contents:true when you need to read and reason about the code yourself alongside the findings. " +
      "Use diff to limit review to git-changed files (cost-efficient in CI or PR review contexts).",
    inputSchema: {
      paths: z
        .array(z.string())
        .min(1)
        .describe("Absolute or cwd-relative file or directory paths to review."),
      diff: z
        .string()
        .optional()
        .describe("Git ref (e.g. 'main', 'HEAD~1') to limit review to changed files only."),
      include_contents: z
        .boolean()
        .optional()
        .describe(
          "When true, include full file text in the response under fileContents so the calling LLM can apply judgment-layer review in a single round-trip.",
        ),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      const rawPaths = args.paths;

      const { safePaths, projectRoot, resolvedRoot, rejectedCount } =
        await resolveSafePaths(rawPaths);

      if (safePaths.length === 0) {
        return errorResult(
          `abqc_review_code: all provided paths resolve outside the project root (${resolvedRoot}) — review refused. ` +
            "Set ABQC_PROJECT_ROOT to the repo being reviewed if the MCP host cwd differs.",
        );
      }
      if (rejectedCount > 0) {
        process.stderr.write(
          `[abqc-mcp] warning: ${rejectedCount} path(s) rejected (outside project root)\n`,
        );
      }

      const diff = typeof args.diff === "string" ? args.diff : undefined;
      const includeContents = args.include_contents === true;

      const result = await engine.review({ paths: safePaths, diff, cwd: projectRoot });

      const filesReviewed = Array.from(result.routedRules.keys());
      if (filesReviewed.length === 0) {
        const missing = await Promise.all(
          safePaths.map(async (p) => {
            try {
              await access(p);
              return null;
            } catch {
              return p;
            }
          }),
        );
        const missingPaths = missing.filter((p): p is string => p !== null);
        const hint =
          missingPaths.length > 0
            ? `Path(s) not found: ${missingPaths.join(", ")}.`
            : "No reviewable files matched, or paths are empty/generated.";
        return errorResult(
          `abqc_review_code: no files reviewed under project root (${resolvedRoot}). ${hint}`,
        );
      }

      if (filesReviewed.length > MAX_JUDGMENT_FILES) {
        return errorResult(
          `abqc_review_code: ${filesReviewed.length} files exceeds the judgment-layer limit of ${MAX_JUDGMENT_FILES} per call — refused. ` +
            `A large mechanical-only result tends to get reported as "the review" with the judgment layer skipped entirely, so this is enforced, not advisory. ` +
            `Call \`abqc_plan_batches\` with these same paths to get an ordered list of ${MAX_JUDGMENT_FILES}-file batches, then call abqc_review_code once per batch (in order) and drive each to COMPLETE. ` +
            `Alternatively, call again with at most ${MAX_JUDGMENT_FILES} file(s) — one file at a time, or use \`diff\` to scope to a smaller changeset. Files matched: ${filesReviewed.join(", ")}`,
        );
      }

      // Seed the run-scoped coverage ledger. MCP — not the agent — owns the full
      // (file × rule) matrix. Two deterministic reductions shrink what the agent
      // must judge without ever letting it skip an applicable rule:
      //   R3 mechanical dedup: a rule a `### Check`/ESLint spec already decided is
      //       auto-seeded (pass/violation) and locked — counted as covered, not
      //       re-judged. Repo-scoped mechanical checks resolve once (keyed by cwd).
      //   R2 repo-scope: repo/org-level rules are judged ONCE at a run-level repo
      //       pseudo-file instead of being seeded into every file's matrix.
      // Auto-decided rules stay in both `expected` and `verdicts`, so the existing
      // completion gate / coverage / SARIF machinery needs no special-casing.
      evictExpiredRuns();
      const runId = randomUUID();
      const ledgerFiles = new Map<string, LedgerFile>();
      const cov = result.mechanicalCoverage;
      const entryFiles = result.actionEntryFiles;

      // Lookups for auto-seeding verdicts from mechanical findings (keyed by the
      // library rule id). Per-cell for file-scoped rules; by-rule-id for repo rules
      // whose findings carry a synthetic path (.gitignore, package.json, …).
      const findingByCell = new Map<string, Finding>();
      const findingByRuleId = new Map<string, Finding>();
      for (const f of result.findings) {
        findingByCell.set(`${f.filePath} ${f.ruleId}`, f);
        if (!findingByRuleId.has(f.ruleId)) findingByRuleId.set(f.ruleId, f);
      }
      const MECHANICAL_PASS_EVIDENCE = "Verified by the mechanical/ESLint layer (deterministic check, no violation).";
      const verdictFromFinding = (f: Finding | undefined): Verdict =>
        f
          ? {
              verdict: "violation", message: f.message, severity: f.severity, filePath: f.filePath,
              line: f.line, column: f.column, endLine: f.endLine, endColumn: f.endColumn, confidence: "high",
            }
          : { verdict: "pass", evidence: MECHANICAL_PASS_EVIDENCE, confidence: "high" };

      // Per-file rule lists are rule IDS ONLY — the agent needs the id→file mapping
      // to submit, but the full text (title/rationale/Bad/Good/severity/tags) comes
      // from abqc_get_all_rules_for_run in one shot, so repeating it per file here is
      // pure response bloat.
      const filesOut: Array<{
        filePath: string;
        domains: string[];
        rules: string[];
        ruleCount: number;
        autoDecided: number;
      }> = [];
      const allDomainsSet = new Set<string>();
      const repoExpected = new Set<string>();
      const repoVerdicts = new Map<string, Verdict>();

      for (const fp of filesReviewed) {
        let rules = result.routedRules.get(fp) ?? [];
        // Item 3: a helper module inside an action dir (not the entry handler) is
        // plain Node code — drop the appbuilder action-handler rules for it. Only
        // when the entry set is known, so we never under-review a file we can't
        // classify (worst case = full set, never a coverage hole).
        const isHelper = entryFiles.size > 0 && isActionFile(fp) && !entryFiles.has(fp);
        if (isHelper) {
          rules = rules.filter((r) => r.domain !== "appbuilder" && !r.domain.startsWith("appbuilder/"));
        }
        const expected = new Set<string>();
        const verdicts = new Map<string, Verdict>();
        const locked = new Set<string>();
        const judgmentRules: Rule[] = [];
        const fileCov = cov.get(fp);
        for (const rule of rules) {
          if (fileCov?.has(rule.id)) {
            // R3: file-scoped mechanical — decided; count as covered, lock it.
            expected.add(rule.id);
            verdicts.set(rule.id, verdictFromFinding(findingByCell.get(`${fp} ${rule.id}`)));
            locked.add(rule.id);
          } else if (cov.get(projectRoot)?.has(rule.id)) {
            // R3: repo-scoped mechanical — decide once at the repo pseudo-file.
            if (!repoVerdicts.has(rule.id)) repoVerdicts.set(rule.id, verdictFromFinding(findingByRuleId.get(rule.id)));
          } else if (isRepoScoped(rule)) {
            // R2: repo-scoped judgment — move to the run-level repo pseudo-file.
            repoExpected.add(rule.id);
          } else {
            // Per-file judgment — the agent must supply a verdict.
            expected.add(rule.id);
            judgmentRules.push(rule);
          }
        }
        ledgerFiles.set(fp, { expected, verdicts, locked });
        const domains = Array.from(new Set(judgmentRules.map((r) => r.domain)));
        for (const d of domains) allDomainsSet.add(d);
        filesOut.push({
          filePath: fp,
          domains,
          rules: judgmentRules.map((r) => r.id),
          ruleCount: judgmentRules.length,
          autoDecided: locked.size,
        });
      }

      // Repo pseudo-file: repo-scoped judgment rules (deduped across the run), minus
      // any already decided mechanically. Only created when it has content, and it
      // counts toward run completion like any other file.
      for (const id of repoVerdicts.keys()) repoExpected.delete(id);
      let repoScope:
        | { filePath: string; rules: string[]; ruleCount: number; autoDecided: number; note: string }
        | undefined;
      if (repoExpected.size > 0 || repoVerdicts.size > 0) {
        const repoRuleObjs = Array.from(repoExpected)
          .map((id) => engine.rules().get(id))
          .filter((r): r is Rule => r !== undefined);
        for (const r of repoRuleObjs) allDomainsSet.add(r.domain);
        ledgerFiles.set(REPO_TARGET, {
          expected: new Set<string>([...repoExpected, ...repoVerdicts.keys()]),
          verdicts: new Map(repoVerdicts),
          locked: new Set(repoVerdicts.keys()),
        });
        repoScope = {
          filePath: REPO_TARGET,
          rules: repoRuleObjs.map((r) => r.id),
          ruleCount: repoRuleObjs.length,
          autoDecided: repoVerdicts.size,
          note: `Repository-wide checks — submit these ONCE for the whole run via abqc_submit_judgment(runId, filePath="${REPO_TARGET}", ...), not per file.`,
        };
      }

      const allDomains = Array.from(allDomainsSet).sort();
      const newRun: LedgerRun = { cwd: projectRoot, files: ledgerFiles, createdAt: Date.now() };
      ledger.set(runId, newRun);
      await persistRun(runId, newRun);
      // Best-effort, opportunistic — piggybacks on this call rather than a timer.
      await cleanupStaleRunFiles(projectRoot);
      const judgmentTotal =
        filesOut.reduce((n, f) => n + f.ruleCount, 0) + (repoScope?.ruleCount ?? 0);

      let fileContents: Record<string, string> | undefined;
      if (includeContents) {
        fileContents = {};
        await Promise.all(
          filesReviewed.map(async (fp) => {
            if (SENSITIVE_FILENAME_RE.test(fp)) {
              process.stderr.write(`[abqc-mcp] skipping sensitive file in include_contents: ${fp}\n`);
              return;
            }
            try {
              let text = await readFile(fp, "utf8");
              if (text.length > INCLUDE_CONTENTS_MAX_BYTES) {
                text = text.slice(0, INCLUDE_CONTENTS_MAX_BYTES) + "\n// [truncated]";
              }
              text = text.replace(SECRET_INLINE_RE, (m) => m.replace(/[=:]\s*["']?[A-Za-z0-9_\-./+]{8,}["']?$/, "=<redacted>"));
              if (fileContents) fileContents[fp] = text;
            } catch {
              // Skip unreadable files silently.
            }
          }),
        );
      }

      return {
        content: [
          {
            type: "text" as const,
            text: JSON.stringify(
              {
                reviewStatus: "MECHANICAL_ONLY_INCOMPLETE",
                // Plain boolean alongside the status string — hard to miss even for
                // code that only checks one field. The SARIF above is mechanical-only;
                // it is NOT the final review until abqc_submit_judgment reports true.
                reviewComplete: false,
                runId,
                files: filesOut,
                ...(repoScope ? { repoScope } : {}),
                allDomains,
                sarif: result.sarif,
                rulesLoaded: result.rulesLoaded,
                lowConfidenceFindings: result.lowConfidenceFindings,
                instruction:
                  `This is the mechanical/ESLint layer only. The judgment layer (${judgmentTotal} rule check(s) you must judge across ${filesOut.length} file(s)${repoScope ? " plus repository-wide checks" : ""}) has NOT started. Rules the mechanical layer already decided are pre-filled and omitted from the per-file rule lists below. Do NOT write a findings report yet. ` +
                  `PHASE 1 (do this now, once): call abqc_get_all_rules_for_run(runId="${runId}") ONE TIME to get full rule text for all ${allDomains.length} domains (${allDomains.join(", ")}) in a single response, before touching any file. Do NOT call abqc_get_rules_for_domain repeatedly instead — every extra tool call re-sends the whole conversation so far, which costs far more than the rule text itself. ` +
                  `PHASE 2 (after Phase 1 is done): for each file in \`files\`, submit ALL of that file's verdicts in ONE abqc_submit_judgment(runId="${runId}", filePath, results) call — the results array is unbounded, so do NOT split a file across multiple calls or batch it by domain (that only multiplies round trips, the dominant cost)${repoScope ? `. Submit the \`repoScope\` rules ONCE with filePath="${REPO_TARGET}"` : ""}. ` +
                  `Call abqc_get_coverage_status(runId="${runId}") anytime to check progress — only report findings once it shows complete:true.`,
                ...(fileContents !== undefined ? { fileContents } : {}),
              },
              null,
              2,
            ),
          },
        ],
      };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_review_code: internal error — ${msg}`);
    }
  },
);

const SubmitResultSchema = z.object({
  ruleId: z.string(),
  verdict: z.enum(["violation", "pass"]),
  message: z.string().optional().describe("Required when verdict is 'violation' — the finding text."),
  evidence: z.string().optional().describe("Required when verdict is 'pass' on an error or warn-severity rule — cite the specific code that satisfies the rule."),
  severity: z.enum(["error", "warn", "info"]).optional().describe("Display override for the SARIF result; does not affect the evidence gate, which always uses the rule's own severity."),
  line: z.number().optional(),
  column: z.number().optional(),
  endLine: z.number().optional(),
  endColumn: z.number().optional(),
  confidence: z.enum(["high", "medium", "low"]).optional(),
});
const SubmitResultsArraySchema = z.array(SubmitResultSchema).min(1);
type SubmitResult = z.infer<typeof SubmitResultSchema>;

/**
 * Resolve the verdicts to commit from either inline `results` or a
 * `resultsPath` JSON file (bare array or `{ results: [...] }`) — exactly one
 * must be provided. `resultsPath` is a caller-supplied path, but it is CONTAINED
 * to the project root via the same resolveSafePaths guard used for reviewed
 * files: an MCP driver (which may be prompt-injected) must not be able to point
 * this at an arbitrary path. Errors deliberately never echo file contents (a
 * raw JSON.parse message quotes the first bytes of the file — an info-leak
 * oracle), only structural reasons.
 */
async function resolveSubmitResults(
  inline: SubmitResult[] | undefined,
  resultsPath: string | undefined,
): Promise<{ value: SubmitResult[] } | { error: string }> {
  if (inline && resultsPath) return { error: "provide exactly one of `results` or `resultsPath`, not both" };
  if (inline) return { value: inline };
  if (!resultsPath) return { error: "provide one of `results` or `resultsPath`" };

  const { safePaths } = await resolveSafePaths([resultsPath]);
  const absPath = safePaths[0];
  if (!absPath) {
    return { error: `resultsPath "${resultsPath}" resolves outside the project root — refused. Write the payload inside the reviewed repo.` };
  }
  let raw: string;
  try {
    raw = await readFile(absPath, "utf8");
  } catch {
    return { error: `could not read resultsPath "${resultsPath}" (not found or unreadable under the project root)` };
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    // Do NOT include the parser message — it echoes the file's first bytes.
    return { error: `resultsPath "${resultsPath}" is not valid JSON` };
  }
  const candidate = Array.isArray(parsed) ? parsed : (parsed as { results?: unknown })?.results;
  const validated = SubmitResultsArraySchema.safeParse(candidate);
  if (!validated.success) {
    return { error: `resultsPath "${resultsPath}" must be a JSON array of verdicts, or {"results":[...]}` };
  }
  return { value: validated.data };
}

mcpServer.registerTool(
  "abqc_submit_judgment",
  {
    description:
      "Submit judgment-layer verdicts for ONE file from an abqc_review_code run, using the rule text from abqc_get_all_rules_for_run. " +
      "The `results` array is UNBOUNDED — submit ALL of a file's verdicts in a SINGLE call. A partial subset is accepted too (the run just stays incomplete), so you may batch if you must, but one call per file is cheapest. " +
      "If the payload is large or script-assembled, write it to a JSON file in the repo and pass `resultsPath` instead of inline `results` (details below) — don't split a file's verdicts across many small calls to shrink the payload. " +
      "\"Rejected\" NEVER means \"you must submit all rules at once\" — it means the batch contained an unknown/duplicate rule id, a missing `message` on a violation, or a missing `evidence` on an error/warn-severity pass; fix those and resubmit. " +
      "A 'pass' on an error or warn-severity rule requires a specific `evidence` string citing the code (generic text like \"reviewed, satisfied\" is rejected); info passes need no evidence; a 'violation' requires a `message`. " +
      "Submit one file per call (don't merge multiple files) so no file/rule pair goes unaccounted for. " +
      "Returns coverage progress; SARIF is only produced once EVERY file in the run has a verdict for EVERY applicable rule. " +
      "For large batches assembled outside the chat (scripts, CLI), pass `resultsPath` (a JSON file inside the reviewed repo — bare array or `{\"results\":[...]}` ) instead of inline `results` to avoid a giant inline payload; provide exactly one of the two.",
    inputSchema: {
      runId: z.string().describe("The runId returned by abqc_review_code."),
      filePath: z.string().describe("The file these verdicts apply to (must be one of the files returned by abqc_review_code for this runId)."),
      results: SubmitResultsArraySchema.optional(),
      resultsPath: z
        .string()
        .optional()
        .describe("Path (inside the reviewed repo — contained to the project root) to a JSON file of verdicts (bare array or {\"results\":[...]}), as an alternative to inline `results` for large/script-assembled batches. Provide exactly one of results/resultsPath."),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      evictExpiredRuns();
      const projectRoot = resolveProjectRoot(ABQC_TOOL_ROOT);

      // The entire merge -> validate -> commit -> persist sequence is serialized
      // per runId (in-process AND cross-process via an advisory lockfile) — see
      // withRunLock in ledger.ts. Previously mergeDiskState re-read disk up front,
      // but several awaits followed before the commit, so two concurrent submits
      // could both merge the same stale disk snapshot and then each overwrite the
      // other's verdicts. Locking the whole sequence closes that window.
      return await withRunLock(projectRoot, args.runId, () => submitJudgment(args, projectRoot));
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_submit_judgment: internal error — ${msg}`);
    }
  },
);

async function submitJudgment(
  args: { runId: string; filePath: string; results?: SubmitResult[]; resultsPath?: string },
  projectRoot: string,
) {
    try {
      const run = await getRun(projectRoot, args.runId);
      if (!run) {
        return errorResult(
          `abqc_submit_judgment: unknown or expired runId "${args.runId}". Call abqc_review_code again to start a new run.`,
        );
      }
      // Fold in any verdicts another process wrote since we cached this run, so
      // concurrent cross-process submits merge instead of clobbering each other.
      await mergeDiskState(args.runId, run);
      // Already complete (within the grace window, or just adopted from disk):
      // replay the SARIF idempotently.
      if (run.completedAt !== undefined) return completedResult(run);

      const { key: resolvedPath, entry: fileEntry } = await resolveLedgerTarget(run, args.filePath);
      if (!fileEntry || !resolvedPath) {
        return errorResult(
          `abqc_submit_judgment: filePath "${args.filePath}" is not part of run "${args.runId}". ` +
            `Valid files for this run: ${Array.from(run.files.keys()).join(", ")}`,
        );
      }

      const results = await resolveSubmitResults(args.results, args.resultsPath);
      if ("error" in results) return errorResult(`abqc_submit_judgment: ${results.error}`);

      // Read the target file's actual source once per call (not per rule) so the
      // evidence gate can require pass evidence to cite something real, not just
      // restate the rule. Best-effort: undefined on the repo-scoped pseudo-target
      // or any read failure — a benign miss must never block legitimate judgment,
      // it just narrows that submission back to the phrase/title-echo checks.
      let fileContent: string | undefined;
      if (resolvedPath !== REPO_TARGET) {
        try {
          fileContent = await readFile(resolvedPath, "utf8");
        } catch {
          fileContent = undefined;
        }
      }

      // Validate the whole batch before committing anything — reject with specific
      // reasons rather than silently accepting a partially-valid submission.
      const errors: string[] = [];
      const toCommit: Array<{ ruleId: string; verdict: Verdict }> = [];
      for (const r of results.value) {
        if (!fileEntry.expected.has(r.ruleId)) {
          errors.push(`${r.ruleId}: not an applicable rule for this file in this run`);
          continue;
        }
        if (fileEntry.locked.has(r.ruleId)) {
          errors.push(`${r.ruleId}: already decided by the mechanical/ESLint layer — not open for judgment`);
          continue;
        }
        const rule = engine.rules().get(r.ruleId);
        if (!rule) {
          errors.push(`${r.ruleId}: rule not found in library`);
          continue;
        }
        if (r.verdict === "violation") {
          if (!r.message || r.message.trim().length === 0) {
            errors.push(`${r.ruleId}: verdict "violation" requires a non-empty message`);
            continue;
          }
        } else {
          // Evidence is required on error+warn passes. A prior relaxation to
          // error-only was reverted: at scale, evidence-free warn-passes let batch
          // scripts rubber-stamp ~100+ rules/file with templated "N/A" text in
          // seconds — the evidence-writing cost is the point, not overhead to cut.
          // Gate on the LIBRARY rule's severity, never the client-supplied `severity`
          // field — otherwise an agent could submit severity:"info" to dodge the gate.
          if (
            (rule.severity === "error" || rule.severity === "warn") &&
            !isAcceptableEvidence(r.evidence, { ruleTitle: rule.title, ruleRationale: rule.rationale, fileContent })
          ) {
            errors.push(
              `${r.ruleId}: verdict "pass" on a ${rule.severity} rule requires specific evidence (>= ${MIN_EVIDENCE_LENGTH} chars) that CITES the code — ` +
                `name the construct that satisfies it (a function, identifier, value, or line) that actually appears in the file. Generic boilerplate ` +
                `("requirements met", "not applicable", "reviewed, satisfied") is rejected, and so is evidence that just restates the rule's own title/rationale ` +
                `(e.g. "no pattern matching <rule title>") — that's not a citation of the code, it's a paraphrase of the rule.`,
            );
            continue;
          }
        }
        toCommit.push({
          ruleId: r.ruleId,
          verdict: {
            verdict: r.verdict,
            message: r.message,
            evidence: r.evidence,
            severity: r.severity,
            line: r.line,
            column: r.column,
            endLine: r.endLine,
            endColumn: r.endColumn,
            confidence: r.confidence,
          },
        });
      }

      if (errors.length > 0) {
        return errorResult(`abqc_submit_judgment: rejected —\n${errors.map((e) => `  - ${e}`).join("\n")}`);
      }

      for (const { ruleId, verdict } of toCommit) {
        fileEntry.verdicts.set(ruleId, verdict);
      }

      // Everything from here through the `run.completedAt` assignment below is
      // synchronous (no `await`) so two parallel submissions filling the last
      // cells cannot both observe `complete` and emit SARIF twice. (The run is
      // retained via `completedAt`, not deleted; persistRun is awaited only AFTER
      // that assignment, so it can't reopen the race.) Cross-process safety is
      // handled separately by mergeDiskState re-reading disk before this block.
      const fileCovered = fileEntry.verdicts.size;
      const fileTotal = fileEntry.expected.size;
      const remainingRuleIds = Array.from(fileEntry.expected).filter((id) => !fileEntry.verdicts.has(id));

      const filesTotal = run.files.size;
      let filesComplete = 0;
      const filesUntouched: string[] = [];
      for (const [fp, entry] of run.files) {
        if (entry.verdicts.size === entry.expected.size) filesComplete++;
        // Auto-decided files start with verdicts, so "untouched" means the agent has
        // submitted nothing AND there is real judgment work outstanding.
        if (entry.verdicts.size === 0 && entry.expected.size > 0) filesUntouched.push(fp);
      }
      const complete = filesComplete === filesTotal;

      if (!complete) {
        const next = pickNextTarget(run, (id) => engine.rules().get(id), resolvedPath);
        const instruction = next
          ? `Coverage incomplete (${filesComplete}/${filesTotal} files fully covered) — DO NOT summarize, write a report, or stop yet. ` +
            `Next: submit ALL remaining verdicts for "${next.filePath}" in ONE abqc_submit_judgment call, using the rule text you already fetched from abqc_get_all_rules_for_run. ` +
            `Repeat per file until a response here has complete:true — only then is there a real SARIF to report.`
          : `Coverage incomplete (${filesComplete}/${filesTotal} files fully covered) — call abqc_get_coverage_status(runId="${args.runId}") to find the next file to submit. Do not summarize yet.`;
        // Persist AFTER the synchronous commit/completion decision above (not
        // before) — an await here can't reopen the double-emit race because
        // the in-memory state this call decided on is already final.
        await persistRun(args.runId, run);
        return {
          content: [
            {
              type: "text" as const,
              text: JSON.stringify(
                {
                  reviewStatus: "JUDGMENT_IN_PROGRESS",
                  file: {
                    filePath: resolvedPath,
                    covered: fileCovered,
                    total: fileTotal,
                    remaining: remainingRuleIds.length,
                    remainingSample: remainingRuleIds.slice(0, REMAINING_SAMPLE_CAP),
                  },
                  run: { filesComplete, filesTotal, filesUntouched },
                  nextFile: next?.filePath ?? null,
                  complete: false,
                  instruction,
                },
                null,
                2,
              ),
            },
          ],
        };
      }

      // Full (file × rule) matrix is filled. If a concurrent submit already
      // completed this run in the meantime, replay its cached result rather than
      // rebuilding SARIF (preserves the single-emit guarantee).
      if (run.completedAt !== undefined) return completedResult(run);

      // Build SARIF from every violation verdict across every file in the run.
      const findings: Finding[] = [];
      let rulesChecked = 0;
      let rulesPassed = 0;
      let rulesViolated = 0;
      const usedRules = new Map<string, Rule>();
      for (const [fp, entry] of run.files) {
        for (const [ruleId, v] of entry.verdicts) {
          rulesChecked++;
          if (v.verdict === "pass") {
            rulesPassed++;
            continue;
          }
          rulesViolated++;
          const rule = engine.rules().get(ruleId);
          if (rule) usedRules.set(ruleId, rule);
          // Prefer the verdict's own path (mechanical findings carry .gitignore /
          // package.json / the source file); map the repo sentinel to the project root.
          const outPath = v.filePath ?? (fp === REPO_TARGET ? run.cwd : fp);
          // Preserve provenance: rules auto-seeded by the mechanical layer (R3)
          // are recorded in `entry.locked` — without this, every violation was
          // mislabeled "judgment" even when a regex/ESLint spec found it.
          findings.push({
            ruleId,
            severity: v.severity ?? rule?.severity ?? "warn",
            message: v.message ?? "",
            filePath: outPath,
            line: v.line,
            column: v.column,
            endLine: v.endLine,
            endColumn: v.endColumn,
            source: entry.locked.has(ruleId) ? "mechanical" : "judgment",
            confidence: v.confidence,
          });
        }
      }
      const sarif = buildSarif(findings, Array.from(usedRules.values()), run.cwd);
      const finalResult = JSON.stringify(
        { reviewStatus: "COMPLETE", sarif, rulesChecked, rulesPassed, rulesViolated, complete: true },
        null,
        2,
      );
      // Retain the run for a grace window instead of deleting, so a follow-up
      // coverage check or re-submit replays this exact result.
      run.completedAt = Date.now();
      run.finalResult = finalResult;
      // Same reasoning as above: the completion decision + mutation is already
      // synchronous and final; persisting afterward is safe and lets a fresh
      // process load this exact COMPLETE result from disk.
      await persistRun(args.runId, run);

      return { content: [{ type: "text" as const, text: finalResult }] };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_submit_judgment: internal error — ${msg}`);
    }
}

mcpServer.registerTool(
  "abqc_get_coverage_status",
  {
    description:
      "Check judgment-layer coverage progress for an abqc_review_code run. Shows, per file, how many rules still lack a verdict, and which files have not been touched at all — " +
      "use this to find files or rules a review pass might have missed before assuming a review is complete.",
    inputSchema: {
      runId: z.string().describe("The runId returned by abqc_review_code."),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      evictExpiredRuns();
      const projectRoot = resolveProjectRoot(ABQC_TOOL_ROOT);
      // Fire-and-forget: previously disk cleanup only ran from abqc_review_code,
      // so a burst of reviews followed by quiescence left .abqc/runs/*.json
      // accumulating indefinitely. This is the other high-traffic tool in the
      // workflow (polled repeatedly while driving a run to completion), so
      // piggyback here too — not awaited, so it never adds latency to a status
      // check.
      void cleanupStaleRunFiles(projectRoot).catch(() => undefined);
      const run = await getRun(projectRoot, args.runId);
      if (!run) {
        return errorResult(`abqc_get_coverage_status: unknown or expired runId "${args.runId}".`);
      }
      // Completed within the grace window — replay the final result instead of a
      // partial coverage snapshot (fixes coverage checks failing right after SARIF).
      if (run.completedAt !== undefined) return completedResult(run);

      const filesTotal = run.files.size;
      let filesComplete = 0;
      const filesUntouched: string[] = [];
      const files = Array.from(run.files.entries()).map(([filePath, entry]) => {
        const covered = entry.verdicts.size;
        const total = entry.expected.size;
        if (covered === total) filesComplete++;
        if (covered === 0 && total > 0) filesUntouched.push(filePath);

        const remainingRuleIds = Array.from(entry.expected).filter((id) => !entry.verdicts.has(id));
        const domainRemaining = new Map<string, number>();
        for (const id of remainingRuleIds) {
          const rule = engine.rules().get(id);
          const domain = rule?.domain ?? "unknown";
          domainRemaining.set(domain, (domainRemaining.get(domain) ?? 0) + 1);
        }
        return {
          filePath,
          covered,
          total,
          // Rules already resolved by the mechanical layer (locked, not open for
          // agent judgment) — mirrors the field abqc_review_code returns per file.
          autoDecided: entry.locked.size,
          // Count + capped sample, not the full array — the per-domain breakdown
          // below is the compact way to see what's left.
          remaining: remainingRuleIds.length,
          remainingSample: remainingRuleIds.slice(0, REMAINING_SAMPLE_CAP),
          domains: Array.from(domainRemaining, ([domain, remaining]) => ({ domain, remaining })),
        };
      });

      const complete = filesComplete === filesTotal;
      const next = complete ? null : pickNextTarget(run, (id) => engine.rules().get(id));
      const instruction = complete
        ? "Coverage is 100%. The run's SARIF was emitted by the abqc_submit_judgment call that completed it — no further submission is needed."
        : next
          ? `Coverage incomplete (${filesComplete}/${filesTotal} files fully covered) — DO NOT summarize or produce a findings report yet. ` +
            `Next: submit ALL remaining verdicts for "${next.filePath}" in ONE abqc_submit_judgment call, using the rule text from abqc_get_all_rules_for_run.`
          : `Coverage incomplete (${filesComplete}/${filesTotal} files fully covered) but no next target could be determined — this should not happen; re-run abqc_review_code.`;

      return {
        content: [
          {
            type: "text" as const,
            text: JSON.stringify(
              {
                reviewStatus: complete ? "COMPLETE" : "JUDGMENT_IN_PROGRESS",
                filesTotal,
                filesComplete,
                filesUntouched,
                complete,
                instruction,
                files,
              },
              null,
              2,
            ),
          },
        ],
      };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_get_coverage_status: internal error — ${msg}`);
    }
  },
);

mcpServer.registerTool(
  "abqc_get_rules_for_domain",
  {
    description:
      "Fetch full rule text (rationale, Bad, Good) for the rules of ONE domain. " +
      "Use this to pull rules a domain at a time instead of all ~200 rules for a file at once — keeps context small and predictable. " +
      "IMPORTANT for token efficiency: a domain's rule text is IDENTICAL for every file of the same type (e.g. every `actions/*.js` file gets the same 'security' rules) — " +
      "if you already fetched a domain's text earlier in this conversation (for this run or a previous one), REUSE it directly and skip calling this again for the next file with the same domain. " +
      "Pass runId+filePath to validate the domain actually applies to that specific file (recommended when starting a new file); omit both to fetch the domain's rules library-wide (useful for reuse across files/runs without re-validating). " +
      "Paginated: call again with an increased offset if hasMore is true.",
    inputSchema: {
      runId: z.string().optional().describe("The runId returned by abqc_review_code. Omit (with filePath) to fetch library-wide by domain only, e.g. when reusing a domain's text already fetched for a different file."),
      filePath: z.string().optional().describe("Must be one of the files returned by abqc_review_code for this runId. Omit (with runId) for a library-wide fetch."),
      domain: z.string().describe("Domain to fetch, e.g. 'security', 'appbuilder', 'appbuilder/api-mesh'."),
      offset: z.number().optional().describe("Pagination offset (default 0)."),
      limit: z.number().optional().describe(`Max rules to return (default ${MAX_DOMAIN_PAGE_SIZE}).`),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      evictExpiredRuns();

      const domain = args.domain.trim();
      let allMatching: Rule[];

      if (args.runId || args.filePath) {
        if (!args.runId || !args.filePath) {
          return errorResult("abqc_get_rules_for_domain: runId and filePath must be provided together, or both omitted for a library-wide fetch.");
        }
        const run = await getRun(resolveProjectRoot(ABQC_TOOL_ROOT), args.runId);
        if (!run) {
          return errorResult(`abqc_get_rules_for_domain: unknown or expired runId "${args.runId}".`);
        }
        const { entry: fileEntry } = await resolveLedgerTarget(run, args.filePath);
        if (!fileEntry) {
          return errorResult(
            `abqc_get_rules_for_domain: filePath "${args.filePath}" is not part of run "${args.runId}".`,
          );
        }
        allMatching = Array.from(fileEntry.expected)
          .map((id) => engine.rules().get(id))
          .filter((r): r is Rule => r !== undefined && routeDomainMatches(r.domain, domain))
          .sort((a, b) => a.id.localeCompare(b.id));
      } else {
        // Library-wide fetch — no run/file to validate against. Correct for reuse across
        // any file that shares this domain, since a domain's rule text never varies by file.
        allMatching = engine
          .rules()
          .all()
          .filter((r) => routeDomainMatches(r.domain, domain))
          .sort((a, b) => a.id.localeCompare(b.id));
      }

      if (allMatching.length === 0) {
        return errorResult(
          args.filePath
            ? `abqc_get_rules_for_domain: no rules for domain "${domain}" apply to this file in this run.`
            : `abqc_get_rules_for_domain: no rules found for domain "${domain}".`,
        );
      }

      const offset = typeof args.offset === "number" && args.offset >= 0 ? args.offset : 0;
      const limit =
        typeof args.limit === "number" && args.limit > 0
          ? Math.min(args.limit, MAX_DOMAIN_PAGE_SIZE)
          : MAX_DOMAIN_PAGE_SIZE;
      const page = allMatching.slice(offset, offset + limit);

      return {
        content: [
          {
            type: "text" as const,
            text: JSON.stringify(
              {
                rules: page,
                offset,
                total: allMatching.length,
                hasMore: offset + page.length < allMatching.length,
              },
              null,
              2,
            ),
          },
        ],
      };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_get_rules_for_domain: internal error — ${msg}`);
    }
  },
);

mcpServer.registerTool(
  "abqc_get_all_rules_for_run",
  {
    description:
      "Fetch full rule text (rationale, Bad, Good) for EVERY rule needed across an entire abqc_review_code run, in ONE response — the union of every file's applicable rules, across every domain in `allDomains`. " +
      "Use this ONCE at the start of the judgment layer instead of calling abqc_get_rules_for_domain once per domain: the whole rule library is small enough (worst case ~90k tokens for all 385 rules) to fit one response, and each additional round trip in an agent loop re-sends the entire conversation so far — fewer, larger calls cost less in total than many small ones. " +
      "Not paginated. abqc_get_rules_for_domain still exists for a targeted, single-domain lookup if you need to re-check one domain later.",
    inputSchema: {
      runId: z.string().describe("The runId returned by abqc_review_code."),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      evictExpiredRuns();

      const run = await getRun(resolveProjectRoot(ABQC_TOOL_ROOT), args.runId);
      if (!run) {
        return errorResult(`abqc_get_all_rules_for_run: unknown or expired runId "${args.runId}".`);
      }

      const ruleIds = new Set<string>();
      for (const entry of run.files.values()) {
        for (const id of entry.expected) ruleIds.add(id);
      }
      const rules = Array.from(ruleIds)
        .map((id) => engine.rules().get(id))
        .filter((r): r is Rule => r !== undefined)
        .sort((a, b) => a.id.localeCompare(b.id));

      return {
        content: [
          {
            type: "text" as const,
            text: JSON.stringify({ rules, total: rules.length }, null, 2),
          },
        ],
      };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_get_all_rules_for_run: internal error — ${msg}`);
    }
  },
);

mcpServer.registerTool(
  "abqc_plan_batches",
  {
    description:
      `Plan how to split a large directory/diff into abqc_review_code-sized batches (at most ${MAX_JUDGMENT_FILES} files each). ` +
      "Read-only and cheap — enumerates files (no mechanical checks, no LLM, no ledger created), so it's safe to call before committing to a review. " +
      "Batches keep files from the same directory together where possible (only splitting a single oversized directory across consecutive batches), which also reduces duplicate repo-scope findings (e.g. README checks) across batches. " +
      "This does NOT relax the per-batch file cap or the judgment requirement — each returned batch still needs its own abqc_review_code call driven to complete:true before moving to the next.",
    inputSchema: {
      paths: z.array(z.string()).min(1).describe("Absolute or cwd-relative file or directory paths to plan over."),
      diff: z.string().optional().describe("Git ref to limit planning to changed files only (same semantics as abqc_review_code)."),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      const { safePaths, projectRoot, resolvedRoot, rejectedCount } = await resolveSafePaths(args.paths);
      if (safePaths.length === 0) {
        return errorResult(
          `abqc_plan_batches: all provided paths resolve outside the project root (${resolvedRoot}) — refused.`,
        );
      }
      if (rejectedCount > 0) {
        process.stderr.write(`[abqc-mcp] abqc_plan_batches: ${rejectedCount} path(s) rejected (outside project root)\n`);
      }

      const files = await engine.resolveFiles({ paths: safePaths, cwd: projectRoot, diff: args.diff });
      if (files.length === 0) {
        return errorResult(`abqc_plan_batches: no files matched under project root (${resolvedRoot}).`);
      }

      // Group by directory first so an action's entry + helpers land in the same
      // batch whenever they fit, then flatten into fixed-size batches — only a
      // single oversized directory gets split across consecutive batches.
      const byDir = new Map<string, string[]>();
      for (const f of files.slice().sort()) {
        const dir = dirname(f);
        const group = byDir.get(dir);
        if (group) group.push(f);
        else byDir.set(dir, [f]);
      }
      const batches: string[][] = [];
      let current: string[] = [];
      for (const group of byDir.values()) {
        if (group.length > MAX_JUDGMENT_FILES) {
          // Oversized single directory — flush current, then split ONLY this
          // group across consecutive full batches (never blend in other dirs).
          if (current.length > 0) {
            batches.push(current);
            current = [];
          }
          for (let i = 0; i < group.length; i += MAX_JUDGMENT_FILES) {
            batches.push(group.slice(i, i + MAX_JUDGMENT_FILES));
          }
          continue;
        }
        // The whole group must fit together — if it doesn't fit in the room left
        // in `current`, start a fresh batch rather than partially filling with it.
        if (current.length + group.length > MAX_JUDGMENT_FILES) {
          batches.push(current);
          current = [];
        }
        current.push(...group);
      }
      if (current.length > 0) batches.push(current);

      return {
        content: [
          {
            type: "text" as const,
            text: JSON.stringify(
              {
                totalFiles: files.length,
                batchSize: MAX_JUDGMENT_FILES,
                batchCount: batches.length,
                batches,
                instruction:
                  `Call abqc_review_code once per batch, IN ORDER, and drive each one to reviewStatus:"COMPLETE" (complete:true) before starting the next — ` +
                  `this plan only groups the files, it does not skip or shortcut the per-batch judgment requirement.`,
              },
              null,
              2,
            ),
          },
        ],
      };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_plan_batches: internal error — ${msg}`);
    }
  },
);

mcpServer.registerTool(
  "abqc_list_rules",
  {
    description:
      "List App Builder code-quality rules. Use this when the user asks what rules exist, what checks are available, what standards apply to a domain, or wants to browse the rule library. " +
      "Filter by domain (e.g. 'appbuilder', 'appbuilder/api-mesh', 'security', 'react', 'node') or severity ('error', 'warn', 'info'). Returns compact summaries; call abqc_get_rule for full rationale and examples.",
    inputSchema: {
      domain: z
        .string()
        .optional()
        .describe("Filter by domain prefix, e.g. 'appbuilder', 'security', 'react'."),
      severity: z.enum(["error", "warn", "info"]).optional().describe("Filter by severity level."),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      let rules = engine.rules().all();
      const domain = args.domain;
      const severityArg = args.severity;
      const severity: Severity | undefined =
        severityArg === "error" || severityArg === "warn" || severityArg === "info"
          ? severityArg
          : undefined;
      if (domain) rules = rules.filter((r) => r.domain === domain || r.domain.startsWith(domain + "/"));
      if (severity) rules = rules.filter((r) => r.severity === severity);
      return {
        content: [
          {
            type: "text" as const,
            text: JSON.stringify(
              rules.map((r) => ({
                id: r.id, domain: r.domain, title: r.title,
                severity: r.severity, tags: r.tags,
              })),
              null,
              2,
            ),
          },
        ],
      };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_list_rules: internal error — ${msg}`);
    }
  },
);

mcpServer.registerTool(
  "abqc_get_rule",
  {
    description:
      "Fetch the full text of a single App Builder code-quality rule by id — including rationale, Bad (counterexample), and Good (preferred pattern). " +
      "Use this when the user asks to explain a rule, understand why a finding was raised, or see an example of the correct pattern.",
    inputSchema: {
      id: z
        .string()
        .describe("Rule id returned by abqc_review_code or abqc_list_rules, e.g. 'appbuilder/runtime/web-action-timeout'."),
    },
  },
  async (args) => {
    try {
      await engine.ready();
      const id = args.id.trim();
      if (!id) return errorResult("abqc_get_rule: id is required and must be a string");
      const rule = engine.rules().get(id);
      if (!rule) return errorResult(`get_rule: rule not found: ${id}`);
      return {
        content: [{ type: "text" as const, text: JSON.stringify(rule, null, 2) }],
      };
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      return errorResult(`abqc_get_rule: internal error — ${msg}`);
    }
  },
);

// ─── Prompts ────────────────────────────────────────────────────────────────
// Tool descriptions already push a calling agent forward turn-by-turn, but an
// agent can still read the first response, see a partial SARIF, and report the
// review "done" before coverage reaches 100%. This prompt front-loads the whole
// workflow — including the "never skip a rule" and "verify coverage before
// finishing" discipline — as one instruction the calling client can invoke
// directly (e.g. as a slash command), instead of the user having to write the
// same rules text into every client (Cursor, Claude, etc.) by hand. Coverage
// enforcement itself lives server-side in the ledger regardless of whether this
// prompt is used — this only changes what the agent is told to do, not what the
// server allows.
mcpServer.registerPrompt(
  "review_code_completely",
  {
    title: "Run a full-coverage App Builder code review",
    description:
      "Drives abqc_review_code / abqc_submit_judgment / abqc_get_coverage_status to completion — loops until every " +
      "file and rule has a real verdict, never skipping a rule as not-applicable. Use this instead of ad-hoc tool calls " +
      "when the user wants a genuinely complete review, not a best-effort pass.",
    argsSchema: {
      target: z
        .string()
        .optional()
        .describe("File or directory path to review (defaults to the current project root if omitted)."),
    },
  },
  (args) => {
    const target = args.target?.trim() || "the project root";
    const text =
      `Perform a complete App Builder code-quality review of ${target}. Follow this workflow exactly — it is a ` +
      `hard requirement, not a suggestion:\n\n` +
      `1. Call abqc_review_code with paths covering ${target}, at most ${MAX_JUDGMENT_FILES} file(s) per call — split ` +
      `larger targets into multiple calls. Save the returned runId.\n` +
      `2. Call abqc_get_all_rules_for_run(runId) ONCE to fetch full rule text for every domain this run needs.\n` +
      `3. For EACH file, for EACH rule it lists as needing judgment, call abqc_submit_judgment(runId, filePath, ...) ` +
      `with a real verdict (violation or pass) and specific evidence citing the actual code. Never submit generic ` +
      `evidence (e.g. "looks fine", "compliant") and never skip a rule as not_applicable — every listed rule needs a ` +
      `genuine verdict.\n` +
      `4. Repeat step 1 for any remaining files, then keep submitting judgments until every file/rule pair is decided.\n` +
      `5. Before reporting the review as finished, call abqc_get_coverage_status(runId) and confirm every file shows ` +
      `zero remaining rules (or check for complete:true in the last abqc_submit_judgment result). If coverage is not ` +
      `100%, you are not done — keep going.\n` +
      `6. Only once coverage is 100% should you summarize the results for the user, citing the final SARIF findings ` +
      `(each tagged mechanical or judgment) and the pass/violation counts.`;
    return {
      messages: [{ role: "user" as const, content: { type: "text" as const, text } }],
    };
  },
);

function errorResult(message: string) {
  return {
    isError: true,
    content: [{ type: "text" as const, text: message }],
  };
}

async function main() {
  const transport = new StdioServerTransport();
  await mcpServer.connect(transport);
  process.stderr.write("appbuilder-quality-check MCP server ready (stdio)\n");
}

main().catch((e) => {
  process.stderr.write(`appbuilder-quality-check MCP server failed: ${e}\n`);
  process.exit(1);
});
