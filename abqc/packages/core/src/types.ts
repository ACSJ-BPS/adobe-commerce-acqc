export type Severity = "error" | "warn" | "info";

/** Shared ordering used by both CLI channels' `--severity` floor filtering — single source of truth. */
export function severityRank(s: Severity): number {
  if (s === "error") return 3;
  if (s === "warn") return 2;
  return 1;
}

export interface Rule {
  id: string;
  domain: string;
  subTopic: string;
  title: string;
  severity: Severity;
  tags: string[];
  rationale: string;
  bad: string;
  good: string;
  filePath: string;
  /** Raw YAML from optional `### Check` fenced block — parsed by rules-check only. */
  checkSpec?: string;
}

export interface ReviewInput {
  paths: string[];
  diff?: string;
  baselinePath?: string;
  rulesDir?: string;
  cwd?: string;
  /** Run declarative mechanical checks from @abqc/rules-check (default: true). */
  mechanical?: boolean;
  /** Run ESLint inside mechanical checks (default: true when mechanical is enabled). */
  mechanicalEslint?: boolean;
}

export interface Finding {
  ruleId: string;
  severity: Severity;
  message: string;
  filePath: string;
  line?: number;
  column?: number;
  endLine?: number;
  endColumn?: number;
  source: "eslint" | "judgment" | "mechanical";
  confidence?: "high" | "medium" | "low";
  /** Set when an ESLint finding is mapped to a library rule id. */
  libraryRuleId?: string;
}

export interface SarifLog {
  $schema: string;
  version: "2.1.0";
  runs: SarifRun[];
}

export interface SarifRun {
  tool: { driver: SarifDriver };
  results: SarifResult[];
}

export interface SarifDriver {
  name: string;
  version: string;
  informationUri?: string;
  rules: SarifRuleDescriptor[];
}

export interface SarifRuleDescriptor {
  id: string;
  name: string;
  shortDescription: { text: string };
  fullDescription?: { text: string };
  helpUri?: string;
  defaultConfiguration?: { level: "error" | "warning" | "note" };
  properties?: { tags?: string[] };
}

export interface SarifResult {
  ruleId: string;
  level: "error" | "warning" | "note";
  message: { text: string };
  locations: Array<{
    physicalLocation: {
      artifactLocation: { uri: string };
      region?: {
        startLine?: number;
        startColumn?: number;
        endLine?: number;
        endColumn?: number;
      };
    };
  }>;
  properties?: {
    source: "eslint" | "judgment" | "mechanical";
    confidence?: "high" | "medium" | "low";
  };
}
