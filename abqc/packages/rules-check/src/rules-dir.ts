import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { resolveRulesDirFromModule } from "@abqc/core";

/** Monorepo rules library for @abqc/rules-check entrypoints. */
export function defaultRulesDir(): string {
  return resolveRulesDirFromModule(import.meta.url, 4);
}

/** @abqc/rules-check package root (contains package.json). */
export function rulesCheckPackageRoot(): string {
  return resolve(dirname(fileURLToPath(import.meta.url)), "..");
}
