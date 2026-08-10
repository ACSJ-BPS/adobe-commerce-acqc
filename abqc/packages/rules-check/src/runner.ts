import { readFile } from "node:fs/promises";
import { extname, join, relative } from "node:path";
import { EslintRunner, severityRank, type Finding, type Rule, type Severity } from "@abqc/core";
import type { CheckContext, RepoContext } from "./types.js";
import { expandPaths, isYamlConfig } from "./walk.js";
import { resolveActionEntryFiles, resolveIncludes } from "./utils/appbuilder-config.js";
import { parseCheckSpec, matchesFileGlob } from "./checkers/spec.js";
import { runTypeCheck } from "./checkers/type-registry.js";
import { dedupeFindings, normalizeEslintFindings } from "./dedup.js";
import { mapEslintRuleId, isFaithfulEslintRule } from "./eslint-map.js";

export interface RunOptions {
  paths: string[];
  cwd: string;
  rules: Rule[];
  eslint?: boolean;
  /**
   * Pre-resolved file set to check (e.g. after a caller has already applied
   * `--diff` filtering). When provided, this is used INSTEAD of re-expanding
   * `paths` from scratch — otherwise a diff-scoped review still mechanically
   * checked every file under `paths`, not just the changed ones, and
   * `evaluated`/`mechanicalCoverage` disagreed with the judgment layer's file
   * set. Repo-scoped checks still consider the whole project via `cwd`.
   */
  files?: string[];
}

export interface RunResult {
  findings: Finding[];
  filesScanned: number;
  rulesLoaded: number;
  checkersRun: number;
  eslintRuleIds: string[];
  /**
   * (filePath, ruleId) pairs a declarative `### Check` spec was actually run
   * against — i.e. definitively evaluated (pass OR fail). Consumers use this to
   * skip re-checking these cells in a later judgment layer without leaving a
   * coverage hole (a rule whose glob didn't match a file is absent here).
   * Repo-scoped checks are keyed by the project root (opts.cwd).
   */
  evaluated: Array<{ filePath: string; ruleId: string }>;
  /**
   * Absolute paths of action ENTRY files, resolved from app.config.yaml (+
   * `$include`s) at the project root. Consumers use this to tell an action's
   * entry handler apart from its helper modules for scoped routing.
   */
  actionEntryFiles: string[];
}

export async function runMechanicalChecks(opts: RunOptions): Promise<RunResult> {
  const projectRoot = opts.cwd;
  const runEslint = opts.eslint !== false;
  const allFiles =
    opts.files ?? (await expandPaths(opts.paths, projectRoot, { logPrefix: "[abqc-rules-check]" }));
  const findings: Finding[] = [];
  const evaluated: Array<{ filePath: string; ruleId: string }> = [];
  const eslintRunner = runEslint ? new EslintRunner({}) : null;
  const eslintRuleIds = new Set<string>();

  const declarativeRules = opts.rules.flatMap((rule) => {
    try {
      const check = parseCheckSpec(rule.checkSpec);
      if (!check) return [];
      return [{ rule, check }];
    } catch (e) {
      // A rule whose ### Check spec fails to parse is silently excluded from
      // `declarativeRules` — with no signal, that rule's mechanical coverage
      // is just gone (typo'd YAML disables a check with nothing pointing at
      // why). `abqc rules validate` catches this ahead of time; warn here too
      // so a plain `review`/`check` run isn't silent about it either.
      process.stderr.write(
        `[abqc-rules-check] ${rule.id}: ### Check spec failed to parse — ${e instanceof Error ? e.message : e}. Skipping this rule's mechanical check.\n`,
      );
      return [];
    }
  });

  const fileRules = declarativeRules.filter((r) => (r.check.scope ?? "file") === "file");
  const repoRules = declarativeRules.filter((r) => r.check.scope === "repo");

  const actionEntryFiles = await resolveEntryFiles(allFiles, projectRoot);

  for (const filePath of allFiles) {
    let content: string;
    try {
      content = await readFile(filePath, "utf8");
    } catch {
      continue;
    }
    const ctx: CheckContext = {
      filePath,
      content,
      ext: extname(filePath).toLowerCase(),
      projectRoot,
      actionEntryFiles,
    };

    for (const { rule, check } of fileRules) {
      if (check.files && !matchesFileGlob(filePath, check.files)) continue;
      if (check.type === "yaml-path" && !isYamlConfig(filePath) && !check.files) continue;

      // This spec was applicable and is about to run against this file — record the
      // (file, rule) cell as definitively evaluated regardless of pass/fail below.
      evaluated.push({ filePath, ruleId: rule.id });
      const hits = await runTypeCheck({ rule, spec: check, file: ctx });
      for (const hit of hits) {
        findings.push(toFinding(rule, filePath, hit));
      }
    }

    if (eslintRunner) {
      const eslintFindings = await eslintRunner.run(filePath);
      const parseFailed = eslintFindings.some((f) => f.ruleId === "eslint/parsing-error");
      for (const f of eslintFindings) {
        if (f.ruleId) eslintRuleIds.add(f.ruleId);
        findings.push({ ...f, source: "eslint" });
      }
      // A clean run of a FAITHFUL ESLint rule (a complete implementation of its
      // library rule, e.g. radix/eqeqeq) is as definitive as a declarative check
      // that ran clean — record it so it can be auto-decided rather than left to
      // judgment. Heuristic/partial mappings (the security detect-* family, the
      // no-eval family that misses vm) are NOT trustworthy on a clean run and are
      // excluded here, so they stay in the agent's judgment set (a FIRED finding
      // is still auto-seeded as a violation via result.findings — this only gates
      // the clean-pass lock). Skip everything on a parse failure: nothing was
      // meaningfully evaluated.
      if (!parseFailed) {
        for (const eslintId of eslintRunner.activeRuleIds(filePath)) {
          if (!isFaithfulEslintRule(eslintId)) continue;
          const libraryId = mapEslintRuleId(eslintId);
          if (libraryId) evaluated.push({ filePath, ruleId: libraryId });
        }
      }
    }
  }

  const repoCtx: RepoContext = { projectRoot, allFiles, actionEntryFiles };
  for (const { rule, check } of repoRules) {
    // Repo-scoped checks run once for the whole run — key their coverage by the
    // project root so a repo-level consumer can match them independent of file.
    evaluated.push({ filePath: projectRoot, ruleId: rule.id });
    const hits = await runTypeCheck({ rule, spec: check, repo: repoCtx });
    for (const hit of hits) {
      const target = hit.path ?? repoTargetPath(rule.id, projectRoot);
      findings.push(toFinding(rule, target, hit));
    }
  }

  let merged = normalizeEslintFindings(findings);
  merged = dedupeFindings(merged);

  return {
    findings: merged,
    filesScanned: allFiles.length,
    rulesLoaded: opts.rules.length,
    checkersRun: declarativeRules.length,
    eslintRuleIds: Array.from(eslintRuleIds).sort(),
    evaluated,
    actionEntryFiles: Array.from(actionEntryFiles),
  };
}

function repoTargetPath(ruleId: string, projectRoot: string): string {
  if (ruleId.includes("never-commit-env")) return join(projectRoot, ".gitignore");
  if (ruleId.includes("pin-exact-versions")) return join(projectRoot, "package.json");
  return projectRoot;
}

async function resolveEntryFiles(allFiles: string[], projectRoot: string): Promise<Set<string>> {
  const entries = new Set<string>();

  // Seed the config queue with configs inside the reviewed paths PLUS the project-
  // root app.config.yaml — so entry-point detection works even when the review is
  // scoped to a subdirectory that doesn't itself contain the config (otherwise the
  // action-signature checks fire on every helper file as false positives).
  const queue: string[] = [
    ...allFiles.filter((f) => isYamlConfig(f)),
    join(projectRoot, "app.config.yaml"),
    join(projectRoot, "app.config.yml"),
  ];
  const visited = new Set<string>();
  while (queue.length > 0) {
    const configPath = queue.shift() as string;
    if (visited.has(configPath)) continue;
    visited.add(configPath);
    let content: string;
    try {
      content = await readFile(configPath, "utf8");
    } catch {
      continue;
    }
    for (const abs of resolveActionEntryFiles(content, configPath)) {
      entries.add(abs);
    }
    // Follow $include directives (bounded by `visited`) to reach actions declared
    // in split config files.
    for (const inc of resolveIncludes(content, configPath)) {
      if (!visited.has(inc)) queue.push(inc);
    }
  }
  return entries;
}

function toFinding(
  rule: Rule,
  filePath: string,
  hit: { line?: number; column?: number; endLine?: number; endColumn?: number; message: string },
): Finding {
  return {
    ruleId: rule.id,
    severity: rule.severity,
    message: hit.message,
    filePath,
    line: hit.line,
    column: hit.column,
    endLine: hit.endLine,
    endColumn: hit.endColumn,
    source: "mechanical",
  };
}

export function filterBySeverity(findings: Finding[], floor: Severity): Finding[] {
  return findings.filter((f) => severityRank(f.severity) >= severityRank(floor));
}

export function relPath(filePath: string, cwd: string): string {
  return relative(cwd, filePath).replace(/\\/g, "/") || filePath;
}
