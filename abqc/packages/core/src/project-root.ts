import { realpath } from "node:fs/promises";
import { resolve } from "node:path";

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
