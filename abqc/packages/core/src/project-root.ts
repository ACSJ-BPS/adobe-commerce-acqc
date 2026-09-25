import { existsSync, readdirSync, statSync } from "node:fs";
import { realpath } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";

/** Env vars that override process.cwd() for MCP / CI hosts that spawn from a non-project directory. */
const PROJECT_ROOT_ENV_KEYS = ["ABQC_PROJECT_ROOT", "MCP_ABQC_PROJECT_ROOT"] as const;

/** Project root for path scoping — prefers ABQC_PROJECT_ROOT / MCP_ABQC_PROJECT_ROOT, else cwd. */
export function resolveProjectRoot(fallbackCwd = process.cwd()): string {
  for (const key of PROJECT_ROOT_ENV_KEYS) {
    const val = process.env[key]?.trim();
    if (val) return resolve(val);
  }
  return fallbackCwd;
}

/** Same as resolveProjectRoot but resolves symlinks for consistent prefix checks. */
export async function resolveProjectRootReal(fallbackCwd = process.cwd()): Promise<string> {
  const root = resolveProjectRoot(fallbackCwd);
  try {
    return await realpath(root);
  } catch {
    return root;
  }
}

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
 * `abqc review /some/repo/actions` (and `abqc-rules-check check /some/repo/actions`)
 * must still resolve to `/some/repo`, not `process.cwd()` (which is wherever the
 * operator ran the command, often the abqc tool's own directory). Walk up from
 * the first scanned path looking for a project-root marker; fall back to that
 * path itself if none is found.
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
