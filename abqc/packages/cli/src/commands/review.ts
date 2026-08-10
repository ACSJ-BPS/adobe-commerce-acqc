import { Engine, severityRank, type Severity } from "@abqc/core";
import { formatTerminal } from "../formatters/terminal.js";
import { formatGithub } from "../formatters/github.js";

interface ReviewOptions {
  paths: string[];
  format: "terminal" | "json" | "sarif" | "github";
  severityFloor: Severity;
  /** When true, run LLM judgment layer (default: false — static ESLint only). */
  ai: boolean;
  /** Git ref to diff against (only review changed files). */
  diff: string | undefined;
  /** Run declarative mechanical checks (default: true). */
  mechanical: boolean;
  /** Run ESLint inside mechanical phase (default: true when mechanical is enabled). */
  mechanicalEslint: boolean;
}

export async function reviewCommand(argv: string[]): Promise<number> {
  const opts = parseArgs(argv);
  if (opts.paths.length === 0) {
    console.error("review: no paths provided");
    return 2;
  }

  const aiEnabled = opts.ai || !!process.env.ABQC_PROVIDER;
  const engine = new Engine({ staticOnly: !aiEnabled });
  const result = await engine.review({
    paths: opts.paths,
    diff: opts.diff,
    cwd: process.cwd(),
    mechanical: opts.mechanical,
    mechanicalEslint: opts.mechanicalEslint,
  });

  const filtered = result.findings.filter((f) => severityRank(f.severity) >= severityRank(opts.severityFloor));

  if (opts.format === "sarif") {
    process.stdout.write(JSON.stringify(result.sarif, null, 2) + "\n");
  } else if (opts.format === "json") {
    process.stdout.write(JSON.stringify(filtered, null, 2) + "\n");
  } else if (opts.format === "github") {
    process.stdout.write(formatGithub(filtered, result.sarif) + "\n");
  } else {
    process.stdout.write(formatTerminal(filtered, result.rulesLoaded) + "\n");
  }

  if (filtered.some((f) => f.severity === "error")) return 2;
  if (filtered.some((f) => f.severity === "warn")) return 1;
  return 0;
}

function parseArgs(argv: string[]): ReviewOptions {
  const paths: string[] = [];
  let format: ReviewOptions["format"] = "terminal";
  let severityFloor: Severity = "info";
  let ai = false;
  let diff: string | undefined;
  let mechanical = true;
  let mechanicalEslint = true;

  for (let i = 0; i < argv.length; i++) {
    const a = argv[i] as string;
    if (a === "--ai" || a === "--llm") {
      ai = true;
    } else if (a === "--no-mechanical") {
      mechanical = false;
    } else if (a === "--mechanical") {
      mechanical = true;
    } else if (a === "--no-eslint") {
      mechanicalEslint = false;
    } else if (a.startsWith("--format=")) {
      const val = a.slice("--format=".length);
      if (val === "terminal" || val === "json" || val === "sarif" || val === "github") format = val;
      else console.error(`review: unknown format "${val}" — using terminal`);
    } else if (a === "--format") {
      const val = argv[++i];
      if (val === "terminal" || val === "json" || val === "sarif" || val === "github") format = val;
    } else if (a.startsWith("--severity=")) {
      const val = a.slice("--severity=".length);
      if (val === "error" || val === "warn" || val === "info") severityFloor = val;
      else console.error(`review: unknown severity "${val}" — using info`);
    } else if (a === "--severity") {
      const val = argv[++i];
      if (val === "error" || val === "warn" || val === "info") severityFloor = val;
    } else if (a.startsWith("--diff=")) {
      diff = a.slice("--diff=".length);
    } else if (a === "--diff") {
      diff = argv[++i];
    } else if (a.startsWith("--provider=")) {
      process.env.ABQC_PROVIDER = a.slice("--provider=".length);
      ai = true;
    } else if (a === "--provider") {
      process.env.ABQC_PROVIDER = argv[++i];
      ai = true;
    } else if (a === "--static-only") {
      ai = false;
    } else if (!a.startsWith("--")) {
      paths.push(a);
    } else {
      console.error(`review: unknown flag "${a}" — ignored`);
    }
  }
  return { paths, format, severityFloor, ai, diff, mechanical, mechanicalEslint };
}
