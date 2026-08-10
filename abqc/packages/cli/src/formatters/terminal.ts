import type { Finding } from "@abqc/core";

export function formatTerminal(findings: Finding[], rulesLoaded: number): string {
  if (findings.length === 0) {
    return `clean — no findings (${rulesLoaded} rules loaded)`;
  }

  const byFile = new Map<string, Finding[]>();
  for (const f of findings) {
    const list = byFile.get(f.filePath) ?? [];
    list.push(f);
    byFile.set(f.filePath, list);
  }

  const lines: string[] = [];
  for (const [file, list] of byFile) {
    lines.push(`\n${file}`);
    for (const f of list) {
      const loc = f.line !== undefined ? `:${f.line}${f.column !== undefined ? ":" + f.column : ""}` : "";
      const tag = f.severity === "error" ? "ERROR" : f.severity === "warn" ? "WARN " : "INFO ";
      lines.push(`  ${tag} ${f.ruleId}${loc}  ${f.message}`);
    }
  }

  const errors = findings.filter((f) => f.severity === "error").length;
  const warns = findings.filter((f) => f.severity === "warn").length;
  const infos = findings.filter((f) => f.severity === "info").length;
  lines.push(``);
  lines.push(`${findings.length} finding(s) — ${errors} error, ${warns} warn, ${infos} info  (${rulesLoaded} rules loaded)`);

  return lines.join("\n");
}
