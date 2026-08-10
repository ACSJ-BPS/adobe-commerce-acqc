import { Linter, Rule } from "eslint";
import { createRequire } from "node:module";
import { readFile } from "node:fs/promises";
import type { Finding, Severity } from "./types.js";
import * as tsParser from "@typescript-eslint/parser";

export interface EslintRunnerOptions {
  maxFileChars?: number;
}

const SEVERITY_FROM_ESLINT: Record<number, Severity> = {
  0: "info",
  1: "warn",
  2: "error",
};

const TS_PARSER_ID = "@typescript-eslint/parser";

// ESLint rules with no taint tracking — they flag every syntactic match
// (any non-literal bracket access, any dynamic RegExp/require/fs argument)
// regardless of whether the value is actually attacker-controlled, so a firing
// rule still needs a human to confirm real risk. Findings from these are
// marked "low" confidence instead of the default "high" so consumers don't
// treat a pattern match as a confirmed vulnerability.
const HEURISTIC_ONLY_RULES: Set<string> = new Set([
  "security/detect-object-injection",
  "security/detect-non-literal-regexp",
  "security/detect-non-literal-require",
  "security/detect-non-literal-fs-filename",
  "security/detect-possible-timing-attacks",
  "security/detect-pseudoRandomBytes",
  "security/detect-unsafe-regex",
]);

// Path patterns that mark a file as test code so we can enable the "jest" env
// (built into ESLint core) and stop flagging describe/it/test/expect/jest/
// beforeEach/afterEach/beforeAll/afterAll as no-undef. Without this, every
// test file lints as if globals like `jest` don't exist.
const TEST_FILE_RE = /(?:^|[/\\])(?:__tests__[/\\]|__mocks__[/\\])|\.(?:test|spec)\.[jt]sx?$/i;

function isTestFile(filePath: string): boolean {
  return TEST_FILE_RE.test(filePath);
}

// ─── Plugin rule sets ─────────────────────────────────────────────────────────
// Rules applied to every lintable file.
const ALL_FILE_RULES: Linter.RulesRecord = {
  // Core ESLint
  "no-unused-vars": ["warn", { argsIgnorePattern: "^_", varsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" }],
  "no-undef": "error",
  "no-eval": "error",
  "no-implied-eval": "error",
  "no-new-func": "error",
  "no-var": "warn",
  "prefer-const": "warn",
  "eqeqeq": ["error", "always", { null: "ignore" }],
  "no-throw-literal": "warn",
  "no-empty": "warn",
  "no-unreachable": "error",
  "no-dupe-keys": "error",
  "no-redeclare": "error",
  "no-cond-assign": "error",
  "no-constant-condition": "warn",
  "radix": "warn",
  // Security plugin
  "security/detect-eval-with-expression": "error",
  "security/detect-non-literal-regexp": "warn",
  "security/detect-non-literal-require": "warn",
  "security/detect-possible-timing-attacks": "warn",
  "security/detect-unsafe-regex": "error",
  "security/detect-object-injection": "warn",
  // Node.js plugin
  "n/no-deprecated-api": "error",
  "n/no-process-exit": "warn",
  "n/no-callback-literal": "error",
  // Import plugin
  "import/no-duplicates": "error",
  "import/no-self-import": "error",
  // Promise plugin
  "promise/param-names": "error",
  "promise/no-return-wrap": "error",
};

// Additional rules for TypeScript files (.ts, .tsx).
const TS_ONLY_RULES: Linter.RulesRecord = {
  // Disable core rules that @typescript-eslint supersedes for TS files.
  "no-undef": "off",
  "no-unused-vars": "off",
  // TypeScript ESLint plugin (syntax-only rules — no project reference needed).
  "@typescript-eslint/no-explicit-any": "warn",
  "@typescript-eslint/no-non-null-assertion": "warn",
  "@typescript-eslint/consistent-type-imports": ["warn", { prefer: "type-imports", fixStyle: "inline-type-imports" }],
};

// Additional rules for JSX/TSX files.
const JSX_RULES: Linter.RulesRecord = {
  // React plugin
  "react/jsx-key": "error",
  "react/no-array-index-key": "warn",
  "react/no-deprecated": "error",
  "react/no-direct-mutation-state": "error",
  "react/no-unknown-property": "error",
  // React Hooks plugin
  "react-hooks/rules-of-hooks": "error",
  "react-hooks/exhaustive-deps": "warn",
  // Accessibility plugin
  "jsx-a11y/alt-text": "error",
  "jsx-a11y/aria-props": "error",
  "jsx-a11y/aria-role": ["error", { ignoreNonDOM: true }],
  "jsx-a11y/aria-proptypes": "error",
};

// ─── EslintRunner ────────────────────────────────────────────────────────────

export class EslintRunner {
  private linter = new Linter();
  private maxFileChars: number;

  constructor(options: EslintRunnerOptions = {}) {
    this.maxFileChars = options.maxFileChars ?? 200_000;
    this.linter.defineParser(TS_PARSER_ID, tsParser as Linter.ParserModule);
    this.registerPlugins();
  }

  private registerPlugins(): void {
    const _require = createRequire(import.meta.url);

    const pluginMap: Array<[prefix: string, pkg: string]> = [
      ["security", "eslint-plugin-security"],
      ["react", "eslint-plugin-react"],
      ["react-hooks", "eslint-plugin-react-hooks"],
      ["jsx-a11y", "eslint-plugin-jsx-a11y"],
      ["n", "eslint-plugin-n"],
      ["import", "eslint-plugin-import"],
      ["promise", "eslint-plugin-promise"],
      ["@typescript-eslint", "@typescript-eslint/eslint-plugin"],
    ];

    for (const [prefix, pkg] of pluginMap) {
      let plugin: Record<string, unknown>;
      try {
        plugin = _require(pkg) as Record<string, unknown>;
      } catch {
        // Plugin not installed — log once and continue. Run `npm install` in packages/core.
        process.stderr.write(`[abqc] ESLint plugin "${pkg}" not installed — skipping its rules\n`);
        continue;
      }
      const rules = (plugin["rules"] ?? {}) as Record<string, Rule.RuleModule>;
      for (const [ruleName, rule] of Object.entries(rules)) {
        try {
          this.linter.defineRule(`${prefix}/${ruleName}`, rule);
        } catch {
          // Skip rules that fail to register (e.g. schema validation errors in old plugin versions).
        }
      }
    }
  }

  async run(filePath: string): Promise<Finding[]> {
    let code: string;
    try {
      code = await readFile(filePath, "utf8");
    } catch {
      return [];
    }
    if (!isLintable(filePath)) return [];

    // Truncate to match the LLM layer's max size so findings have consistent line context.
    if (code.length > this.maxFileChars) {
      code = code.slice(0, this.maxFileChars);
    }

    const isTS = isTypeScript(filePath);
    const isJSX = isJsxFile(filePath);

    const config: Linter.Config = {
      parserOptions: {
        ecmaVersion: "latest",
        sourceType: "module",
        ecmaFeatures: { jsx: isJSX },
      },
      env: { node: true, browser: true, es2022: true, jest: isTestFile(filePath), mocha: isTestFile(filePath) },
      settings: isJSX ? { react: { version: "detect" } } : undefined,
      parser: isTS ? TS_PARSER_ID : undefined,
      rules: {
        ...ALL_FILE_RULES,
        ...(isTS ? TS_ONLY_RULES : {}),
        ...(isJSX ? JSX_RULES : {}),
      },
    };

    const messages = this.linter.verify(code, config, { filename: filePath });
    return messages
      .filter((m) => m.ruleId !== null || !m.fatal)
      .map((m) => this.toFinding(m, filePath));
  }

  /**
   * ESLint rule ids that will actually RUN against this file — lintable, not
   * configured "off", and registered (accounts for a plugin that failed to
   * load in registerPlugins()). Used by @abqc/rules-check to know which
   * ESLint-mapped library rules were definitively evaluated (fired or not),
   * so a passing rule can be treated as decided rather than left to judgment.
   */
  activeRuleIds(filePath: string): string[] {
    if (!isLintable(filePath)) return [];
    const isTS = isTypeScript(filePath);
    const isJSX = isJsxFile(filePath);
    const merged: Linter.RulesRecord = {
      ...ALL_FILE_RULES,
      ...(isTS ? TS_ONLY_RULES : {}),
      ...(isJSX ? JSX_RULES : {}),
    };
    const defined = this.linter.getRules();
    return Object.entries(merged)
      .filter(([id, cfg]) => ruleSeverity(cfg) !== 0 && defined.has(id))
      .map(([id]) => id);
  }

  private toFinding(m: Linter.LintMessage, filePath: string): Finding {
    return {
      ruleId: m.ruleId ?? "eslint/parsing-error",
      severity: SEVERITY_FROM_ESLINT[m.severity] ?? "info",
      message: m.message,
      filePath,
      line: m.line,
      column: m.column,
      endLine: m.endLine,
      endColumn: m.endColumn,
      source: "eslint",
      confidence: m.ruleId && HEURISTIC_ONLY_RULES.has(m.ruleId) ? "low" : "high",
    };
  }
}

/** Normalizes an ESLint rule config entry ("off"|0|"warn"|1|["error",{...}]|...) to a severity number. */
function ruleSeverity(cfg: Linter.RuleEntry): number {
  const raw = Array.isArray(cfg) ? cfg[0] : cfg;
  if (typeof raw === "number") return raw;
  if (raw === "off") return 0;
  if (raw === "warn") return 1;
  if (raw === "error") return 2;
  return 0;
}

function isLintable(filePath: string): boolean {
  return /\.(js|mjs|cjs|jsx|ts|tsx)$/i.test(filePath);
}

function isTypeScript(filePath: string): boolean {
  return /\.tsx?$/i.test(filePath);
}

function isJsxFile(filePath: string): boolean {
  return /\.(jsx|tsx)$/i.test(filePath);
}
