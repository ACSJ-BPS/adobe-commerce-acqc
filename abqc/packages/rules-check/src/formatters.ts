import type { Finding, Rule, Severity } from "@abqc/core";
import { relPath } from "./runner.js";

export interface TerminalSummary {
  rulesLoaded: number;
  checkersRun: number;
  eslintRuleCount: number;
  eslintEnabled: boolean;
  cwd: string;
}

export function formatTerminal(findings: Finding[], summary: TerminalSummary): string {
  const { rulesLoaded, checkersRun, eslintRuleCount, eslintEnabled, cwd } = summary;
  const eslintNote = eslintEnabled
    ? `, ESLint layer: ${eslintRuleCount} rule(s) triggered`
    : ", ESLint layer: disabled";
  if (findings.length === 0) {
    return `clean — no findings (${checkersRun} mechanical checkers over ${rulesLoaded} library rules${eslintNote})`;
  }
  const grouped = new Map<string, Finding[]>();
  for (const f of findings) {
    const rel = relPath(f.filePath, cwd);
    if (!grouped.has(rel)) grouped.set(rel, []);
    grouped.get(rel)!.push(f);
  }
  const lines: string[] = [];
  for (const [file, fileFindings] of grouped) {
    lines.push("", file);
    for (const f of fileFindings) {
      const loc = f.line ? `${f.line}:${f.column ?? 1}` : "-";
      const tag = f.source === "eslint" ? "eslint" : "rules";
      lines.push(`  ${f.severity.toUpperCase().padEnd(5)} [${tag}] ${f.ruleId}:${loc}  ${f.message}`);
    }
  }
  const errors = findings.filter((f) => f.severity === "error").length;
  const warns = findings.filter((f) => f.severity === "warn").length;
  const infos = findings.filter((f) => f.severity === "info").length;
  const mech = findings.filter((f) => f.source === "mechanical").length;
  const es = findings.filter((f) => f.source === "eslint").length;
  lines.push(
    "",
    `${findings.length} finding(s) — ${errors} error, ${warns} warn, ${infos} info`,
    `  sources: ${mech} mechanical (${checkersRun} checkers), ${es} eslint${eslintNote}`,
  );
  return lines.join("\n").trimStart();
}

export function buildMechanicalSarif(findings: Finding[], rules: Rule[], cwd: string): object {
  const usedIds = new Set(findings.map((f) => f.ruleId));
  const ruleById = new Map(rules.map((r) => [r.id, r]));
  const descriptors = [...usedIds].map((id) => {
    const r = ruleById.get(id);
    if (r) {
      return {
        id: r.id,
        name: r.id,
        shortDescription: { text: r.title },
        defaultConfiguration: {
          level: r.severity === "error" ? "error" : r.severity === "warn" ? "warning" : "note",
        },
      };
    }
    return {
      id,
      name: id,
      shortDescription: { text: id },
      defaultConfiguration: { level: "warning" as const },
    };
  });

  const levelMap: Record<Severity, string> = { error: "error", warn: "warning", info: "note" };

  return {
    $schema: "https://docs.oasis-open.org/sarif/sarif/v2.1.0/cos02/schemas/sarif-schema-2.1.0.json",
    version: "2.1.0",
    runs: [{
      tool: {
        driver: {
          name: "appbuilder-rules-check",
          version: "0.1.0",
          informationUri: "https://github.com/adobe/appbuilder-quality-check",
          rules: descriptors,
        },
      },
      results: findings.map((f) => ({
        ruleId: f.ruleId,
        level: levelMap[f.severity as Severity],
        message: { text: f.message },
        locations: [{
          physicalLocation: {
            artifactLocation: { uri: relPath(f.filePath, cwd) },
            ...(f.line ? {
              region: {
                startLine: f.line,
                startColumn: f.column ?? 1,
                endLine: f.endLine ?? f.line,
                endColumn: f.endColumn,
              },
            } : {}),
          },
        }],
        properties: { source: f.source, confidence: "high" },
      })),
    }],
  };
}
