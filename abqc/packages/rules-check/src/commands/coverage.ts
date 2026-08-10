import { writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { classifyRules, buildCoverageMarkdown } from "../classify.js";
import { loadRulesAndValidate } from "../registry.js";
import { defaultRulesDir, rulesCheckPackageRoot } from "../rules-dir.js";

export async function coverageCommand(outPath?: string): Promise<number> {
  const rulesDir = defaultRulesDir();
  const { rules } = await loadRulesAndValidate(rulesDir);
  const entries = classifyRules(rules);
  const md = buildCoverageMarkdown(entries);
  const target = outPath ?? resolve(rulesCheckPackageRoot(), "COVERAGE.md");
  await writeFile(target, md, "utf8");
  process.stdout.write(`Wrote coverage report (${entries.length} rules) → ${target}\n`);
  const auto = entries.filter((e) => e.status === "automatable").length;
  process.stdout.write(`  automatable: ${auto}, partial: ${entries.filter((e) => e.status === "partial").length}, manual: ${entries.filter((e) => e.status === "manual").length}\n`);
  return 0;
}
