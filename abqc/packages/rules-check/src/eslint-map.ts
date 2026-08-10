import type { Rule } from "@abqc/core";

/**
 * Maps ESLint rule ids (from @abqc/core EslintRunner) to library rule ids.
 *
 * Every value MUST be a real rule id present in the rule library. This is enforced
 * at startup by `validateEslintMap` (called from self-test) and by a unit test, so
 * the map can never silently drift out of sync with the rule slugs.
 */
export const ESLINT_TO_LIBRARY: Record<string, string> = {
  eqeqeq: "node/correctness/strict-equality-only-eqeqeq",
  "n/no-deprecated-api": "node/bug-classes/buffer-alloc-not-new-buffer",
  "no-unused-vars": "node/correctness/no-unused-variables-imports-or-parameters",
  "prefer-const": "node/correctness/prefer-const-no-var",
  "no-var": "node/correctness/prefer-const-no-var",
  "no-eval": "node/bug-classes/no-eval-new-function-or-vm-runinthiscontext",
  "no-implied-eval": "node/bug-classes/no-eval-new-function-or-vm-runinthiscontext",
  "no-new-func": "node/bug-classes/no-eval-new-function-or-vm-runinthiscontext",
  "no-unreachable": "node/correctness/no-unreachable-code-no-duplicate-object-keys",
  "no-dupe-keys": "node/correctness/no-unreachable-code-no-duplicate-object-keys",
  "radix": "stylistic/numbers/parseint-requires-a-radix",
  "import/no-duplicates": "imports/discipline/no-duplicate-imports-merge-them",
  "import/no-self-import": "imports/discipline/file-can-t-import-itself-no-self-import",
  "security/detect-eval-with-expression": "node/bug-classes/no-eval-new-function-or-vm-runinthiscontext",
  "security/detect-non-literal-regexp": "security/eslint-security/no-dynamic-regex-construction-without-anchoring",
  "security/detect-non-literal-require": "security/eslint-security/no-dynamic-require-non-literal-require",
  "security/detect-possible-timing-attacks": "security/eslint-security/use-crypto-timingsafeequal-for-token-hmac-comparison",
  "security/detect-unsafe-regex": "node/bug-classes/validate-regex-for-redos-no-nested-quantifiers-on-user-input",
  "security/detect-object-injection": "security/eslint-security/no-bracket-access-on-user-controlled-keys-object-injection",
  "security/detect-non-literal-fs-filename": "security/eslint-security/no-dynamic-fs-paths-from-user-input-non-literal-fs-filename",
  "security/detect-pseudoRandomBytes": "security/eslint-security/no-pseudorandombytes-for-security-sensitive-values",
  "n/no-process-exit": "node/errors/don-t-process-exit-let-the-runtime-finish-gracefully",
  "n/no-callback-literal": "node/errors/error-first-callback-functions-only-invoke-with-an-error-or-null",
  "@typescript-eslint/no-explicit-any": "typescript/type-safety/no-explicit-any",
  "@typescript-eslint/no-non-null-assertion": "typescript/type-safety/avoid-non-null-assertion",
  "@typescript-eslint/consistent-type-imports": "typescript/style/type-only-imports-use-the-type-modifier-consistent-type-imports",
  "react/jsx-key": "react/components/don-t-use-array-index-as-key-when-items-can-reorder-insert",
  "react-hooks/rules-of-hooks": "react/hooks/hooks-rules-only-at-top-level-only-from-react-functions",
  "react-hooks/exhaustive-deps": "react/hooks/exhaustive-deps-every-used-value-goes-in-the-dep-array",
  "jsx-a11y/alt-text": "a11y/content/every-img-has-a-meaningful-alt-empty-alt-for-decorative",
};

export function mapEslintRuleId(eslintRuleId: string): string | undefined {
  return ESLINT_TO_LIBRARY[eslintRuleId];
}

/**
 * ESLint rules that are a COMPLETE implementation of their library rule — a
 * clean run (rule active, no finding) definitively means the library rule is
 * satisfied. ONLY these may be auto-passed + locked out of judgment when they
 * don't fire.
 *
 * The excluded mappings are deliberately narrow heuristics whose library rule is
 * broader with no declarative `### Check` backing (e.g. detect-possible-timing-
 * attacks only fires on secret-ish identifier names; the no-eval family misses
 * `vm.runInThisContext`; detect-unsafe-regex is literal-regex only). Auto-passing
 * those on a clean run would silently drop real security rules from semantic
 * review — so they stay in the agent's judgment set (a fired finding is still
 * auto-seeded as a violation via result.findings; only the clean-pass lock is
 * gated here). When in doubt, leave a mapping OUT — worst case is a rule the
 * agent judges anyway, never a coverage hole.
 */
export const FAITHFUL_ESLINT_RULES: Set<string> = new Set([
  "radix",
  "eqeqeq",
  "no-unused-vars",
  "prefer-const",
  "no-var",
  "no-unreachable",
  "no-dupe-keys",
  "import/no-duplicates",
  "import/no-self-import",
  "n/no-process-exit",
  "@typescript-eslint/no-explicit-any",
  "@typescript-eslint/no-non-null-assertion",
  "@typescript-eslint/consistent-type-imports",
  "react-hooks/rules-of-hooks",
  "react-hooks/exhaustive-deps",
  "jsx-a11y/alt-text",
]);

/** True if a clean run of this ESLint rule can be trusted to lock the mapped library rule. */
export function isFaithfulEslintRule(eslintRuleId: string): boolean {
  return FAITHFUL_ESLINT_RULES.has(eslintRuleId);
}

export function eslintCoveredLibraryIds(): Set<string> {
  return new Set(Object.values(ESLINT_TO_LIBRARY));
}

export function eslintRuleIdsForLibrary(libraryId: string): string[] {
  return Object.entries(ESLINT_TO_LIBRARY)
    .filter(([, lib]) => lib === libraryId)
    .map(([eslint]) => eslint);
}

/**
 * Verify every ESLINT_TO_LIBRARY target exists in the loaded rule library.
 * Returns the list of ESLint rule ids whose library target is missing (empty = all valid).
 */
export function findBrokenEslintMappings(rules: Rule[]): Array<{ eslintId: string; libraryId: string }> {
  const ids = new Set(rules.map((r) => r.id));
  return Object.entries(ESLINT_TO_LIBRARY)
    .filter(([, libraryId]) => !ids.has(libraryId))
    .map(([eslintId, libraryId]) => ({ eslintId, libraryId }));
}

/** Throw if any ESLint→library mapping points at a rule id that no longer exists. */
export function validateEslintMap(rules: Rule[]): void {
  const broken = findBrokenEslintMappings(rules);
  if (broken.length > 0) {
    const lines = broken.map((b) => `  ${b.eslintId} → ${b.libraryId} (no such rule)`);
    throw new Error(
      `[abqc-rules-check] ESLint map has ${broken.length} target(s) with no matching library rule:\n${lines.join("\n")}`,
    );
  }
}
