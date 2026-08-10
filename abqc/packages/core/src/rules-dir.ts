import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

/** Env var that overrides the auto-resolved rules directory (for non-monorepo installs). */
const RULES_DIR_ENV_KEYS = ["ABQC_RULES_DIR", "MCP_ABQC_RULES_DIR"] as const;

/** Returns an explicit rules dir from env, or undefined when none is set. */
export function rulesDirFromEnv(): string | undefined {
  for (const key of RULES_DIR_ENV_KEYS) {
    const val = process.env[key]?.trim();
    if (val) return resolve(val);
  }
  return undefined;
}

/** Resolve the monorepo rules library from a module URL (typically import.meta.url). */
export function resolveRulesDirFromModule(moduleUrl: string, levelsUp: number): string {
  const fromEnv = rulesDirFromEnv();
  if (fromEnv) return fromEnv;
  const filePath = decodeURIComponent(
    fileURLToPath(moduleUrl).replace(/^\/([A-Za-z]:)/, "$1"),
  );
  return resolve(filePath, ...Array(levelsUp).fill(".."), "rules");
}

/** Default rules dir when called from @abqc/core/dist/*.js (four levels up to monorepo root). */
export function defaultRulesDir(): string {
  return resolveRulesDirFromModule(import.meta.url, 4);
}
