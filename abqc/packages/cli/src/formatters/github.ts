import type { Finding, SarifLog } from "@abqc/core";

export function formatGithub(findings: Finding[], _sarif: SarifLog): string {
  if (findings.length === 0) {
    return "## abqc — clean\n\nNo findings.\n";
  }

  const errors = findings.filter((f) => f.severity === "error");
  const warns = findings.filter((f) => f.severity === "warn");
  const infos = findings.filter((f) => f.severity === "info");

  const lines: string[] = [];
  lines.push("## abqc — App Builder code review");
  lines.push("");
  lines.push(`**${findings.length} finding(s)**: ${errors.length} error, ${warns.length} warn, ${infos.length} info`);
  lines.push("");

  for (const f of findings) {
    const icon = f.severity === "error" ? ":x:" : f.severity === "warn" ? ":warning:" : ":information_source:";
    const loc = f.line !== undefined ? `:${f.line}` : "";
    lines.push(`- ${icon} **\`${f.ruleId}\`** in \`${f.filePath}${loc}\` — ${escapeMd(f.message)}`);
  }
  return lines.join("\n");
}

/** Escape markdown special chars in user/LLM-supplied text to prevent comment injection. */
function escapeMd(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/`/g, "\\`")
    .replace(/\[/g, "\\[")
    .replace(/\]/g, "\\]");
}
