/**
 * Direct exercise of the new React domain builtin checkers
 * (react-components.ts, react-hooks.ts, react-jsx-setup.ts).
 *
 * These checkers are NOT wired into `checkers/builtins/index.ts` or
 * `checkers/type-checkers/builtin.ts` (both off-limits for this task), so
 * they can't be reached through `RuleLoader`/`runTypeCheck` the way
 * `automatable-rules.test.ts` exercises the existing builtins. Instead this
 * file imports each checker function directly and drives it with a hand-built
 * `CheckContext`/`RepoContext`, reading fixture content from
 * `src/test/fixtures/{bad,good}-<name>.jsx`.
 */
import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, mkdtemp, writeFile } from "node:fs/promises";
import { join, dirname, extname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, RepoContext, FileChecker } from "../types.js";

import {
  componentPascalCaseChecker,
  leakedRenderChecker,
  nestedComponentDefChecker,
  childrenPropChecker,
  classComponentChecker,
} from "../checkers/builtins/react-components.js";
import {
  inlinePropOnMemoedChecker,
  trivialUseMemoChecker,
  fetchInRenderChecker,
  noLazyRouteChecker,
  unvirtualizedListChecker,
  bindInJsxChecker,
  manualPromiseToStateChecker,
} from "../checkers/builtins/react-hooks.js";
import {
  sanitizeDangerouslySetInnerHtmlChecker,
  unnecessaryWrappingDivChecker,
  targetBlankRelChecker,
  singleChildFragmentChecker,
  nonSelfClosingEmptyTagChecker,
  buttonMissingTypeChecker,
  directStateMutationChecker,
  voidElementChildrenChecker,
  hardcodedUiStringChecker,
  routeNoErrorBoundaryChecker,
  iframeNoSandboxChecker,
  noSpectrumProviderChecker,
  handRolledUiIdiomChecker,
  noExcAppImportChecker,
  propDrillingChecker,
  handRolledShellLayoutChecker,
} from "../checkers/builtins/react-jsx-setup.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

async function ctxFor(fixtureFile: string, filePath: string): Promise<CheckContext> {
  const content = await readFile(join(fixtures, fixtureFile), "utf8");
  return { filePath, content, ext: extname(filePath), projectRoot: "/proj" };
}

interface FileCase {
  label: string;
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const DEFAULT_PATH = "/proj/src/components/Widget.jsx";

const FILE_CASES: FileCase[] = [
  {
    label: "component-pascalcase",
    checker: componentPascalCaseChecker,
    bad: "bad-component-pascalcase.jsx",
    good: "good-component-pascalcase.jsx",
    filePath: "/proj/src/components/OrderRow.jsx",
  },
  {
    label: "leaked-render",
    checker: leakedRenderChecker,
    bad: "bad-leaked-render.jsx",
    good: "good-leaked-render.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "nested-component-def",
    checker: nestedComponentDefChecker,
    bad: "bad-nested-component-def.jsx",
    good: "good-nested-component-def.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "children-prop",
    checker: childrenPropChecker,
    bad: "bad-children-prop.jsx",
    good: "good-children-prop.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "class-component",
    checker: classComponentChecker,
    bad: "bad-class-component.jsx",
    good: "good-class-component.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "trivial-usememo",
    checker: trivialUseMemoChecker,
    bad: "bad-trivial-usememo.jsx",
    good: "good-trivial-usememo.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "fetch-in-render",
    checker: fetchInRenderChecker,
    bad: "bad-fetch-in-render.jsx",
    good: "good-fetch-in-render.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "no-lazy-route",
    checker: noLazyRouteChecker,
    bad: "bad-no-lazy-route.jsx",
    good: "good-no-lazy-route.jsx",
    filePath: "/proj/src/App.jsx",
  },
  {
    label: "unvirtualized-list",
    checker: unvirtualizedListChecker,
    bad: "bad-unvirtualized-list.jsx",
    good: "good-unvirtualized-list.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "bind-in-jsx",
    checker: bindInJsxChecker,
    bad: "bad-bind-in-jsx.jsx",
    good: "good-bind-in-jsx.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "manual-promise-to-state",
    checker: manualPromiseToStateChecker,
    bad: "bad-manual-promise-to-state.jsx",
    good: "good-manual-promise-to-state.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "sanitize-dangerously-set-inner-html",
    checker: sanitizeDangerouslySetInnerHtmlChecker,
    bad: "bad-sanitize-dangerously-set-inner-html.jsx",
    good: "good-sanitize-dangerously-set-inner-html.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "unnecessary-wrapping-div",
    checker: unnecessaryWrappingDivChecker,
    bad: "bad-unnecessary-wrapping-div.jsx",
    good: "good-unnecessary-wrapping-div.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "target-blank-rel",
    checker: targetBlankRelChecker,
    bad: "bad-target-blank-rel.jsx",
    good: "good-target-blank-rel.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "single-child-fragment",
    checker: singleChildFragmentChecker,
    bad: "bad-single-child-fragment.jsx",
    good: "good-single-child-fragment.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "non-self-closing-empty-tag",
    checker: nonSelfClosingEmptyTagChecker,
    bad: "bad-non-self-closing-empty-tag.jsx",
    good: "good-non-self-closing-empty-tag.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "button-missing-type",
    checker: buttonMissingTypeChecker,
    bad: "bad-button-missing-type.jsx",
    good: "good-button-missing-type.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "direct-state-mutation",
    checker: directStateMutationChecker,
    bad: "bad-direct-state-mutation.jsx",
    good: "good-direct-state-mutation.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "void-element-children",
    checker: voidElementChildrenChecker,
    bad: "bad-void-element-children.jsx",
    good: "good-void-element-children.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "hardcoded-ui-string",
    checker: hardcodedUiStringChecker,
    bad: "bad-hardcoded-ui-string.jsx",
    good: "good-hardcoded-ui-string.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "route-no-error-boundary",
    checker: routeNoErrorBoundaryChecker,
    bad: "bad-route-no-error-boundary.jsx",
    good: "good-route-no-error-boundary.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "iframe-no-sandbox",
    checker: iframeNoSandboxChecker,
    bad: "bad-iframe-no-sandbox.jsx",
    good: "good-iframe-no-sandbox.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "hand-rolled-ui-idiom",
    checker: handRolledUiIdiomChecker,
    bad: "bad-hand-rolled-ui-idiom.jsx",
    good: "good-hand-rolled-ui-idiom.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "no-exc-app-import",
    checker: noExcAppImportChecker,
    bad: "bad-no-exc-app-import.jsx",
    good: "good-no-exc-app-import.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "prop-drilling",
    checker: propDrillingChecker,
    bad: "bad-prop-drilling.jsx",
    good: "good-prop-drilling.jsx",
    filePath: DEFAULT_PATH,
  },
  {
    label: "hand-rolled-shell-layout",
    checker: handRolledShellLayoutChecker,
    bad: "bad-hand-rolled-shell-layout.jsx",
    good: "good-hand-rolled-shell-layout.jsx",
    filePath: DEFAULT_PATH,
  },
];

describe("react domain builtin checkers — file-scope", () => {
  for (const c of FILE_CASES) {
    it(`${c.label}: flags the bad fixture and passes the good fixture`, async () => {
      const badCtx = await ctxFor(c.bad, c.filePath);
      const badHits = await c.checker.check(badCtx);
      assert.ok(badHits.length > 0, `expected ${c.bad} to produce at least one hit for ${c.label}`);

      const goodCtx = await ctxFor(c.good, c.filePath);
      const goodHits = await c.checker.check(goodCtx);
      assert.equal(goodHits.length, 0, `expected ${c.good} to produce zero hits for ${c.label}, got: ${JSON.stringify(goodHits)}`);
    });
  }
});

describe("react domain builtin checkers — repo-scope", () => {
  it("inline-prop-on-memoed: flags inline literal props on a memoed component, not stable references", async () => {
    const badPath = join(fixtures, "bad-inline-prop-on-memoed.jsx");
    const badRepo: RepoContext = { projectRoot: "/proj", allFiles: [badPath] };
    const badHits = await inlinePropOnMemoedChecker.check(badRepo);
    assert.ok(badHits.length > 0, "expected inline literal props on a memoed component to be flagged");

    const goodPath = join(fixtures, "good-inline-prop-on-memoed.jsx");
    const goodRepo: RepoContext = { projectRoot: "/proj", allFiles: [goodPath] };
    const goodHits = await inlinePropOnMemoedChecker.check(goodRepo);
    assert.equal(goodHits.length, 0, `expected stable-reference props to produce zero hits, got: ${JSON.stringify(goodHits)}`);
  });

  it("no-spectrum-provider: flags an app entry with no Provider, passes one with theme+locale", async () => {
    const badContent = await readFile(join(fixtures, "bad-no-spectrum-provider.jsx"), "utf8");
    const goodContent = await readFile(join(fixtures, "good-no-spectrum-provider.jsx"), "utf8");

    const badRoot = await mkdtemp(join(tmpdir(), "abqc-no-provider-bad-"));
    const badEntry = join(badRoot, "App.jsx");
    await writeFile(badEntry, badContent, "utf8");
    const badHits = await noSpectrumProviderChecker.check({ projectRoot: badRoot, allFiles: [badEntry] });
    assert.ok(badHits.length > 0, "expected an app entry with no <Provider> to be flagged");

    const goodRoot = await mkdtemp(join(tmpdir(), "abqc-no-provider-good-"));
    const goodEntry = join(goodRoot, "App.jsx");
    await writeFile(goodEntry, goodContent, "utf8");
    const goodHits = await noSpectrumProviderChecker.check({ projectRoot: goodRoot, allFiles: [goodEntry] });
    assert.equal(goodHits.length, 0, `expected a Provider with theme+locale to produce zero hits, got: ${JSON.stringify(goodHits)}`);
  });
});

describe("react/components/no-findDOMNode-it's-deprecated — regex-only rule", () => {
  // No AST checker needed for this one — it's wired as `type: regex` with
  // `pattern: findDOMNode\(` directly in the rule's `### Check` block.
  it("pattern matches the bad fixture and not the good fixture", async () => {
    const badContent = await readFile(join(fixtures, "bad-finddomnode.jsx"), "utf8");
    const goodContent = await readFile(join(fixtures, "good-finddomnode.jsx"), "utf8");
    const pattern = /findDOMNode\(/;
    assert.ok(pattern.test(badContent), "expected findDOMNode( to be present in the bad fixture");
    assert.ok(!pattern.test(goodContent), "expected findDOMNode( to be absent from the good fixture");
  });
});
