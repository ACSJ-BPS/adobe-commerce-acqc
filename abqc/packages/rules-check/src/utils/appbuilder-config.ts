import { parse } from "yaml";
import { dirname, resolve } from "node:path";

export type YamlAction = Record<string, unknown>;

export function parseYamlSafe(content: string): unknown {
  try {
    return parse(content);
  } catch {
    return undefined;
  }
}

/**
 * Find every action-like object in a parsed App Builder config.
 * An action is any mapping that has a string `function` property. This works for
 * both `app.config.yaml` (application.runtimeManifest.packages.<pkg>.actions.<name>)
 * and `$include`-d action config files where action names are top-level keys.
 */
export function findActionObjects(doc: unknown): YamlAction[] {
  const out: YamlAction[] = [];
  const seen = new Set<object>();
  const visit = (node: unknown): void => {
    if (!node || typeof node !== "object") return;
    if (seen.has(node)) return;
    seen.add(node);
    if (Array.isArray(node)) {
      for (const item of node) visit(item);
      return;
    }
    const obj = node as Record<string, unknown>;
    if (typeof obj.function === "string") {
      out.push(obj);
      // still descend for nested packages, but action objects rarely nest actions
    }
    for (const val of Object.values(obj)) visit(val);
  };
  visit(doc);
  return out;
}

/**
 * Resolve `$include:` directives (string values, anywhere in the doc) to absolute
 * config paths, relative to the including file. App Builder apps frequently split
 * their runtimeManifest / actions into included config files, so entry-point
 * resolution must follow these to see every action's `function:` file.
 */
export function resolveIncludes(content: string, configAbsPath: string): string[] {
  const doc = parseYamlSafe(content);
  if (doc === undefined) return [];
  const baseDir = dirname(configAbsPath);
  const out: string[] = [];
  const seen = new Set<object>();
  const visit = (node: unknown): void => {
    if (!node || typeof node !== "object") return;
    if (seen.has(node)) return;
    seen.add(node);
    if (Array.isArray(node)) {
      for (const item of node) visit(item);
      return;
    }
    for (const [key, val] of Object.entries(node as Record<string, unknown>)) {
      if (key === "$include" && typeof val === "string" && val.trim()) {
        out.push(resolve(baseDir, val));
      } else {
        visit(val);
      }
    }
  };
  visit(doc);
  return out;
}

/** Resolve `function:` entry file paths (relative to the config file) to absolute paths. */
export function resolveActionEntryFiles(content: string, configAbsPath: string): string[] {
  const doc = parseYamlSafe(content);
  if (doc === undefined) return [];
  const baseDir = dirname(configAbsPath);
  const files: string[] = [];
  for (const action of findActionObjects(doc)) {
    const fn = action.function;
    if (typeof fn === "string" && fn.trim()) {
      files.push(resolve(baseDir, fn));
    }
  }
  return files;
}

/** Memory limit for an action, honoring both `memory` and `memorySize`. */
export function actionMemory(action: YamlAction): number | undefined {
  const limits = action.limits as Record<string, unknown> | undefined;
  const mem = limits?.memory ?? limits?.memorySize;
  return typeof mem === "number" ? mem : undefined;
}

/** Whether an action is marked final (at action root or under annotations). */
export function actionIsFinal(action: YamlAction): boolean {
  if (action.final === true || action.final === "true") return true;
  const annotations = action.annotations as Record<string, unknown> | undefined;
  return annotations?.final === true || annotations?.final === "true";
}

/**
 * The `require-adobe-auth` setting for an action (root or under annotations):
 * `true`/`false` when declared, or `undefined` when the annotation is omitted
 * entirely — the three states are distinct (omitted ≠ false).
 */
export function actionRequireAdobeAuth(action: YamlAction): boolean | undefined {
  const annotations = action.annotations as Record<string, unknown> | undefined;
  const raw = action["require-adobe-auth"] ?? annotations?.["require-adobe-auth"];
  if (raw === true || raw === "true") return true;
  if (raw === false || raw === "false") return false;
  return undefined;
}

/** The `allowedHosts` list for an action (root or under annotations), or undefined when unset. */
export function actionAllowedHosts(action: YamlAction): unknown[] | undefined {
  const annotations = action.annotations as Record<string, unknown> | undefined;
  const raw = action.allowedHosts ?? annotations?.allowedHosts;
  return Array.isArray(raw) ? raw : undefined;
}
