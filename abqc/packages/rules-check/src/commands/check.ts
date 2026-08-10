import { resolve } from "node:path";
import type { Severity } from "@abqc/core";
import { loadRulesAndValidate } from "../registry.js";
import { filterBySeverity, runMechanicalChecks } from "../runner.js";
import { buildMechanicalSarif, formatTerminal } from "../formatters.js";
import { defaultRulesDir } from "../rules-dir.js";

interface CheckOptions {
  paths: string[];
  format: "terminal" | "json" | "sarif";
  severityFloor: Severity;
  cwd: string;
  eslint: boolean;
}

export async function checkCommand(argv: string[]): Promise<number> {
  const opts = parseArgs(argv);
  if (opts.paths.length === 0) {
    process.stderr.write("check: no paths provided\n");
    return 2;
  }

  const rulesDir = defaultRulesDir();
  const { rules } = await loadRulesAndValidate(rulesDir);
  const result = await runMechanicalChecks({
    paths: opts.paths,
    cwd: opts.cwd,
    rules,
    eslint: opts.eslint,
  });

  const filtered = filterBySeverity(result.findings, opts.severityFloor);

  if (opts.format === "sarif") {
    process.stdout.write(JSON.stringify(buildMechanicalSarif(filtered, rules, opts.cwd), null, 2) + "\n");
  } else if (opts.format === "json") {
    process.stdout.write(JSON.stringify(filtered, null, 2) + "\n");
  } else {
    process.stdout.write(formatTerminal(filtered, {
      rulesLoaded: result.rulesLoaded,
      checkersRun: result.checkersRun,
      eslintRuleCount: result.eslintRuleIds.length,
      eslintEnabled: opts.eslint,
      cwd: opts.cwd,
    }) + "\n");
  }

  if (filtered.some((f) => f.severity === "error")) return 2;
  if (filtered.some((f) => f.severity === "warn")) return 1;
  return 0;
}

function parseArgs(argv: string[]): CheckOptions {
  const paths: string[] = [];
  let format: CheckOptions["format"] = "terminal";
  let severityFloor: Severity = "info";
  let cwd = process.cwd();
  let eslint = true;

  for (let i = 0; i < argv.length; i++) {
    const a = argv[i] as string;
    if (a.startsWith("--format=")) {
      const val = a.slice("--format=".length);
      if (val === "terminal" || val === "json" || val === "sarif") format = val;
    } else if (a === "--format") {
      const val = argv[++i];
      if (val === "terminal" || val === "json" || val === "sarif") format = val;
    } else if (a.startsWith("--severity=")) {
      const val = a.slice("--severity=".length);
      if (val === "error" || val === "warn" || val === "info") severityFloor = val;
    } else if (a === "--severity") {
      const val = argv[++i];
      if (val === "error" || val === "warn" || val === "info") severityFloor = val;
    } else if (a.startsWith("--cwd=")) {
      cwd = resolve(a.slice("--cwd=".length));
    } else if (a === "--no-eslint") {
      eslint = false;
    } else if (a === "--eslint") {
      eslint = true;
    } else if (!a.startsWith("--")) {
      paths.push(a);
    }
  }
  return { paths, format, severityFloor, cwd, eslint };
}
