import { parse as parseYaml } from "yaml";

export type CheckType = "regex" | "yaml-path" | "builtin";

export interface BaseRuleCheck {
  type: CheckType;
  /** Glob patterns relative to scan root; omit = all lintable files for file scope. */
  files?: string[];
  /** `file` (default) or `repo` (run once per scan). */
  scope?: "file" | "repo";
  message?: string;
}

export interface RegexRuleCheck extends BaseRuleCheck {
  type: "regex";
  pattern: string;
  ignore?: string[];
  strip?: Array<"strings" | "comments">;
}

export type YamlPathCondition =
  | "web-timeout"
  | "memory-tier"
  | "final"
  | "relative-path"
  | "include-large"
  | "require-auth"
  | "allowed-hosts"
  | "concurrency"
  | "runtime-version";

export interface YamlPathRuleCheck extends BaseRuleCheck {
  type: "yaml-path";
  condition: YamlPathCondition;
  /** Max lines before $include required (include-large only). */
  maxLines?: number;
}

export interface BuiltinRuleCheck extends BaseRuleCheck {
  type: "builtin";
  id: string;
}

export type RuleCheck = RegexRuleCheck | YamlPathRuleCheck | BuiltinRuleCheck;

const VALID_TYPES = new Set<CheckType>(["regex", "yaml-path", "builtin"]);

export function parseCheckSpec(raw: string | undefined): RuleCheck | null {
  if (!raw?.trim()) return null;
  let doc: unknown;
  try {
    doc = parseYaml(raw);
  } catch (e) {
    throw new Error(`invalid check YAML: ${e instanceof Error ? e.message : e}`);
  }
  if (!doc || typeof doc !== "object") {
    throw new Error("check spec must be a YAML mapping");
  }
  const obj = doc as Record<string, unknown>;
  const type = obj.type;
  if (typeof type !== "string" || !VALID_TYPES.has(type as CheckType)) {
    throw new Error(`check spec missing or invalid type: ${String(type)}`);
  }

  const base: BaseRuleCheck = {
    type: type as CheckType,
    files: normalizeFiles(obj.files),
    scope: obj.scope === "repo" ? "repo" : "file",
    message: typeof obj.message === "string" ? obj.message : undefined,
  };

  if (type === "regex") {
    if (typeof obj.pattern !== "string" || !obj.pattern) {
      throw new Error("regex check requires pattern");
    }
    return {
      ...base,
      type: "regex",
      pattern: obj.pattern,
      ignore: normalizeStringArray(obj.ignore),
      strip: normalizeStrip(obj.strip),
    };
  }

  if (type === "yaml-path") {
    const condition = obj.condition;
    const valid: YamlPathCondition[] = [
      "web-timeout", "memory-tier", "final", "relative-path", "include-large",
      "require-auth", "allowed-hosts", "concurrency", "runtime-version",
    ];
    if (typeof condition !== "string" || !valid.includes(condition as YamlPathCondition)) {
      throw new Error(`yaml-path check requires condition: ${valid.join("|")}`);
    }
    return {
      ...base,
      type: "yaml-path",
      condition: condition as YamlPathCondition,
      maxLines: typeof obj.maxLines === "number" ? obj.maxLines : undefined,
    };
  }

  if (type === "builtin") {
    if (typeof obj.id !== "string" || !obj.id) {
      throw new Error("builtin check requires id");
    }
    return { ...base, type: "builtin", id: obj.id };
  }

  throw new Error(`unsupported check type: ${type}`);
}

function normalizeFiles(v: unknown): string[] | undefined {
  if (!v) return undefined;
  if (typeof v === "string") return [v];
  if (Array.isArray(v)) return v.filter((x): x is string => typeof x === "string");
  return undefined;
}

function normalizeStringArray(v: unknown): string[] | undefined {
  if (!v) return undefined;
  if (typeof v === "string") return [v];
  if (Array.isArray(v)) return v.filter((x): x is string => typeof x === "string");
  return undefined;
}

function normalizeStrip(v: unknown): Array<"strings" | "comments"> | undefined {
  if (!Array.isArray(v)) return undefined;
  return v.filter((x): x is "strings" | "comments" => x === "strings" || x === "comments");
}

/** Simple glob match: **, *, {a,b} extensions. */
export function matchesFileGlob(filePath: string, patterns: string[] | undefined): boolean {
  if (!patterns?.length) return true;
  const norm = filePath.replace(/\\/g, "/");
  for (const pat of patterns) {
    if (globMatch(norm, pat.replace(/\\/g, "/"))) return true;
  }
  return false;
}

function globMatch(path: string, pattern: string): boolean {
  const re = globToRegExp(pattern);
  return re.test(path) || re.test(path.split("/").pop() ?? path);
}

function globToRegExp(glob: string): RegExp {
  let re = "^";
  for (let i = 0; i < glob.length; i++) {
    const c = glob[i]!;
    if (c === "*") {
      if (glob[i + 1] === "*") {
        re += ".*";
        i++;
        if (glob[i + 1] === "/") i++;
      } else {
        re += "[^/]*";
      }
    } else if (c === "?") {
      re += ".";
    } else if (c === "{") {
      const end = glob.indexOf("}", i);
      if (end > i) {
        re += `(?:${glob.slice(i + 1, end).split(",").map(escapeRe).join("|")})`;
        i = end;
      } else {
        re += escapeRe(c);
      }
    } else {
      re += escapeRe(c);
    }
  }
  re += "$";
  return new RegExp(re, "i");
}

function escapeRe(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

