import type { Rule } from "@abqc/core";
import type { CheckHit, CheckContext } from "../../types.js";
import type { RuleCheck, YamlPathRuleCheck } from "../spec.js";
import { matchesFileGlob } from "../spec.js";
import { isYamlConfig } from "../../walk.js";
import {
  actionAllowedHosts,
  actionIsFinal,
  actionMemory,
  actionRequireAdobeAuth,
  findActionObjects,
  parseYamlSafe,
} from "../../utils/appbuilder-config.js";

const PREWARMED_MEMORY = new Set([256, 512, 1024]);
const DEFAULT_LARGE_LINES = 120;
// Node runtimes at or below this major are end-of-life on Adobe I/O Runtime.
// Single source of truth for the runtime-version condition — bump when the
// EOL floor moves (e.g. once nodejs:20 reaches end-of-life).
const EOL_NODE_MAJOR = 18;

export function runYamlPathCheck(rule: Rule, spec: RuleCheck, ctx: CheckContext): CheckHit[] {
  const s = spec as YamlPathRuleCheck;
  if (!isYamlConfig(ctx.filePath) && !matchesFileGlob(ctx.filePath, s.files)) return [];
  if (s.files && !matchesFileGlob(ctx.filePath, s.files)) return [];

  if (s.condition === "include-large") {
    return checkIncludeLarge(ctx, s, rule);
  }

  const doc = parseYamlSafe(ctx.content);
  if (doc === undefined) return [];

  const hits: CheckHit[] = [];
  for (const action of findActionObjects(doc)) {
    const lineHint = lineForFunction(ctx.content, action.function);
    const web = action.web;
    const isWeb = web === "yes" || web === true;
    const name = String(action.function ?? "action");
    switch (s.condition) {
      case "web-timeout": {
        const limits = action.limits as Record<string, unknown> | undefined;
        const timeout = coerceNumber(limits?.timeout);
        if (isWeb && timeout !== undefined && timeout > 60000) {
          hits.push({
            line: lineHint,
            message: s.message ?? `Web action timeout ${timeout}ms exceeds 60s blocking cap`,
          });
        }
        break;
      }
      case "memory-tier": {
        const memory = actionMemory(action) ?? coerceNumber((action.limits as Record<string, unknown>)?.memorySize);
        if (memory !== undefined && !PREWARMED_MEMORY.has(memory)) {
          hits.push({
            line: lineHint,
            message: s.message ?? `memory: ${memory} is not a prewarmed tier — use 256, 512, or 1024 MB`,
          });
        }
        break;
      }
      case "final": {
        if (!actionIsFinal(action)) {
          hits.push({
            line: lineHint,
            message: s.message ?? `Action '${name}' must set final: true (under annotations) to prevent sequence injection`,
          });
        }
        break;
      }
      case "relative-path": {
        const fn = action.function;
        if (typeof fn === "string" && (fn.startsWith("/") || /^[A-Za-z]:\\/.test(fn))) {
          hits.push({
            line: lineHint,
            message: s.message ?? `Action function path must be relative, not absolute: ${fn}`,
          });
        }
        break;
      }
      case "require-auth": {
        // Web action with the require-adobe-auth annotation omitted entirely
        // (distinct from an explicit `false`, which is a separate documented-review rule).
        if (isWeb && actionRequireAdobeAuth(action) === undefined) {
          hits.push({
            line: lineHint,
            message: s.message ?? `Web action '${name}' does not declare require-adobe-auth — set it explicitly (true to require an IMS token; false only for an intentional public endpoint)`,
          });
        }
        break;
      }
      case "allowed-hosts": {
        // Public web action (require-adobe-auth: false) with no allowedHosts restriction.
        if (isWeb && actionRequireAdobeAuth(action) === false && !actionAllowedHosts(action)?.length) {
          hits.push({
            line: lineHint,
            message: s.message ?? `Public web action '${name}' (require-adobe-auth: false) has no allowedHosts — restrict which hosts may invoke it`,
          });
        }
        break;
      }
      case "concurrency": {
        const concurrency = coerceNumber((action.limits as Record<string, unknown> | undefined)?.concurrency);
        if (concurrency === 1) {
          hits.push({
            line: lineHint,
            message: s.message ?? `Action '${name}' sets concurrency: 1 — forces a new container per request (cold start every time); raise it unless single-flight is required`,
          });
        }
        break;
      }
      case "runtime-version": {
        const runtime = action.runtime;
        if (typeof runtime === "string") {
          const m = /^nodejs:(\d+)/.exec(runtime.trim());
          if (m && Number(m[1]) <= EOL_NODE_MAJOR) {
            hits.push({
              line: lineHint,
              message: s.message ?? `Action '${name}' uses ${runtime} — nodejs:${EOL_NODE_MAJOR} and older are end-of-life; upgrade to a supported Node runtime`,
            });
          }
        }
        break;
      }
    }
  }
  return hits;
}

function checkIncludeLarge(ctx: CheckContext, s: YamlPathRuleCheck, rule: Rule): CheckHit[] {
  if (!/(^|\/)app\.config\.yaml$/.test(ctx.filePath.replace(/\\/g, "/"))) return [];
  const max = s.maxLines ?? DEFAULT_LARGE_LINES;
  const lineCount = ctx.content.split("\n").length;
  if (lineCount <= max) return [];
  if (/\$include\s*:/.test(ctx.content) || /\$include\b/.test(ctx.content)) return [];
  return [{
    line: 1,
    message: s.message ?? `app.config.yaml has ${lineCount} lines — use $include to split large configs`,
  }];
}

function lineForFunction(content: string, fnPath: unknown): number {
  if (typeof fnPath !== "string") return 1;
  const idx = content.indexOf(fnPath);
  if (idx < 0) return 1;
  return content.slice(0, idx).split("\n").length;
}

function coerceNumber(v: unknown): number | undefined {
  if (typeof v === "number" && !Number.isNaN(v)) return v;
  if (typeof v === "string" && /^\d+$/.test(v.trim())) return Number(v.trim());
  return undefined;
}
