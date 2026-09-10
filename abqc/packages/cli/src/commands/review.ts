import { existsSync, readdirSync, statSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { Engine, severityRank, type Severity } from "@abqc/core";
import { formatTerminal } from "../formatters/terminal.js";
import { formatGithub } from "../formatters/github.js";

const PROJECT_ROOT_MARKERS = ["package.json", "app.config.yaml", "app.config.yml", ".git"];

// Directories that never themselves indicate a single project's boundary,
// even when they contain a `package.json` somewhere below — skipped while
// probing for sibling projects below.
const CONTAINER_SCAN_SKIP_DIRS = new Set(["node_modules", ".git", "dist", "build", "coverage"]);
const CONTAINER_SCAN_MAX_DEPTH = 3;
const CONTAINER_SCAN_MIN_PROJECTS = 2;

/**
 * True when `dir` itself has no project markers but contains 2+ independent
 * projects below it (each with their own `package.json`) — a directory that
 * bundles multiple unrelated sample apps (e.g. a downloaded "quickstarts"
 * archive with no top-level package.json/.git), rather than a subdirectory
 * that's part of ONE project whose root lives further up the tree. Walking
 * up past a directory like this is how `findProjectRootFromPaths` used to
 * escape into an ancestor's unrelated package.json/.git (e.g. the abqc tool's
 * own workspace root sitting a couple of levels above a sample fixture) —
 * treat `dir` as the boundary instead of climbing past it.
 */
function containsMultipleProjects(dir: string): boolean {
  let found = 0;
  const visit = (d: string, depth: number): void => {
    if (found >= CONTAINER_SCAN_MIN_PROJECTS || depth > CONTAINER_SCAN_MAX_DEPTH) return;
    let entries;
    try {
      entries = readdirSync(d, { withFileTypes: true });
    } catch {
      return;
    }
    for (const e of entries) {
      if (found >= CONTAINER_SCAN_MIN_PROJECTS) return;
      if (!e.isDirectory() || e.name.startsWith(".") || CONTAINER_SCAN_SKIP_DIRS.has(e.name)) continue;
      const sub = join(d, e.name);
      if (existsSync(join(sub, "package.json"))) {
        found++;
      } else {
        visit(sub, depth + 1);
      }
    }
  };
  visit(dir, 0);
  return found >= CONTAINER_SCAN_MIN_PROJECTS;
}

/**
 * Repo-scoped checks (package.json, .gitignore, docs/*, git history, ...) need a
 * project root, not the directory the CLI process happens to be invoked from —
 * `abqc review /some/repo/actions` must still resolve to `/some/repo`, not
 * `process.cwd()` (which is wherever the operator ran the command, often the
 * abqc tool's own directory). Walk up from the first scanned path looking for a
 * project-root marker; fall back to that path itself if none is found.
 */
export function findProjectRootFromPaths(paths: string[]): string {
  if (paths.length === 0) return process.cwd();
  const first = resolve(process.cwd(), paths[0] as string);
  let dir: string;
  try {
    dir = statSync(first).isDirectory() ? first : dirname(first);
  } catch {
    dir = first;
  }
  const start = dir;
  // The scanned directory has no marker of its own but bundles several
  // independent projects below it — it IS the boundary; don't climb past it
  // into whatever unrelated ancestor project happens to sit further up.
  if (containsMultipleProjects(start)) return start;
  for (let i = 0; i < 50; i++) {
    if (PROJECT_ROOT_MARKERS.some((marker) => existsSync(join(dir, marker)))) return dir;
    const parent = dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }
  return start;
}

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
    cwd: findProjectRootFromPaths(opts.paths),
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
