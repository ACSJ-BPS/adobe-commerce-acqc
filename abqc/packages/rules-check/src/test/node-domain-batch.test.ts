import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdtemp } from "node:fs/promises";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, RepoContext, FileChecker } from "../types.js";
import {
  topLevelAwaitNetworkChecker,
  requireAwaitChecker,
  floatingPromiseChecker,
  thenMustReturnChecker,
  nestedThenChecker,
  promiseResolveWrapChecker,
  returnInFinallyChecker,
  thenInAsyncFnChecker,
  unboundedPromiseAllMapChecker,
} from "../checkers/builtins/node-async-promises.js";
import {
  redosNestedQuantifierChecker,
  childProcessShellChecker,
  syncFsInHandlerChecker,
  cpuBoundSyncHandlerChecker,
  httpTimeoutRetryChecker,
  schemaValidateBoundaryChecker,
  pureRethrowCatchChecker,
  unusedErrParamChecker,
  typedErrorClassesChecker,
  processCrashHandlersChecker,
} from "../checkers/builtins/node-bugclasses-errors.js";
import {
  isnanTypeofChecker,
  arrayCallbackReturnChecker,
  noShadowChecker,
  callbackFsChecker,
  deprecatedNodeApiChecker,
  redundantGlobalImportChecker,
  namingConventionChecker,
  exportStyleByPathChecker,
} from "../checkers/builtins/node-correctness-modern.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

interface Case {
  name: string;
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const CASES: Case[] = [
  { name: "redos-nested-quantifier", checker: redosNestedQuantifierChecker, bad: "bad-redos-nested-quantifier.js", good: "good-redos-nested-quantifier.js", filePath: "/proj/src/regex.js" },
  { name: "top-level-await-network", checker: topLevelAwaitNetworkChecker, bad: "bad-top-level-await-network.js", good: "good-top-level-await-network.js", filePath: "/proj/actions/demo/index.js" },
  { name: "require-await", checker: requireAwaitChecker, bad: "bad-require-await.js", good: "good-require-await.js", filePath: "/proj/src/index.js" },
  { name: "child-process-shell", checker: childProcessShellChecker, bad: "bad-child-process-shell.js", good: "good-child-process-shell.js", filePath: "/proj/src/index.js" },
  { name: "sync-fs-in-handler", checker: syncFsInHandlerChecker, bad: "bad-sync-fs-in-handler.js", good: "good-sync-fs-in-handler.js", filePath: "/proj/actions/demo/index.js" },
  { name: "cpu-bound-sync-handler", checker: cpuBoundSyncHandlerChecker, bad: "bad-cpu-bound-sync-handler.js", good: "good-cpu-bound-sync-handler.js", filePath: "/proj/actions/demo/index.js" },
  { name: "http-timeout-retry", checker: httpTimeoutRetryChecker, bad: "bad-http-timeout-retry.js", good: "good-http-timeout-retry.js", filePath: "/proj/src/index.js" },
  { name: "schema-validate-boundary", checker: schemaValidateBoundaryChecker, bad: "bad-schema-validate-boundary.js", good: "good-schema-validate-boundary.js", filePath: "/proj/actions/demo/index.js" },
  { name: "isnan-typeof", checker: isnanTypeofChecker, bad: "bad-isnan-typeof.js", good: "good-isnan-typeof.js", filePath: "/proj/src/index.js" },
  { name: "array-callback-return", checker: arrayCallbackReturnChecker, bad: "bad-array-callback-return.js", good: "good-array-callback-return.js", filePath: "/proj/src/index.js" },
  { name: "no-shadow", checker: noShadowChecker, bad: "bad-no-shadow.js", good: "good-no-shadow.js", filePath: "/proj/src/index.js" },
  { name: "floating-promise", checker: floatingPromiseChecker, bad: "bad-floating-promise.js", good: "good-floating-promise.js", filePath: "/proj/src/index.js" },
  { name: "pure-rethrow-catch", checker: pureRethrowCatchChecker, bad: "bad-pure-rethrow-catch.js", good: "good-pure-rethrow-catch.js", filePath: "/proj/src/index.js" },
  { name: "unused-err-param", checker: unusedErrParamChecker, bad: "bad-unused-err-param.js", good: "good-unused-err-param.js", filePath: "/proj/src/index.js" },
  { name: "typed-error-classes", checker: typedErrorClassesChecker, bad: "bad-typed-error-classes.js", good: "good-typed-error-classes.js", filePath: "/proj/src/index.js" },
  { name: "callback-fs", checker: callbackFsChecker, bad: "bad-callback-fs.js", good: "good-callback-fs.js", filePath: "/proj/src/index.js" },
  { name: "deprecated-node-api", checker: deprecatedNodeApiChecker, bad: "bad-deprecated-node-api.js", good: "good-deprecated-node-api.js", filePath: "/proj/src/index.js" },
  { name: "redundant-global-import", checker: redundantGlobalImportChecker, bad: "bad-redundant-global-import.js", good: "good-redundant-global-import.js", filePath: "/proj/src/index.js" },
  { name: "naming-convention", checker: namingConventionChecker, bad: "bad-naming-convention.js", good: "good-naming-convention.js", filePath: "/proj/src/index.js" },
  { name: "export-style-by-path", checker: exportStyleByPathChecker, bad: "bad-export-style-by-path.js", good: "good-export-style-by-path.js", filePath: "/proj/src/utils/helper.js" },
  { name: "then-must-return", checker: thenMustReturnChecker, bad: "bad-then-must-return.js", good: "good-then-must-return.js", filePath: "/proj/src/index.js" },
  { name: "nested-then", checker: nestedThenChecker, bad: "bad-nested-then.js", good: "good-nested-then.js", filePath: "/proj/src/index.js" },
  { name: "promise-resolve-wrap", checker: promiseResolveWrapChecker, bad: "bad-promise-resolve-wrap.js", good: "good-promise-resolve-wrap.js", filePath: "/proj/src/index.js" },
  { name: "return-in-finally", checker: returnInFinallyChecker, bad: "bad-return-in-finally.js", good: "good-return-in-finally.js", filePath: "/proj/src/index.js" },
  { name: "then-in-async-fn", checker: thenInAsyncFnChecker, bad: "bad-then-in-async-fn.js", good: "good-then-in-async-fn.js", filePath: "/proj/src/index.js" },
  { name: "unbounded-promise-all-map", checker: unboundedPromiseAllMapChecker, bad: "bad-unbounded-promise-all-map.js", good: "good-unbounded-promise-all-map.js", filePath: "/proj/src/index.js" },
];

async function runCheck(checker: FileChecker, fixtureName: string, filePath: string) {
  const content = await readFile(join(fixtures, fixtureName), "utf8");
  const ctx: CheckContext = {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
    actionEntryFiles: filePath.includes("/actions/") ? new Set([filePath]) : undefined,
  };
  return checker.check(ctx);
}

describe("node async/bug-classes/correctness builtin checkers — file-scoped fixtures", () => {
  for (const c of CASES) {
    it(`${c.name} flags bad fixture`, async () => {
      const hits = await runCheck(c.checker, c.bad, c.filePath);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.name} passes good fixture`, async () => {
      const hits = await runCheck(c.checker, c.good, c.filePath);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

describe("node builtin checkers — repo-scoped fixtures", () => {
  it("process-crash-handlers flags entry file missing handlers", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-bad-"));
    const content = await readFile(join(fixtures, "bad-process-crash-handlers.js"), "utf8");
    const entryPath = join(root, "index.js");
    await writeFile(entryPath, content, "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [entryPath] };
    const hits = await processCrashHandlersChecker.check(ctx);
    assert.ok(hits.length > 0);
  });

  it("process-crash-handlers passes when both handlers are registered", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-good-"));
    const content = await readFile(join(fixtures, "good-process-crash-handlers.js"), "utf8");
    const entryPath = join(root, "index.js");
    await writeFile(entryPath, content, "utf8");
    const ctx: RepoContext = { projectRoot: root, allFiles: [entryPath] };
    const hits = await processCrashHandlersChecker.check(ctx);
    assert.equal(hits.length, 0);
  });

  it("process-crash-handlers skips when no entry file is found", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-empty-"));
    const ctx: RepoContext = { projectRoot: root, allFiles: [] };
    const hits = await processCrashHandlersChecker.check(ctx);
    assert.equal(hits.length, 0);
  });
});
