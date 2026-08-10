import { RuleLoader, type Rule } from "@abqc/core";
import { parseCheckSpec } from "./checkers/spec.js";
import { knownCheckTypes } from "./checkers/type-registry.js";
import { allBuiltinIds, isRegisteredBuiltin } from "./checkers/type-checkers/builtin.js";
import { validateEslintMap } from "./eslint-map.js";

export function validateRuleChecks(rules: Rule[]): void {
  const errors: string[] = [];
  const knownTypes = new Set(knownCheckTypes());

  for (const rule of rules) {
    if (!rule.checkSpec?.trim()) continue;
    try {
      const check = parseCheckSpec(rule.checkSpec);
      if (!check) continue;
      if (!knownTypes.has(check.type)) {
        errors.push(`${rule.id}: unknown check type "${check.type}"`);
      }
      if (check.type === "builtin") {
        const id = (check as { id: string }).id;
        if (!isRegisteredBuiltin(id)) {
          errors.push(`${rule.id}: unknown builtin id "${id}" (known: ${allBuiltinIds().join(", ")})`);
        }
      }
      if (check.type === "regex") {
        // A pattern that fails to compile previously passed validation cleanly
        // (parseCheckSpec only checks it's a non-empty string) and then
        // silently produced ZERO findings forever at review time (regex.ts
        // swallows the RegExp() throw and returns []) — a coverage hole with
        // no signal anywhere. Catch it here, at the one place meant to
        // guarantee every ### Check spec is actually usable.
        const pattern = (check as { pattern: string }).pattern;
        try {
          new RegExp(pattern, "g");
        } catch (e) {
          errors.push(`${rule.id}: invalid regex pattern "${pattern}" — ${e instanceof Error ? e.message : e}`);
        }
      }
    } catch (e) {
      errors.push(`${rule.id}: ${e instanceof Error ? e.message : e}`);
    }
  }

  if (errors.length > 0) {
    throw new Error(
      `[abqc-rules-check] invalid ### Check spec(s):\n  ${errors.join("\n  ")}`,
    );
  }
}

export async function loadRulesAndValidate(rulesDir: string): Promise<{ loader: RuleLoader; rules: Rule[] }> {
  const loader = new RuleLoader(rulesDir);
  const rules = await loader.load();
  validateRuleChecks(rules);
  validateEslintMap(rules);
  return { loader, rules };
}

export function automatableRuleIds(rules: Rule[]): string[] {
  return rules
    .filter((r) => {
      if (!r.checkSpec?.trim()) return false;
      try {
        const c = parseCheckSpec(r.checkSpec);
        return c !== null;
      } catch {
        return false;
      }
    })
    .map((r) => r.id);
}
