import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import type { CheckContext, FileChecker } from "../types.js";
import {
  typeOnlyImportChecker,
  noUnnecessaryTypeAssertionChecker,
  strictBooleanExpressionChecker,
  noUnnecessaryConditionChecker,
  nonStringTemplateInterpChecker,
  unsafeAnyBoundaryChecker,
} from "../checkers/builtins/typescript-checks.js";
import { objectInjectionMemberKeyChecker } from "../checkers/builtins/security-owasp.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

interface Case {
  name: string;
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

// Fixture-file cases for the manual→automatable proxies and the object-
// injection blind-spot proxy added during the independent verification pass
// (see VERIFICATION-REPORT.md). unsafe-assignment/-return/-argument
// deliberately reuse the SAME checker against three different fixture pairs —
// one per rule's own canonical example — since all three share one checker.
const CASES: Case[] = [
  { name: "type-only-import", checker: typeOnlyImportChecker, bad: "bad-type-only-import.ts", good: "good-type-only-import.ts", filePath: "/proj/src/format.ts" },
  { name: "unnecessary-type-assertion", checker: noUnnecessaryTypeAssertionChecker, bad: "bad-unnecessary-type-assertion.ts", good: "good-unnecessary-type-assertion.ts", filePath: "/proj/src/greet.ts" },
  { name: "strict-boolean-expression", checker: strictBooleanExpressionChecker, bad: "bad-strict-boolean-expression.ts", good: "good-strict-boolean-expression.ts", filePath: "/proj/src/greet.ts" },
  { name: "unnecessary-condition", checker: noUnnecessaryConditionChecker, bad: "bad-unnecessary-condition.ts", good: "good-unnecessary-condition.ts", filePath: "/proj/src/process.ts" },
  { name: "non-string-template-interp", checker: nonStringTemplateInterpChecker, bad: "bad-non-string-template-interp.ts", good: "good-non-string-template-interp.ts", filePath: "/proj/src/describe.ts" },
  { name: "unsafe-any-boundary (assignment shape)", checker: unsafeAnyBoundaryChecker, bad: "bad-unsafe-assignment.ts", good: "good-unsafe-assignment.ts", filePath: "/proj/src/handle.ts" },
  { name: "unsafe-any-boundary (return shape)", checker: unsafeAnyBoundaryChecker, bad: "bad-unsafe-return.ts", good: "good-unsafe-return.ts", filePath: "/proj/src/getOrder.ts" },
  { name: "unsafe-any-boundary (argument shape)", checker: unsafeAnyBoundaryChecker, bad: "bad-unsafe-argument.ts", good: "good-unsafe-argument.ts", filePath: "/proj/src/handle2.ts" },
  { name: "object-injection-member-key", checker: objectInjectionMemberKeyChecker, bad: "bad-object-injection-member-key.js", good: "good-object-injection-member-key.js", filePath: "/proj/src/setField.js" },
];

async function runCheck(checker: FileChecker, fixtureName: string, filePath: string) {
  const content = await readFile(join(fixtures, fixtureName), "utf8");
  const ctx: CheckContext = {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
  };
  return checker.check(ctx);
}

describe("verification-pass fixes — manual→automatable proxies and blind-spot checkers", () => {
  for (const c of CASES) {
    it(`${c.name}: flags the bad fixture and passes the good fixture`, async () => {
      const badHits = await runCheck(c.checker, c.bad, c.filePath);
      assert.ok(badHits.length > 0, `expected ${c.bad} to produce at least one hit for ${c.name}`);

      const goodHits = await runCheck(c.checker, c.good, c.filePath);
      assert.equal(goodHits.length, 0, `expected ${c.good} to produce zero hits for ${c.name}, got: ${JSON.stringify(goodHits)}`);
    });
  }
});
