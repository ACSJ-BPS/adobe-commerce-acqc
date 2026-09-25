import { relative } from "node:path";
import type {
  Finding,
  Rule,
  SarifLog,
  SarifResult,
  SarifRuleDescriptor,
  Severity,
} from "./types.js";

const TOOL_NAME = "appbuilder-quality-check";
const TOOL_VERSION = "0.1.0";
const TOOL_URI = "https://github.com/adobe/appbuilder-quality-check";

const LEVEL_FROM_SEVERITY: Record<Severity, "error" | "warning" | "note"> = {
  error: "error",
  warn: "warning",
  info: "note",
};

export function buildSarif(
  findings: Finding[],
  rules: Rule[],
  cwd: string,
): SarifLog {
  const usedRuleIds = new Set(findings.map((f) => f.ruleId));
  const ruleDescriptors: SarifRuleDescriptor[] = rules
    .filter((r) => usedRuleIds.has(r.id))
    .map(toRuleDescriptor);

  // A ruleId not in our markdown library is usually a real ESLint rule id — but
  // it can also be a synthetic/internal marker (e.g. "judgment/layer-unavailable",
  // or a hallucinated id an LLM judgment call invented) or a rule that was
  // deliberately withheld/renamed. Deciding "ESLint" purely by "not in our
  // library" previously fabricated a bogus eslint.org/docs/.../judgment%2Funknown
  // URL (a guaranteed 404) for anything that wasn't ESLint. Only findings whose
  // `source` is actually "eslint" get the ESLint helpUri; everything else gets a
  // plain descriptor with no fabricated link.
  const sourceByRuleId = new Map<string, Finding["source"]>();
  for (const f of findings) {
    if (!sourceByRuleId.has(f.ruleId)) sourceByRuleId.set(f.ruleId, f.source);
  }
  for (const id of usedRuleIds) {
    if (ruleDescriptors.find((d) => d.id === id)) continue;
    ruleDescriptors.push(
      sourceByRuleId.get(id) === "eslint" ? synthesizeEslintDescriptor(id) : synthesizeUnknownDescriptor(id),
    );
  }

  return {
    $schema: "https://docs.oasis-open.org/sarif/sarif/v2.1.0/cos02/schemas/sarif-schema-2.1.0.json",
    version: "2.1.0",
    runs: [
      {
        tool: {
          driver: {
            name: TOOL_NAME,
            version: TOOL_VERSION,
            informationUri: TOOL_URI,
            rules: ruleDescriptors,
          },
        },
        results: findings.map((f) => toResult(f, cwd)),
      },
    ],
  };
}

function toRuleDescriptor(rule: Rule): SarifRuleDescriptor {
  return {
    id: rule.id,
    name: rule.title,
    shortDescription: { text: rule.title },
    fullDescription: { text: rule.rationale },
    defaultConfiguration: { level: LEVEL_FROM_SEVERITY[rule.severity] },
    properties: { tags: rule.tags },
  };
}

function synthesizeEslintDescriptor(id: string): SarifRuleDescriptor {
  return {
    id,
    name: id,
    shortDescription: { text: `ESLint rule ${id}` },
    helpUri: `https://eslint.org/docs/latest/rules/${id}`,
  };
}

/** For any non-ESLint id outside our markdown library — no fabricated (likely-404) link. */
function synthesizeUnknownDescriptor(id: string): SarifRuleDescriptor {
  return {
    id,
    name: id,
    shortDescription: { text: id },
  };
}

function toResult(f: Finding, cwd: string): SarifResult {
  const rel = relative(cwd, f.filePath).replace(/\\/g, "/") || f.filePath;
  return {
    ruleId: f.ruleId,
    level: LEVEL_FROM_SEVERITY[f.severity],
    message: { text: f.message },
    locations: [
      {
        physicalLocation: {
          artifactLocation: { uri: rel },
          region:
            f.line !== undefined
              ? {
                  startLine: f.line,
                  ...(f.column !== undefined ? { startColumn: f.column } : {}),
                  ...(f.endLine !== undefined ? { endLine: f.endLine } : {}),
                  ...(f.endColumn !== undefined ? { endColumn: f.endColumn } : {}),
                }
              : undefined,
        },
      },
    ],
    properties: {
      source: f.source,
      ...(f.confidence ? { confidence: f.confidence } : {}),
    },
  };
}
