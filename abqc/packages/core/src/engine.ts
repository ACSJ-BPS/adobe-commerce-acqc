import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { EslintRunner } from "./eslint-runner.js";
import { defaultProvider, type LLMProvider } from "./llm-judge.js";
import { defaultRulesDir } from "./rules-dir.js";
import { RuleLoader } from "./rule-loader.js";
import { RuleRouter } from "./rule-router.js";
import { buildSarif } from "./sarif.js";
import { expandPaths } from "./walk.js";
import type { Finding, ReviewInput, Rule, SarifLog } from "./types.js";

// For API providers (Anthropic/OpenAI) 5 concurrent files is fine.
// For ClaudeCliProvider, chunks are sequential per file so 3 concurrent files
// means at most 3 simultaneous claude subprocesses — avoids rate limits.
const JUDGMENT_CONCURRENCY = 3;

// Directories that are always generated/vendored — never review them.
const DEFAULT_SKIP_DIRS = new Set([
  "node_modules", "dist", "build", "coverage", ".turbo", ".next", "out",
]);

// Safe git ref characters — rejects shell metacharacters before passing to execFileSync.
const SAFE_GIT_REF_RE = /^[a-zA-Z0-9_.~^:/@-]{1,200}$/;

export interface EngineOptions {
  rulesDir?: string;
  provider?: LLMProvider;
  /** When true, skip the LLM judgment layer (used by the MCP server). */
  staticOnly?: boolean;
  /** Additional directory names to skip during file walk (merged with defaults). */
  skipDirs?: string[];
  /** Override the per-file character truncation limit (default: 200 000). */
  maxFileChars?: number;
}

export interface ReviewResult {
  sarif: SarifLog;
  findings: Finding[];
  /** Subset of findings with confidence="low" — separated so callers can downgrade them. */
  lowConfidenceFindings: Finding[];
  routedRules: Map<string, Rule[]>;
  rulesLoaded: number;
  /**
   * file path → rule ids the mechanical layer definitively evaluated (pass OR fail).
   * Repo-scoped checks are keyed by the project root (cwd). Callers that run a
   * separate judgment layer use this to skip re-checking already-decided cells
   * without leaving a coverage hole. Empty when the mechanical layer didn't run.
   */
  mechanicalCoverage: Map<string, Set<string>>;
  /**
   * Absolute paths of action ENTRY files (from app.config.yaml + `$include`s).
   * Lets callers distinguish an action's entry handler from its helper modules
   * for scoped routing. Empty when the mechanical layer didn't run.
   */
  actionEntryFiles: Set<string>;
}

export class Engine {
  private loader: RuleLoader;
  private router: RuleRouter | null = null;
  private eslintRunner: EslintRunner;
  private provider: LLMProvider;
  private staticOnly: boolean;
  private skipDirs: Set<string>;
  private maxFileChars: number;

  constructor(options: EngineOptions = {}) {
    const rulesDir = options.rulesDir ?? defaultRulesDir();
    this.loader = new RuleLoader(rulesDir);
    this.provider = options.provider ?? defaultProvider();
    this.staticOnly = options.staticOnly ?? false;
    this.skipDirs = options.skipDirs
      ? new Set([...DEFAULT_SKIP_DIRS, ...options.skipDirs])
      : DEFAULT_SKIP_DIRS;
    this.maxFileChars = options.maxFileChars ?? 200_000;
    this.eslintRunner = new EslintRunner({ maxFileChars: this.maxFileChars });
  }

  async ready(): Promise<void> {
    if (this.router) return;
    const rules = await this.loader.load();
    this.router = new RuleRouter(rules);
  }

  /**
   * Resolve `input.paths` (files or directories) to the concrete file list that
   * `review()` would operate on — directory walk + skip-dirs + optional `diff`
   * filter — without running the mechanical or judgment layers. Exposed so
   * callers (e.g. a batch planner) can enumerate a large tree cheaply, with no
   * LLM calls and no ledger, using the exact same resolution logic as review().
   */
  async resolveFiles(input: Pick<ReviewInput, "paths" | "cwd" | "diff">): Promise<string[]> {
    await this.ready();
    const cwd = input.cwd ?? process.cwd();
    const allFiles = await expandPaths(input.paths, cwd, {
      skipDirs: this.skipDirs,
      logPrefix: "[abqc]",
    });
    return input.diff ? await applyDiffFilter(allFiles, input.diff, cwd) : allFiles;
  }

  async review(input: ReviewInput): Promise<ReviewResult> {
    await this.ready();
    const cwd = input.cwd ?? process.cwd();
    const runMechanical = input.mechanical !== false;

    // Resolve the set of files to review, optionally filtered to git-changed files.
    const files = await this.resolveFiles(input);

    const allFindings: Finding[] = [];
    const routedRules = new Map<string, Rule[]>();

    // Always record which rules apply to each reviewed file, independent of which
    // analysis phases run. Callers (MCP, CLI) rely on routedRules to know whether any
    // files were actually reviewed — it must be populated in every mode.
    const router = this.router as RuleRouter;
    for (const file of files) {
      routedRules.set(file, router.routeFile(file).rules);
    }

    // Phase 1: declarative mechanical checks + ESLint (via rules-check when available).
    let ranMechanical = false;
    const mechanicalCoverage = new Map<string, Set<string>>();
    const actionEntryFiles = new Set<string>();
    if (runMechanical) {
      // Pass the already-resolved (and diff-filtered, when `input.diff` is set)
      // file list — otherwise the mechanical layer re-expands `input.paths` from
      // scratch and checks the WHOLE tree even when `--diff` scoped the routing
      // and judgment layers to a handful of changed files.
      const mechanical = await runMechanicalPhase({
        paths: input.paths,
        files,
        cwd,
        rules: this.loader.all(),
        eslint: input.mechanicalEslint !== false,
      });
      if (mechanical) {
        allFindings.push(...mechanical.findings);
        for (const { filePath, ruleId } of mechanical.evaluated) {
          let set = mechanicalCoverage.get(filePath);
          if (!set) {
            set = new Set<string>();
            mechanicalCoverage.set(filePath, set);
          }
          set.add(ruleId);
        }
        for (const f of mechanical.actionEntryFiles) actionEntryFiles.add(f);
        ranMechanical = true;
      }
    }

    // ESLint-only path: used when mechanical checks are disabled OR when the
    // @abqc/rules-check package is unavailable (fallback so findings are never
    // silently empty).
    if (!ranMechanical) {
      for (const file of files) {
        const eslintFindings = await this.eslintRunner.run(file);
        allFindings.push(...eslintFindings);
      }
    }

    // Phase 2: LLM judgment — run up to JUDGMENT_CONCURRENCY files in parallel.
    if (!this.staticOnly) {
      const judgeTargets = files.filter((f) => (routedRules.get(f)?.length ?? 0) > 0);
      await withConcurrency(judgeTargets, JUDGMENT_CONCURRENCY, async (file) => {
        const fileContents = await safeRead(file);
        if (fileContents === null) return;
        const judgmentFindings = await this.provider.judge({
          filePath: file,
          fileContents,
          rules: routedRules.get(file) ?? [],
          maxFileChars: this.maxFileChars,
        });
        allFindings.push(...judgmentFindings);
      });
    }

    // Separate low-confidence judgment findings so callers can treat them differently.
    const findings = allFindings.filter(
      (f) =>
        f.source === "eslint" ||
        f.source === "mechanical" ||
        f.confidence !== "low" ||
        f.severity === "error",
    );
    const lowConfidenceFindings = allFindings.filter(
      (f) => f.source === "judgment" && f.confidence === "low" && f.severity !== "error",
    );

    const allRules = this.loader.all();
    const sarif = buildSarif(allFindings, allRules, cwd);
    return {
      sarif,
      findings,
      lowConfidenceFindings,
      routedRules,
      rulesLoaded: allRules.length,
      mechanicalCoverage,
      actionEntryFiles,
    };
  }

  rules(): RuleLoader {
    return this.loader;
  }

  /** Returns the initialized RuleRouter, or null before ready() has been called. */
  getRouter(): RuleRouter | null {
    return this.router;
  }
}

const RULES_CHECK_PKG = "@abqc/rules-check";

/**
 * Run declarative mechanical checks via @abqc/rules-check.
 * Returns findings, or `null` when the package is unavailable so the caller can
 * fall back to the ESLint-only path (findings are never silently dropped).
 */
async function runMechanicalPhase(opts: {
  paths: string[];
  files: string[];
  cwd: string;
  rules: Rule[];
  eslint: boolean;
}): Promise<{
  findings: Finding[];
  evaluated: Array<{ filePath: string; ruleId: string }>;
  actionEntryFiles: string[];
} | null> {
  // Only a failure to resolve the TOP-LEVEL package itself falls back to
  // ESLint-only — deliberately its own try/catch, separate from the call
  // below. Both a missing package AND a bug deep inside rules-check's own
  // transitive imports can throw a message containing "Cannot find module",
  // so string-matching across BOTH steps previously risked misreading a real
  // internal error as "the optional dependency isn't installed" and silently
  // swallowing it instead of surfacing a genuine bug.
  let mod: {
    runMechanicalChecks: (o: {
      paths: string[];
      files: string[];
      cwd: string;
      rules: Rule[];
      eslint: boolean;
    }) => Promise<{
      findings: Finding[];
      evaluated: Array<{ filePath: string; ruleId: string }>;
      actionEntryFiles: string[];
    }>;
  };
  try {
    mod = (await import(RULES_CHECK_PKG)) as typeof mod;
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    if (msg.includes(`Cannot find package '${RULES_CHECK_PKG}'`) || msg.includes(`Cannot find module '${RULES_CHECK_PKG}'`)) {
      process.stderr.write(
        "[abqc] @abqc/rules-check not installed — falling back to ESLint-only review\n",
      );
      return null;
    }
    throw e;
  }
  // Deliberately NOT caught here — an error thrown once the package resolved
  // successfully (i.e. a real bug inside runMechanicalChecks) must propagate,
  // never be mistaken for "package not installed".
  const result = await mod.runMechanicalChecks({
    paths: opts.paths,
    files: opts.files,
    cwd: opts.cwd,
    rules: opts.rules,
    eslint: opts.eslint,
  });
  return {
    findings: result.findings,
    evaluated: result.evaluated,
    actionEntryFiles: result.actionEntryFiles,
  };
}

async function applyDiffFilter(
  files: string[],
  diffRef: string,
  cwd: string,
): Promise<string[]> {
  if (!SAFE_GIT_REF_RE.test(diffRef)) {
    process.stderr.write(`[abqc] invalid git ref "${diffRef}" — falling back to full review\n`);
    return files;
  }
  try {
    const stdout = execFileSync("git", ["diff", "--name-only", diffRef], {
      cwd,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"],
    });
    const changedAbsolute = new Set(
      stdout
        .split("\n")
        .map((line) => line.trim())
        .filter(Boolean)
        .map((rel) => resolve(cwd, rel)),
    );
    const filtered = files.filter((f) => changedAbsolute.has(f));
    // Fall back to full review when diff yields nothing (e.g. clean working tree).
    return filtered.length > 0 ? filtered : files;
  } catch {
    // git not available, invalid ref, or non-git directory — review all files.
    return files;
  }
}

async function safeRead(path: string): Promise<string | null> {
  try {
    return await readFile(path, "utf8");
  } catch {
    return null;
  }
}

async function withConcurrency<T>(
  items: T[],
  concurrency: number,
  fn: (item: T) => Promise<void>,
): Promise<void> {
  const iter = items[Symbol.iterator]();
  const workers = Array.from({ length: Math.min(concurrency, items.length) }, async () => {
    for (let next = iter.next(); !next.done; next = iter.next()) {
      await fn(next.value);
    }
  });
  await Promise.all(workers);
}
