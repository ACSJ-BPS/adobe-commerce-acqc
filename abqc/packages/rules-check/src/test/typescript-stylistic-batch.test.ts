import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdtemp } from "node:fs/promises";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, RepoContext, FileChecker, RepoChecker } from "../types.js";
import {
  tsFloatingPromiseChecker,
  tsMisusedPromiseChecker,
  tsAwaitNonPromiseChecker,
  tsUnmarkedAsyncChecker,
  asyncReturnTypeNotWrappedChecker,
  typeAliasObjectShapeChecker,
  nonReadonlyFieldChecker,
  tsEnumDeclarationChecker,
  preferSatisfiesChecker,
  overloadDuplicationChecker,
  switchExhaustivenessChecker,
  confusingVoidChecker,
  meaninglessVoidChecker,
  indexofPrefixSuffixChecker,
  indexofMembershipChecker,
  filterZeroIndexChecker,
  tsconfigStrictChecker,
  tsconfigUncheckedIndexedAccessChecker,
  nonNullOnOptionalChainChecker,
  logicalOrDefaultChecker,
  manualNullGuardChainChecker,
  indexSignatureDictChecker,
  duplicateUnionMemberChecker,
} from "../checkers/builtins/typescript-checks.js";
import {
  nestedTernaryChecker,
  unneededTernaryChecker,
  emptyBlockNoCommentChecker,
  uselessReturnChecker,
  elseAfterReturnChecker,
  repeatedMemberAccessChecker,
  functionApplyChecker,
  objectShorthandChecker,
  paramReassignChecker,
  anonymousFunctionCallbackChecker,
  returnAwaitOutsideTryChecker,
  restrictedSyntaxChecker,
  magicNumberChecker,
  numericSeparatorChecker,
  throwNonErrorChecker,
  implicitCoercionChecker,
  mixedOperatorsChecker,
  yodaConditionChecker,
  operatorAssignmentChecker,
  bracketNotationValidIdentChecker,
  unusedExpressionChecker,
  stringConcatChecker,
  literalConcatChecker,
} from "../checkers/builtins/stylistic-checks.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

interface FileCase {
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const TS_FILE = "/proj/src/sample.ts";
const JS_FILE = "/proj/src/sample.js";

const FILE_CASES: FileCase[] = [
  { checker: tsFloatingPromiseChecker, bad: "bad-ts-floating-promise-approx.ts", good: "good-ts-floating-promise-approx.ts", filePath: TS_FILE },
  { checker: tsMisusedPromiseChecker, bad: "bad-ts-misused-promise-approx.ts", good: "good-ts-misused-promise-approx.ts", filePath: TS_FILE },
  { checker: tsAwaitNonPromiseChecker, bad: "bad-ts-await-non-promise-approx.ts", good: "good-ts-await-non-promise-approx.ts", filePath: TS_FILE },
  { checker: tsUnmarkedAsyncChecker, bad: "bad-ts-unmarked-async-approx.ts", good: "good-ts-unmarked-async-approx.ts", filePath: TS_FILE },
  { checker: asyncReturnTypeNotWrappedChecker, bad: "bad-async-return-type-not-wrapped.ts", good: "good-async-return-type-not-wrapped.ts", filePath: TS_FILE },
  { checker: typeAliasObjectShapeChecker, bad: "bad-type-alias-object-shape.ts", good: "good-type-alias-object-shape.ts", filePath: TS_FILE },
  { checker: nonReadonlyFieldChecker, bad: "bad-non-readonly-field.ts", good: "good-non-readonly-field.ts", filePath: TS_FILE },
  { checker: tsEnumDeclarationChecker, bad: "bad-ts-enum-declaration.ts", good: "good-ts-enum-declaration.ts", filePath: TS_FILE },
  { checker: preferSatisfiesChecker, bad: "bad-prefer-satisfies-approx.ts", good: "good-prefer-satisfies-approx.ts", filePath: TS_FILE },
  { checker: overloadDuplicationChecker, bad: "bad-overload-duplication.ts", good: "good-overload-duplication.ts", filePath: TS_FILE },
  { checker: switchExhaustivenessChecker, bad: "bad-switch-exhaustiveness-approx.ts", good: "good-switch-exhaustiveness-approx.ts", filePath: TS_FILE },
  { checker: confusingVoidChecker, bad: "bad-confusing-void-approx.ts", good: "good-confusing-void-approx.ts", filePath: TS_FILE },
  { checker: meaninglessVoidChecker, bad: "bad-meaningless-void.ts", good: "good-meaningless-void.ts", filePath: TS_FILE },
  { checker: indexofPrefixSuffixChecker, bad: "bad-indexof-prefix-suffix.ts", good: "good-indexof-prefix-suffix.ts", filePath: TS_FILE },
  { checker: indexofMembershipChecker, bad: "bad-indexof-membership.ts", good: "good-indexof-membership.ts", filePath: TS_FILE },
  { checker: filterZeroIndexChecker, bad: "bad-filter-zero-index.ts", good: "good-filter-zero-index.ts", filePath: TS_FILE },
  { checker: nonNullOnOptionalChainChecker, bad: "bad-non-null-on-optional-chain.ts", good: "good-non-null-on-optional-chain.ts", filePath: TS_FILE },
  { checker: logicalOrDefaultChecker, bad: "bad-logical-or-default-approx.ts", good: "good-logical-or-default-approx.ts", filePath: TS_FILE },
  { checker: manualNullGuardChainChecker, bad: "bad-manual-null-guard-chain.ts", good: "good-manual-null-guard-chain.ts", filePath: TS_FILE },
  { checker: indexSignatureDictChecker, bad: "bad-index-signature-dict.ts", good: "good-index-signature-dict.ts", filePath: TS_FILE },
  { checker: duplicateUnionMemberChecker, bad: "bad-duplicate-union-member.ts", good: "good-duplicate-union-member.ts", filePath: TS_FILE },

  { checker: nestedTernaryChecker, bad: "bad-nested-ternary.js", good: "good-nested-ternary.js", filePath: JS_FILE },
  { checker: unneededTernaryChecker, bad: "bad-unneeded-ternary.js", good: "good-unneeded-ternary.js", filePath: JS_FILE },
  { checker: emptyBlockNoCommentChecker, bad: "bad-empty-block-no-comment.js", good: "good-empty-block-no-comment.js", filePath: JS_FILE },
  { checker: uselessReturnChecker, bad: "bad-useless-return.js", good: "good-useless-return.js", filePath: JS_FILE },
  { checker: elseAfterReturnChecker, bad: "bad-else-after-return.js", good: "good-else-after-return.js", filePath: JS_FILE },
  { checker: repeatedMemberAccessChecker, bad: "bad-repeated-member-access.js", good: "good-repeated-member-access.js", filePath: JS_FILE },
  { checker: functionApplyChecker, bad: "bad-function-apply.js", good: "good-function-apply.js", filePath: JS_FILE },
  { checker: objectShorthandChecker, bad: "bad-object-shorthand.js", good: "good-object-shorthand.js", filePath: JS_FILE },
  { checker: paramReassignChecker, bad: "bad-param-reassign.js", good: "good-param-reassign.js", filePath: JS_FILE },
  { checker: anonymousFunctionCallbackChecker, bad: "bad-anonymous-function-callback.js", good: "good-anonymous-function-callback.js", filePath: JS_FILE },
  { checker: returnAwaitOutsideTryChecker, bad: "bad-return-await-outside-try.js", good: "good-return-await-outside-try.js", filePath: JS_FILE },
  { checker: restrictedSyntaxChecker, bad: "bad-configurable-restricted-syntax.js", good: "good-configurable-restricted-syntax.js", filePath: JS_FILE },
  { checker: magicNumberChecker, bad: "bad-magic-number.js", good: "good-magic-number.js", filePath: JS_FILE },
  { checker: numericSeparatorChecker, bad: "bad-numeric-separator.js", good: "good-numeric-separator.js", filePath: JS_FILE },
  { checker: throwNonErrorChecker, bad: "bad-throw-non-error.js", good: "good-throw-non-error.js", filePath: JS_FILE },
  { checker: implicitCoercionChecker, bad: "bad-implicit-coercion.js", good: "good-implicit-coercion.js", filePath: JS_FILE },
  { checker: mixedOperatorsChecker, bad: "bad-mixed-operators.js", good: "good-mixed-operators.js", filePath: JS_FILE },
  { checker: yodaConditionChecker, bad: "bad-yoda-condition.js", good: "good-yoda-condition.js", filePath: JS_FILE },
  { checker: operatorAssignmentChecker, bad: "bad-operator-assignment.js", good: "good-operator-assignment.js", filePath: JS_FILE },
  { checker: bracketNotationValidIdentChecker, bad: "bad-bracket-notation-valid-ident.js", good: "good-bracket-notation-valid-ident.js", filePath: JS_FILE },
  { checker: unusedExpressionChecker, bad: "bad-unused-expression.js", good: "good-unused-expression.js", filePath: JS_FILE },
  { checker: stringConcatChecker, bad: "bad-string-concat.js", good: "good-string-concat.js", filePath: JS_FILE },
  { checker: literalConcatChecker, bad: "bad-literal-concat.js", good: "good-literal-concat.js", filePath: JS_FILE },
];

async function loadCtx(filePath: string, fixtureName: string): Promise<CheckContext> {
  const content = await readFile(join(fixtures, fixtureName), "utf8");
  return {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
  };
}

describe("typescript + stylistic builtin checkers — file-scoped fixtures", () => {
  for (const c of FILE_CASES) {
    it(`${c.checker.ruleId} flags bad fixture`, async () => {
      const ctx = await loadCtx(c.filePath, c.bad);
      assert.ok(c.checker.appliesTo(ctx.filePath), `checker should apply to ${ctx.filePath}`);
      const hits = await c.checker.check(ctx);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.checker.ruleId} passes good fixture`, async () => {
      const ctx = await loadCtx(c.filePath, c.good);
      const hits = await c.checker.check(ctx);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

interface RepoCase {
  checker: RepoChecker;
  bad: string;
  good: string;
}

const REPO_CASES: RepoCase[] = [
  { checker: tsconfigStrictChecker, bad: "bad-tsconfig-strict.json", good: "good-tsconfig-strict.json" },
  {
    checker: tsconfigUncheckedIndexedAccessChecker,
    bad: "bad-tsconfig-unchecked-indexed-access.json",
    good: "good-tsconfig-unchecked-indexed-access.json",
  },
];

async function repoCtxFor(fixtureName: string): Promise<RepoContext> {
  const root = await mkdtemp(join(tmpdir(), "abqc-ts-stylistic-"));
  const content = await readFile(join(fixtures, fixtureName), "utf8");
  const tsconfigPath = join(root, "tsconfig.json");
  await writeFile(tsconfigPath, content, "utf8");
  return { projectRoot: root, allFiles: [tsconfigPath] };
}

describe("typescript builtin checkers — repo-scoped fixtures (tsconfig)", () => {
  for (const c of REPO_CASES) {
    it(`${c.checker.ruleId} flags bad tsconfig`, async () => {
      const ctx = await repoCtxFor(c.bad);
      const hits = await c.checker.check(ctx);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.checker.ruleId} passes good tsconfig`, async () => {
      const ctx = await repoCtxFor(c.good);
      const hits = await c.checker.check(ctx);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

describe("typescript/style/don-t-disable-typescript-eslint-with-ts-ignore-use-ts-expect-err (regex spec, no builtin code)", () => {
  const TS_IGNORE_PATTERN = /@ts-ignore/;

  it("matches the bad fixture (@ts-ignore present)", async () => {
    const content = await readFile(join(fixtures, "bad-ts-ignore.ts"), "utf8");
    assert.match(content, TS_IGNORE_PATTERN);
  });

  it("does not match the good fixture (@ts-expect-error used instead)", async () => {
    const content = await readFile(join(fixtures, "good-ts-ignore.ts"), "utf8");
    assert.doesNotMatch(content, TS_IGNORE_PATTERN);
  });
});
