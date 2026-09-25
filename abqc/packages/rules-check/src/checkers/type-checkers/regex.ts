import type { Rule } from "@abqc/core";
import type { CheckHit, CheckContext } from "../../types.js";
import type { RegexRuleCheck, RuleCheck } from "../spec.js";
import { matchesFileGlob } from "../spec.js";

export function runRegexCheck(rule: Rule, spec: RuleCheck, ctx: CheckContext): CheckHit[] {
  const s = spec as RegexRuleCheck;
  if (!matchesFileGlob(ctx.filePath, s.files)) return [];

  let re: RegExp;
  try {
    re = new RegExp(s.pattern, "g");
  } catch (e) {
    // Warn instead of a bare silent []: an invalid pattern otherwise checks
    // NOTHING, forever, with zero signal that this rule's mechanical coverage
    // is dead — `abqc rules validate` also catches this ahead of time (see
    // registry.ts), but a review run directly should not be silent either.
    process.stderr.write(
      `[abqc-rules-check] ${rule.id}: invalid regex pattern "${s.pattern}" — ${e instanceof Error ? e.message : e}. Skipping this check.\n`,
    );
    return [];
  }

  const hits: CheckHit[] = [];
  const lines = ctx.content.split("\n");
  for (let i = 0; i < lines.length; i++) {
    let line = lines[i] ?? "";
    if (s.strip?.includes("comments")) {
      line = stripLineComments(line);
    }
    if (s.strip?.includes("strings")) {
      line = stripStrings(line);
    }
    if (s.ignore?.some((ign) => line.includes(ign))) continue;

    re.lastIndex = 0;
    if (re.test(line)) {
      hits.push({
        line: i + 1,
        message: s.message ?? rule.title,
      });
    }
  }
  return hits;
}

function stripLineComments(line: string): string {
  const idx = line.indexOf("//");
  if (idx < 0) return line;
  const before = line.slice(0, idx);
  if ((before.match(/'/g)?.length ?? 0) % 2 === 1) return line;
  if ((before.match(/"/g)?.length ?? 0) % 2 === 1) return line;
  return before;
}

function stripStrings(line: string): string {
  return line
    .replace(/'(?:\\.|[^'\\])*'/g, "''")
    .replace(/"(?:\\.|[^"\\])*"/g, '""')
    .replace(/`(?:\\.|[^`\\])*`/g, "``");
}
