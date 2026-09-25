import type { Finding } from "@abqc/core";
import { mapEslintRuleId } from "./eslint-map.js";

/**
 * De-duplicates findings so each library rule is reported once per file+line:
 *  1. When ESLint reports a finding mapped to the same library rule id and file+line
 *     as a mechanical finding, drop the mechanical one (ESLint is authoritative for location).
 *  2. When two ESLint rules map to the same library rule id at the same file+line
 *     (e.g. `no-eval` and `security/detect-eval-with-expression` → the same library
 *     rule), keep only the first so the user isn't shown the same violation twice.
 */
export function dedupeFindings(findings: Finding[]): Finding[] {
  const eslintKeys = new Set<string>();
  for (const f of findings) {
    if (f.source !== "eslint") continue;
    const libId = f.libraryRuleId ?? mapEslintRuleId(f.ruleId);
    if (!libId) continue;
    eslintKeys.add(dedupeKey(libId, f.filePath, f.line));
  }

  const seenEslintKeys = new Set<string>();
  return findings.filter((f) => {
    if (f.source === "mechanical") {
      const key = dedupeKey(f.ruleId, f.filePath, f.line);
      return !eslintKeys.has(key);
    }
    if (f.source === "eslint") {
      const libId = f.libraryRuleId ?? mapEslintRuleId(f.ruleId);
      // Unmapped ESLint findings keep their own id — never collapse distinct rules.
      if (!libId) return true;
      const key = dedupeKey(libId, f.filePath, f.line);
      if (seenEslintKeys.has(key)) return false;
      seenEslintKeys.add(key);
      return true;
    }
    return true;
  });
}

function dedupeKey(ruleId: string, filePath: string, line?: number): string {
  return `${ruleId}\0${filePath}\0${line ?? 0}`;
}

/** Normalize ESLint findings to library rule ids where mapped. */
export function normalizeEslintFindings(findings: Finding[]): Finding[] {
  return findings.map((f) => {
    if (f.source !== "eslint") return f;
    const libId = mapEslintRuleId(f.ruleId);
    if (!libId) return f;
    return {
      ...f,
      libraryRuleId: libId,
      ruleId: libId,
    };
  });
}
