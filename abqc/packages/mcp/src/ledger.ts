import { randomUUID } from "node:crypto";
import { mkdir, readdir, readFile, rename, stat, unlink, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import type { Rule, Severity } from "@abqc/core";

// ─── Coverage ledger ────────────────────────────────────────────────────────
// Ephemeral, in-memory, run-scoped. A "run" is one abqc_review_code call across
// potentially many files. MCP — not the agent — owns the (file × rule) matrix:
// abqc_review_code seeds every expected cell up front, so a file or rule the
// agent never submits for is still visible as uncovered rather than invisible.

export interface Verdict {
  verdict: "violation" | "pass";
  message?: string;
  evidence?: string;
  severity?: Severity;
  filePath?: string;
  line?: number;
  column?: number;
  endLine?: number;
  endColumn?: number;
  confidence?: "high" | "medium" | "low";
}

export interface LedgerFile {
  expected: Set<string>;
  verdicts: Map<string, Verdict>;
  // Rules auto-decided by the mechanical layer (pre-seeded into `verdicts`). They
  // count toward coverage but are NOT open for agent judgment — the agent cannot
  // overwrite a deterministic mechanical verdict.
  locked: Set<string>;
}

export interface LedgerRun {
  cwd: string;
  files: Map<string, LedgerFile>;
  createdAt: number;
  // Set once the run reaches 100% coverage. The entry is retained (not deleted)
  // for a grace window so coverage checks / re-reads still work right after SARIF,
  // and its cached COMPLETE payload is replayed idempotently.
  completedAt?: number;
  finalResult?: string;
}

// Coverage runs older than this are evicted lazily on next ledger access.
export const RUN_TTL_MS = 30 * 60 * 1000;

// After a run completes (SARIF emitted) the entry is kept this long instead of
// deleted immediately, so coverage/verification calls right after don't get
// "unknown or expired runId".
export const COMPLETED_GRACE_MS = 5 * 60 * 1000;

// A crashed/killed writer's temp file older than this is safe to reclaim — anything
// younger might belong to a write currently in flight in another process.
const STALE_TMP_MS = 60 * 1000;

// Evidence quality floor for `pass` verdicts on error/warn rules — best-effort only,
// cannot verify genuine reasoning, only raises the cost of pass-spamming.
export const MIN_EVIDENCE_LENGTH = 15;
const GENERIC_EVIDENCE_RE =
  /^(ok|okay|n\/?a|pass(es|ed)?|complian(t|ce)|looks?\s*good|fine|no\s*issues?|good|correct|valid)\.?$/i;
// Boilerplate vocabulary: filler + "requirements met / not applicable / reviewed,
// satisfied" style words. Length + the anchored regex above only catch evidence
// that is ENTIRELY one generic token; multi-word boilerplate ("rule requirements
// met or not applicable", 39 chars) slips through. So we also require the evidence
// to carry real content — at least 2 tokens that are NOT in this set — which forces
// a citation of the actual construct rather than a templated non-answer. Genuine
// reasoning ("handler awaits every fetch", "parseInt called with radix 10") clears
// this trivially; only filler-only strings are rejected.
const GENERIC_EVIDENCE_TOKENS = new Set<string>([
  "and", "the", "are", "was", "were", "not", "none", "all", "any", "for", "with",
  "met", "meet", "meets", "rule", "rules", "code", "this", "that", "these", "those",
  "issue", "issues", "okay", "fine", "good", "correct", "valid", "compliant",
  "compliance", "reviewed", "review", "looks", "look", "pass", "passed", "passes",
  "present", "expected", "properly", "correctly", "followed", "follows", "follow",
  "adhered", "adheres", "adhere", "here", "there", "requirement", "requirements",
  "satisfied", "satisfies", "satisfy", "applicable", "checked", "verified", "seems",
]);

// Confirmed real-world exploit phrasing: a batch script's judgeRule() fallback
// auto-passed ~70% of rules with templated strings like "Reviewed index.js — no
// pattern matching <rule title>" or "Supplemental pass for <id> — reviewed, no
// violation pattern found". These clear the generic-token check below because
// the interpolated filename/rule-id makes the string "substantive" by length,
// even though it cites nothing about the code. Denylist the template shells
// directly, on top of the vocabulary-overlap check that catches the same
// exploit even if the wording is varied.
const GAMING_PHRASE_RE =
  /no\s+pattern\s+matching|no\s+violation\s+pattern\s+found|supplemental\s+pass\s+for|reviewed,?\s+no\s+violation/i;

// License/copyright-header vocabulary: present in nearly every source file's
// header comment regardless of what the file actually does. A follow-up gaming
// attempt against the file-citation check below generated evidence like
// "coordinates Copyright in this actions helper" — "Copyright"/"Adobe" pass the
// structural check trivially because they appear in every file, not because
// they say anything about THIS file or THIS rule. Scoped to only the
// file-citation check (not merged into GENERIC_EVIDENCE_TOKENS) so evidence
// that genuinely references e.g. "the Adobe I/O SDK client" is unaffected
// elsewhere — only its standing as the file-citation is removed.
const BOILERPLATE_TOKENS = new Set<string>([
  "copyright", "copyrighted", "adobe", "license", "licensed", "licensing",
  "rights", "reserved", "spdx", "confidential", "proprietary", "permission",
  "permissions", "warranty", "warranties", "merchantability", "noninfringement",
  "fitness", "particular", "purpose", "author", "authors", "contributor",
  "contributors", "holder", "holders", "notice", "notices", "furnished",
  "substantial", "portions", "distributed", "governed", "terms", "agreement",
  "unauthorized", "prohibited", "trademark", "trademarks",
]);

function tokenize(text: string, minLen: number): string[] {
  return text
    .toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter((t) => t.length >= minLen && !GENERIC_EVIDENCE_TOKENS.has(t));
}

export interface EvidenceContext {
  ruleTitle: string;
  ruleRationale: string;
  // Actual source of the file the verdict applies to, when available — undefined
  // for the repo-scoped pseudo-target or when the file couldn't be read. In that
  // case the structural file-citation check below is skipped (a benign read
  // failure must never block legitimate judgment), and only the phrase/title-echo
  // checks apply.
  fileContent?: string;
}

export function isAcceptableEvidence(evidence: string | undefined, ctx?: EvidenceContext): boolean {
  if (!evidence) return false;
  const trimmed = evidence.trim();
  if (trimmed.length < MIN_EVIDENCE_LENGTH) return false;
  if (GENERIC_EVIDENCE_RE.test(trimmed)) return false;
  if (GAMING_PHRASE_RE.test(trimmed)) return false;
  const substantive = tokenize(trimmed, 3);
  if (substantive.length < 2) return false;

  if (ctx) {
    // Title-echo: if every substantive token in the evidence is also part of the
    // rule's own title/rationale, the evidence adds nothing beyond restating the
    // rule spec — that's exactly what "no pattern matching <title>" produces.
    const ruleVocabulary = new Set(tokenize(`${ctx.ruleTitle} ${ctx.ruleRationale}`, 3));
    if (substantive.every((t) => ruleVocabulary.has(t))) return false;

    // Structural file-citation: require at least one longer token that both
    // appears in the file's actual source AND isn't part of the rule's own
    // vocabulary — a real, code-specific reference the rule text itself
    // couldn't have supplied. Only enforced when file content is available.
    if (ctx.fileContent !== undefined) {
      const fileLower = ctx.fileContent.toLowerCase();
      const citesCode = tokenize(trimmed, 4).some(
        (t) => !ruleVocabulary.has(t) && !BOILERPLATE_TOKENS.has(t) && fileLower.includes(t),
      );
      if (!citesCode) return false;
    }
  }

  return true;
}

// Reserved ledger key for the run-level "repository" pseudo-file: repo/org-scoped
// rules are checked ONCE per run here rather than seeded into every file's matrix.
// The token cannot collide with an absolute file path, so it flows safely through
// the same filePath field the agent already passes back to submit/get-rules tools.
export const REPO_TARGET = "::repo::";

// domain/subTopic buckets that are entirely repo/org-level, not per-file. Verified
// against `rules list`: all of process/* and deployment/* (deployment is only ever
// routed to config YAML anyway), and the repo-doc subtopics (NOT documentation/code,
// which is per-file JSDoc). supply-chain is deliberately left per-file — it is a
// mixed file, and leaving a rule per-file is bloat, never a coverage hole.
export const REPO_SCOPED_SUBTOPICS = new Set<string>([
  "process/compliance", "process/discovery", "process/governance",
  "process/operations", "process/quality",
  "deployment/environments", "deployment/pipeline", "deployment/releases",
  "deployment/rollback", "deployment/secrets", "deployment/slos",
  "documentation/project", "documentation/releases",
]);

export function isRepoScoped(rule: Rule): boolean {
  return REPO_SCOPED_SUBTOPICS.has(`${rule.domain}/${rule.subTopic}`);
}

export const ledger = new Map<string, LedgerRun>();

export function evictExpiredRuns(): void {
  const now = Date.now();
  for (const [id, run] of ledger) {
    const stale =
      run.completedAt !== undefined
        ? now - run.completedAt > COMPLETED_GRACE_MS
        : now - run.createdAt > RUN_TTL_MS;
    if (stale) ledger.delete(id);
  }
}

// ─── Disk persistence ───────────────────────────────────────────────────────
// A run created in one MCP server process (e.g. Cursor's session) is invisible
// to a different process (e.g. a standalone script) with only the in-memory
// `ledger` — this bridges that gap. Runs are written to
// <projectRoot>/.abqc/runs/<runId>.json; `getRun` checks memory first, then
// disk. Concurrency model is last-write-MERGES: every submit re-reads the disk
// copy via `mergeDiskState` and unions in verdicts another process committed
// (verdicts are additive and decided-once), so concurrent cross-process submits
// to the same run no longer silently drop each other. A per-runId lock (see
// `withRunLock`) additionally serializes the merge→commit→persist sequence so
// two writers can't interleave inside that window (see P1-3 in the review).

const RUN_ID_RE = /^[0-9a-f-]{8,64}$/i;

interface SerializedLedgerRun {
  cwd: string;
  files: Array<[string, { expected: string[]; verdicts: Array<[string, Verdict]>; locked: string[] }]>;
  createdAt: number;
  completedAt?: number;
  finalResult?: string;
}

export function runsDir(projectRoot: string): string {
  return join(projectRoot, ".abqc", "runs");
}

/** Resolves runId to its on-disk path, or null if runId isn't UUID-shaped (never touch an arbitrary path). */
export function runFilePath(projectRoot: string, runId: string): string | null {
  if (!RUN_ID_RE.test(runId)) return null;
  return join(runsDir(projectRoot), `${runId}.json`);
}

function lockFilePath(projectRoot: string, runId: string): string | null {
  const base = runFilePath(projectRoot, runId);
  return base ? `${base}.lock` : null;
}

function serializeRun(run: LedgerRun): SerializedLedgerRun {
  return {
    cwd: run.cwd,
    createdAt: run.createdAt,
    completedAt: run.completedAt,
    finalResult: run.finalResult,
    files: Array.from(run.files.entries()).map(([fp, entry]) => [
      fp,
      {
        expected: Array.from(entry.expected),
        verdicts: Array.from(entry.verdicts.entries()),
        locked: Array.from(entry.locked),
      },
    ]),
  };
}

function deserializeRun(data: SerializedLedgerRun): LedgerRun {
  return {
    cwd: data.cwd,
    createdAt: data.createdAt,
    completedAt: data.completedAt,
    finalResult: data.finalResult,
    files: new Map(
      data.files.map(([fp, entry]) => [
        fp,
        { expected: new Set(entry.expected), verdicts: new Map(entry.verdicts), locked: new Set(entry.locked) },
      ]),
    ),
  };
}

/**
 * Best-effort: a persistence failure never blocks the in-memory response. The
 * temp filename includes a random id (not just the pid) — two concurrent
 * persists for the SAME run in the SAME process previously collided on
 * `${filePath}.${pid}.tmp` and could interleave writes into one file.
 */
export async function persistRun(runId: string, run: LedgerRun): Promise<void> {
  const filePath = runFilePath(run.cwd, runId);
  if (!filePath) return;
  try {
    await mkdir(dirname(filePath), { recursive: true });
    const tmpPath = `${filePath}.${process.pid}.${randomUUID()}.tmp`;
    await writeFile(tmpPath, JSON.stringify(serializeRun(run)), "utf8");
    await rename(tmpPath, filePath); // atomic swap — never leaves a torn read
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    process.stderr.write(`[abqc-mcp] failed to persist run ${runId}: ${msg}\n`);
  }
}

/** In-memory first; falls back to disk and hydrates the in-memory cache on a hit. */
export async function getRun(projectRootFallback: string, runId: string): Promise<LedgerRun | undefined> {
  const inMemory = ledger.get(runId);
  if (inMemory) return inMemory;
  const filePath = runFilePath(projectRootFallback, runId);
  if (!filePath) return undefined;
  try {
    const raw = await readFile(filePath, "utf8");
    const run = deserializeRun(JSON.parse(raw) as SerializedLedgerRun);
    ledger.set(runId, run);
    return run;
  } catch {
    return undefined;
  }
}

/**
 * Reconcile the in-memory run with the on-disk copy. `getRun` serves a cached
 * object without re-reading disk, so a long-lived process never sees verdicts
 * another process committed to the same run — that was the "one agent says
 * complete, the other says pending" data loss. Merging disk state in (verdicts
 * are additive and decided-once, so a union is correct) turns the write from
 * last-write-WINS into last-write-MERGES.
 */
export async function mergeDiskState(runId: string, run: LedgerRun): Promise<void> {
  const filePath = runFilePath(run.cwd, runId);
  if (!filePath) return;
  let disk: LedgerRun;
  try {
    disk = deserializeRun(JSON.parse(await readFile(filePath, "utf8")) as SerializedLedgerRun);
  } catch {
    return; // no disk copy yet, or unreadable — in-memory is authoritative
  }
  // Another process already finalized this run: adopt its completion so we replay
  // its SARIF instead of rebuilding (and re-emitting) our own.
  if (disk.completedAt !== undefined && run.completedAt === undefined) {
    run.completedAt = disk.completedAt;
    run.finalResult = disk.finalResult;
  }
  for (const [fp, diskEntry] of disk.files) {
    const mem = run.files.get(fp);
    if (!mem) {
      run.files.set(fp, diskEntry);
      continue;
    }
    for (const id of diskEntry.expected) mem.expected.add(id);
    for (const id of diskEntry.locked) mem.locked.add(id);
    // Keep our own verdict when a cell exists in both (both are valid decided-once
    // verdicts); only adopt cells this process hasn't decided.
    for (const [ruleId, v] of diskEntry.verdicts) {
      if (!mem.verdicts.has(ruleId)) mem.verdicts.set(ruleId, v);
    }
  }
}

/**
 * Serializes access to a single runId's merge→validate→commit→persist sequence,
 * both within this process (an in-process async mutex keyed by runId) and across
 * processes (a best-effort exclusive lockfile at `<run>.json.lock`, created with
 * the `wx` flag so a second process's create fails instead of clobbering). This
 * closes the window where two concurrent submits for the same run could both
 * read a stale disk state and then each overwrite the other's verdicts (see
 * P1-3 in the architectural review) — previously `mergeDiskState` re-read disk
 * up front, but `resolveLedgerTarget`/`resolveSubmitResults` still awaited
 * *after* that read, before the synchronous commit block.
 *
 * The cross-process lock is advisory and best-effort: if it can't be acquired
 * within the retry budget (another process is wedged or crashed while holding
 * it), we proceed anyway rather than hang a tool call forever — `mergeDiskState`
 * still unions in whatever the other writer completed.
 */
const inProcessLocks = new Map<string, Promise<unknown>>();

async function acquireDiskLock(projectRoot: string, runId: string): Promise<(() => Promise<void>) | null> {
  const lockPath = lockFilePath(projectRoot, runId);
  if (!lockPath) return null;
  const maxAttempts = 20;
  const retryDelayMs = 25;
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    try {
      await mkdir(dirname(lockPath), { recursive: true });
      await writeFile(lockPath, String(process.pid), { flag: "wx" });
      return async () => {
        await unlink(lockPath).catch(() => undefined);
      };
    } catch {
      await new Promise((r) => setTimeout(r, retryDelayMs));
    }
  }
  // Couldn't acquire — proceed without the cross-process lock (best-effort only).
  return null;
}

export async function withRunLock<T>(projectRoot: string, runId: string, fn: () => Promise<T>): Promise<T> {
  const prior = inProcessLocks.get(runId) ?? Promise.resolve();
  let release!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  // The queue tail this call installs. Keep it in a local so the finally block can
  // compare by reference: recomputing `prior.then(() => gate)` there produces a
  // NEW promise that is never === the stored one, so the entry would never be
  // deleted (a slow per-runId map leak on a long-lived server).
  const mine = prior.then(() => gate);
  inProcessLocks.set(runId, mine);
  await prior;
  try {
    const releaseDiskLock = await acquireDiskLock(projectRoot, runId);
    try {
      return await fn();
    } finally {
      if (releaseDiskLock) await releaseDiskLock();
    }
  } finally {
    release();
    // Only clear the map when we're still the tail — a later caller that chained
    // on will have replaced it, and its entry must survive.
    if (inProcessLocks.get(runId) === mine) inProcessLocks.delete(runId);
  }
}

/**
 * Best-effort disk cleanup mirroring the in-memory TTL/grace window, run
 * opportunistically from abqc_review_code. Temp files are only reclaimed once
 * they're older than STALE_TMP_MS — a fresh `.tmp` may belong to a write another
 * process has in flight right now (unconditional unlink previously raced a
 * peer's writeFile→rename and made its persist silently disappear).
 */
export async function cleanupStaleRunFiles(projectRoot: string): Promise<void> {
  let entries: string[];
  try {
    entries = await readdir(runsDir(projectRoot));
  } catch {
    return; // no .abqc/runs yet
  }
  const now = Date.now();
  await Promise.all(
    entries.map(async (name) => {
      const filePath = join(runsDir(projectRoot), name);
      if (name.endsWith(".tmp") || name.endsWith(".lock")) {
        try {
          const s = await stat(filePath);
          if (now - s.mtimeMs > STALE_TMP_MS) await unlink(filePath).catch(() => undefined);
        } catch {
          // Already gone — fine.
        }
        return;
      }
      if (!name.endsWith(".json")) return;
      try {
        const data = JSON.parse(await readFile(filePath, "utf8")) as SerializedLedgerRun;
        const stale =
          data.completedAt !== undefined
            ? now - data.completedAt > COMPLETED_GRACE_MS
            : now - data.createdAt > RUN_TTL_MS;
        if (stale) await unlink(filePath);
      } catch {
        // Corrupt or unreadable — remove so it doesn't accumulate forever.
        await unlink(filePath).catch(() => undefined);
      }
    }),
  );
}

// A completed run's cached COMPLETE payload, replayed for late coverage checks or
// idempotent re-submits during the grace window (instead of "unknown runId").
export function completedResult(run: LedgerRun) {
  return {
    content: [
      {
        type: "text" as const,
        text: run.finalResult ?? JSON.stringify({ reviewStatus: "COMPLETE", complete: true }, null, 2),
      },
    ],
  };
}

// Returns a concrete next (file, domain) to work on, so the "keep going" instruction
// in tool responses can name the exact next call instead of a vague reminder — a
// specific instruction survives long tool loops better than a generic one read once
// in the tool description.
export function pickNextTarget(
  run: LedgerRun,
  ruleById: (id: string) => Rule | undefined,
  preferFilePath?: string,
): { filePath: string; domain: string } | null {
  const order = [
    ...(preferFilePath ? [preferFilePath] : []),
    ...Array.from(run.files.keys()).filter((fp) => fp !== preferFilePath),
  ];
  for (const fp of order) {
    const entry = run.files.get(fp);
    if (!entry || entry.verdicts.size === entry.expected.size) continue;
    const remaining = Array.from(entry.expected).find((id) => !entry.verdicts.has(id));
    if (!remaining) continue;
    const rule = ruleById(remaining);
    return { filePath: fp, domain: rule?.domain ?? remaining };
  }
  return null;
}
