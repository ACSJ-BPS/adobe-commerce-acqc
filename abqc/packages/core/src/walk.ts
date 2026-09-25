import { join } from "node:path";
import { readdir, stat } from "node:fs/promises";

export const DEFAULT_SKIP_DIRS = new Set([
  "node_modules", "dist", "build", "coverage", ".turbo", ".next", "out",
]);

export const ALLOWED_DOT_DIRS = new Set([".github"]);

export const MAX_WALK_FILES = 2_000;

export function isGeneratedFile(name: string): boolean {
  return (
    name.endsWith(".min.js") ||
    name.endsWith(".min.css") ||
    name.endsWith(".d.ts") ||
    name.endsWith(".js.map") ||
    name.endsWith(".css.map") ||
    name === "package-lock.json" ||
    name === "yarn.lock" ||
    name === "pnpm-lock.yaml"
  );
}

// Filenames/patterns that must never be walked into a review — a full-tree
// `abqc review --ai` would otherwise read these and ship their contents to
// whatever LLM provider is configured. Single source of truth: the MCP server
// (packages/mcp) reuses this instead of keeping its own copy.
export const SENSITIVE_FILENAME_RE =
  /(?:^|[/\\])(?:\.env(?:\.[^/\\]*)?|\.aio|.*\.key|.*\.pem|.*\.p12|.*\.pfx|.*secret.*|.*credential.*|.*password.*|package-lock\.json|yarn\.lock|pnpm-lock\.yaml)$/i;

// Binary/non-text extensions — never meaningfully "reviewable" as source, and
// (same risk as above) must never be read and forwarded to an LLM provider.
const BINARY_EXTENSIONS = new Set([
  ".png", ".jpg", ".jpeg", ".gif", ".bmp", ".ico", ".webp", ".svg",
  ".pdf", ".zip", ".gz", ".tgz", ".tar", ".rar", ".7z",
  ".woff", ".woff2", ".ttf", ".eot", ".otf",
  ".mp3", ".mp4", ".mov", ".avi", ".webm",
  ".wasm", ".node", ".exe", ".dll", ".so", ".dylib",
  ".db", ".sqlite", ".sqlite3",
]);

export function isBinaryLikeFile(name: string): boolean {
  const dot = name.lastIndexOf(".");
  if (dot === -1) return false;
  return BINARY_EXTENSIONS.has(name.slice(dot).toLowerCase());
}

/** Never walk a file that's sensitive-by-name or a known binary type. */
export function isUnreviewableFile(name: string): boolean {
  return SENSITIVE_FILENAME_RE.test(name) || isBinaryLikeFile(name);
}

export interface WalkOptions {
  skipDirs?: Set<string>;
  cap?: number;
  logPrefix?: string;
}

export async function expandPaths(
  paths: string[],
  cwd: string,
  options: WalkOptions = {},
): Promise<string[]> {
  const { resolve, basename } = await import("node:path");
  const skipDirs = options.skipDirs ?? DEFAULT_SKIP_DIRS;
  const out = new Set<string>();
  for (const p of paths) {
    const abs = resolve(cwd, p);
    try {
      const s = await stat(abs);
      if (s.isFile()) {
        // Refuse even an EXPLICITLY named sensitive/binary file — an MCP caller
        // (possibly prompt-injected) can pass a path directly, bypassing the
        // directory-walk filter below entirely.
        if (isUnreviewableFile(basename(abs))) {
          process.stderr.write(
            `${options.logPrefix ?? "[abqc]"} refusing to review sensitive/binary file: ${abs}\n`,
          );
          continue;
        }
        out.add(abs);
      } else if (s.isDirectory()) {
        for (const f of await walkDirectory(abs, skipDirs, options.cap, options.logPrefix)) {
          out.add(f);
        }
      }
    } catch {
      // skip missing paths
    }
  }
  return Array.from(out);
}

export async function walkDirectory(
  dir: string,
  skipDirs: Set<string> = DEFAULT_SKIP_DIRS,
  cap = MAX_WALK_FILES,
  logPrefix = "[abqc]",
): Promise<string[]> {
  const out: string[] = [];
  let warned = false;
  // Breadth-first, not depth-first: a single alphabetically-early subtree
  // (e.g. a vendored/unrelated directory with tens of thousands of files)
  // must not exhaust the whole `cap` before sibling directories are ever
  // visited. A FIFO queue naturally processes the tree level-by-level, so
  // every directory gets a shallow pass before any one of them is walked
  // deeply — depth-first recursion gave earlier siblings no such guarantee.
  const queue: string[] = [dir];
  while (queue.length > 0 && out.length < cap) {
    const current = queue.shift() as string;
    let entries;
    try {
      entries = await readdir(current, { withFileTypes: true });
    } catch {
      continue;
    }
    // readdir's order is filesystem-dependent (not alphabetical on every OS/FS) —
    // sorting makes which files survive the `cap` truncation below deterministic
    // and reproducible across machines/CI runners, instead of varying by
    // directory-entry order on disk.
    entries = entries.slice().sort((a, b) => a.name.localeCompare(b.name));
    for (const e of entries) {
      const isDotDir = e.isDirectory() && e.name.startsWith(".");
      if ((isDotDir && !ALLOWED_DOT_DIRS.has(e.name)) || skipDirs.has(e.name)) continue;
      const full = join(current, e.name);
      if (e.isDirectory()) {
        queue.push(full);
      } else if (e.isFile() && !isGeneratedFile(e.name) && !isUnreviewableFile(e.name)) {
        if (out.length >= cap) {
          if (!warned) {
            process.stderr.write(
              `${logPrefix} walk capped at ${cap} files — pass specific paths to review more\n`,
            );
            warned = true;
          }
          break;
        }
        out.push(full);
      }
    }
  }
  return out;
}

export function isJsLike(ext: string): boolean {
  return [".js", ".mjs", ".cjs", ".ts", ".tsx", ".jsx"].includes(ext);
}

export function isYamlConfig(filePath: string): boolean {
  const p = filePath.replace(/\\/g, "/");
  return (
    /(^|\/)app\.config\.yaml$/.test(p) ||
    /(^|\/)ext\.config\.yaml$/.test(p) ||
    /(^|\/)install\.yaml$/.test(p) ||
    /(^|\/)[^/]*actions?\.config\.ya?ml$/.test(p) ||
    /(^|\/)manifest\.ya?ml$/.test(p)
  );
}

export function isActionFile(filePath: string): boolean {
  return /(^|\/)actions\/.+\.(js|ts|mjs|cjs)$/.test(filePath.replace(/\\/g, "/"));
}

export function isSourceFile(filePath: string): boolean {
  return /\.(js|ts|jsx|tsx|mjs|cjs)$/.test(filePath);
}
