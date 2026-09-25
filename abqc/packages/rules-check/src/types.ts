import type { Severity } from "@abqc/core";

export interface CheckContext {
  filePath: string;
  content: string;
  ext: string;
  projectRoot: string;
  /**
   * Absolute paths of action entry files resolved from YAML `function:` declarations.
   * Empty/undefined when no config was in scope (checkers then fall back to path heuristics).
   */
  actionEntryFiles?: Set<string>;
}

export interface RepoContext {
  projectRoot: string;
  allFiles: string[];
  actionEntryFiles?: Set<string>;
}

export interface CheckHit {
  line?: number;
  column?: number;
  endLine?: number;
  endColumn?: number;
  message: string;
  /** Absolute path for repo-scoped findings (overrides runner default target). */
  path?: string;
}

export interface FileChecker {
  ruleId: string;
  kind: "file";
  appliesTo: (filePath: string) => boolean;
  check: (ctx: CheckContext) => CheckHit[] | Promise<CheckHit[]>;
}

export interface RepoChecker {
  ruleId: string;
  kind: "repo";
  check: (ctx: RepoContext) => CheckHit[] | Promise<CheckHit[]>;
}

export type Checker = FileChecker | RepoChecker;

export type RuleCoverageStatus = "automatable" | "partial" | "manual";

export interface RuleCoverageEntry {
  id: string;
  domain: string;
  title: string;
  severity: import("@abqc/core").Severity;
  status: RuleCoverageStatus;
}

/** @deprecated Use Finding from @abqc/core — kept as alias for backward compatibility. */
export type { Finding as MechanicalFinding } from "@abqc/core";
