import { readFile } from "node:fs/promises";
import { join, dirname } from "node:path";
import type { CheckHit, CheckContext, FileChecker, RepoChecker, RepoContext } from "../../types.js";
import { requireParsed, isCheckHitArray } from "./shared.js";
import { walkAst, locStart, type AstNode } from "../../utils/parse.js";

/** Matches `*.test.js`/`*.spec.tsx`/etc — the App Builder test-file naming convention. */
const TEST_FILE_RE = /\.(test|spec)\.[jt]sx?$/i;

function isTestFile(filePath: string): boolean {
  return TEST_FILE_RE.test(filePath);
}

function identName(n: AstNode | undefined): string | undefined {
  if (!n || n.type !== "Identifier") return undefined;
  return (n as AstNode & { name?: string }).name;
}

function member(n: AstNode, key: string): AstNode | undefined {
  const v = (n as Record<string, unknown>)[key];
  return v && typeof v === "object" && "type" in (v as object) ? (v as AstNode) : undefined;
}

function callArgs(n: AstNode): AstNode[] {
  return (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
}

function literalString(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Literal") {
    const v = (n as AstNode & { value?: unknown }).value;
    return typeof v === "string" ? v : undefined;
  }
  return undefined;
}

function keyName(n: AstNode | undefined): string | undefined {
  if (!n) return undefined;
  if (n.type === "Identifier") return identName(n);
  return literalString(n);
}

// ---------------------------------------------------------------------------
// 1. testing/coverage/cover-at-least-80-of-branches-in-actions — coverage-threshold-config
// ---------------------------------------------------------------------------

const COVERAGE_KEYS = ["branches", "functions", "lines", "statements"] as const;

async function findCoverageThresholds(
  ctx: RepoContext,
): Promise<{ source: string; values: Partial<Record<(typeof COVERAGE_KEYS)[number], number>> } | null> {
  const pkgPath = join(ctx.projectRoot, "package.json");
  try {
    const raw = await readFile(pkgPath, "utf8");
    const pkg = JSON.parse(raw) as { jest?: { coverageThreshold?: { global?: Record<string, unknown> } } };
    const global = pkg.jest?.coverageThreshold?.global;
    if (global && typeof global === "object") {
      const values: Partial<Record<(typeof COVERAGE_KEYS)[number], number>> = {};
      for (const key of COVERAGE_KEYS) {
        const v = global[key];
        if (typeof v === "number") values[key] = v;
      }
      return { source: pkgPath, values };
    }
  } catch {
    // no package.json, invalid JSON, or no jest field — fall through to jest.config.*
  }

  const jestConfigPath = ctx.allFiles.find((f) => /(^|\/)jest\.config\.(js|cjs|mjs|ts|json)$/i.test(f.replace(/\\/g, "/")));
  if (!jestConfigPath) return null;
  try {
    const raw = await readFile(jestConfigPath, "utf8");
    // jest.config.js may be an executable module — never eval it. Regex-extract
    // the coverageThreshold.global block and pull number literals near each key.
    const match = /coverageThreshold\s*:\s*\{[\s\S]*?global\s*:\s*\{([\s\S]*?)\}/.exec(raw);
    if (!match) return null;
    const body = match[1] ?? "";
    const values: Partial<Record<(typeof COVERAGE_KEYS)[number], number>> = {};
    for (const key of COVERAGE_KEYS) {
      const m = new RegExp(`${key}\\s*:\\s*(\\d+)`).exec(body);
      if (m?.[1]) values[key] = Number(m[1]);
    }
    if (Object.keys(values).length === 0) return null;
    return { source: jestConfigPath, values };
  } catch {
    return null;
  }
}

export const coverageThresholdConfigChecker: RepoChecker = {
  ruleId: "testing/coverage/cover-at-least-80-of-branches-in-actions",
  kind: "repo",
  async check(ctx) {
    // Checks only that the coverage gate is CONFIGURED — verifying actual measured
    // coverage would require running the test suite, which is out of scope for a
    // static check.
    const hits: CheckHit[] = [];
    const found = await findCoverageThresholds(ctx);
    if (!found) {
      hits.push({
        line: 1,
        message:
          "No jest coverageThreshold.global configuration found (package.json `jest` field or jest.config.*) — the coverage gate is not enforced",
      });
      return hits;
    }
    for (const key of COVERAGE_KEYS) {
      const v = found.values[key];
      if (typeof v === "number" && v < 80) {
        hits.push({
          line: 1,
          path: found.source,
          message: `Coverage threshold for '${key}' is ${v}, below the required 80`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 2. testing/coverage/snapshot-tests-for-stable-json-shapes-only — snapshot-of-render
// ---------------------------------------------------------------------------

export const snapshotOfRenderChecker: FileChecker = {
  ruleId: "testing/coverage/snapshot-tests-for-stable-json-shapes-only",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;

    const renderVars = new Set<string>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const id = member(n, "id");
      const init = member(n, "init");
      if (init?.type !== "CallExpression" || identName(member(init, "callee")) !== "render") return;
      if (id?.type === "Identifier") {
        const name = identName(id);
        if (name) renderVars.add(name);
      } else if (id?.type === "ObjectPattern") {
        // `const { container } = render(...)` / `const { asFragment } = render(...)` —
        // the destructured binding is itself render-derived, equivalent to a later
        // `wrapper.container` / `wrapper.asFragment()` access on a whole-object binding.
        const props = (id as AstNode & { properties?: AstNode[] }).properties ?? [];
        for (const p of props) {
          const key = member(p, "key");
          const keyName = identName(key);
          const value = member(p, "value");
          if ((keyName === "container" || keyName === "asFragment") && value?.type === "Identifier") {
            const name = identName(value);
            if (name) renderVars.add(name);
          }
        }
      }
    });

    function isRenderDerived(arg: AstNode | undefined): boolean {
      if (!arg) return false;
      if (arg.type === "Identifier") return renderVars.has(identName(arg) ?? "");
      if (arg.type === "MemberExpression") {
        const obj = member(arg, "object");
        const propName = identName(member(arg, "property"));
        return propName === "container" && obj?.type === "Identifier" && renderVars.has(identName(obj) ?? "");
      }
      if (arg.type === "CallExpression") {
        const callee = member(arg, "callee");
        if (callee?.type === "Identifier") {
          // Destructured `const { asFragment } = render(...)` then `expect(asFragment())`.
          return renderVars.has(identName(callee) ?? "");
        }
        if (callee?.type !== "MemberExpression") return false;
        const obj = member(callee, "object");
        const propName = identName(member(callee, "property"));
        return propName === "asFragment" && obj?.type === "Identifier" && renderVars.has(identName(obj) ?? "");
      }
      return false;
    }

    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression" || identName(member(callee, "property")) !== "toMatchSnapshot") return;
      const receiver = member(callee, "object");
      if (receiver?.type !== "CallExpression" || identName(member(receiver, "callee")) !== "expect") return;
      const arg = callArgs(receiver)[0];
      if (isRenderDerived(arg)) {
        hits.push({
          ...locStart(n),
          message: "toMatchSnapshot() on a render() result produces churn-y HTML snapshots — assert specific text/roles instead",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 3. testing/fixtures/test-against-the-cloudevents-spec... — cloudevents-fixture-shape
// ---------------------------------------------------------------------------

const REQUIRED_CE_KEYS = ["specversion", "type", "source", "id", "time"];

export const cloudeventsFixtureShapeChecker: FileChecker = {
  ruleId: "testing/fixtures/test-against-the-cloudevents-spec-for-i-o-events-handler-payloads",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    if (!/event|webhook/i.test(ctx.filePath)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;

    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression" || identName(member(callee, "property")) !== "main") return;
      for (const arg of callArgs(n)) {
        if (arg.type !== "ObjectExpression") continue;
        const props = (arg as AstNode & { properties?: AstNode[] }).properties ?? [];
        const keys = new Set(props.map((p) => keyName(member(p, "key"))).filter((k): k is string => !!k));
        const missing = REQUIRED_CE_KEYS.filter((k) => !keys.has(k));
        if (missing.length > 0) {
          hits.push({
            ...locStart(arg),
            message: `CloudEvents test fixture is missing required field(s): ${missing.join(", ")}`,
          });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 4. testing/layout/test-files-mirror-source-structure-under-test-actions — test-layout-mirror
// ---------------------------------------------------------------------------

export const testLayoutMirrorChecker: RepoChecker = {
  ruleId: "testing/layout/test-files-mirror-source-structure-under-test-actions",
  kind: "repo",
  check(ctx) {
    const hits: CheckHit[] = [];
    const norm = (p: string) => p.replace(/\\/g, "/");
    const fileSet = new Set(ctx.allFiles.map(norm));
    for (const file of ctx.allFiles) {
      const nf = norm(file);
      const m = /(^|\/)actions\/(.+)\/index\.(js|ts)$/.exec(nf);
      if (!m) continue;
      const rel = m[2];
      const ext = m[3];
      if (!rel || !ext) continue;
      const expected = norm(join(ctx.projectRoot, "test", "actions", `${rel}.test.${ext}`));
      if (fileSet.has(expected)) continue;
      const dir = norm(dirname(file));
      const hasColocated = ctx.allFiles.some((f) => {
        const nf2 = norm(f);
        return norm(dirname(f)) === dir && /\.test\.(js|ts)$/i.test(nf2);
      });
      if (hasColocated) {
        hits.push({
          path: file,
          line: 1,
          message: `Test for '${nf}' is co-located next to the source instead of mirrored at 'test/actions/${rel}.test.${ext}'`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 5. testing/layout/use-aio-app-test-not-raw-jest — raw-jest-ci
//    (also the target for deployment/pipeline/ci-must-run-aio-app-test-not-just-jest — duplicate, implemented once)
// ---------------------------------------------------------------------------

export const rawJestCiChecker: RepoChecker = {
  ruleId: "testing/layout/use-aio-app-test-not-raw-jest",
  kind: "repo",
  async check(ctx) {
    const candidates = ctx.allFiles.filter((f) => {
      const n = f.replace(/\\/g, "/");
      return /\.github\/workflows\/[^/]+\.ya?ml$/i.test(n) || /(^|\/)package\.json$/.test(n);
    });

    const hits: CheckHit[] = [];
    let hasAioAppTest = false;
    const jestHits: Array<{ file: string; line: number }> = [];

    for (const file of candidates) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      if (/aio\s+app\s+test/.test(content)) hasAioAppTest = true;
      const isPkg = /(^|\/)package\.json$/.test(file.replace(/\\/g, "/"));
      const jestCliRe = isPkg ? /"[^"]+"\s*:\s*"[^"]*\bjest\b[^"]*"/ : /run:\s*.*\bjest\b/;
      content.split("\n").forEach((line, idx) => {
        if (jestCliRe.test(line) && !/aio\s+app\s+test/.test(line)) {
          jestHits.push({ file, line: idx + 1 });
        }
      });
    }

    if (jestHits.length > 0 && !hasAioAppTest) {
      for (const jh of jestHits) {
        hits.push({
          path: jh.file,
          line: jh.line,
          message: "CI/scripts invoke `jest` directly — use `aio app test` so App Builder's test harness (mocks, env setup) is applied",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 6. testing/layout/test-descriptions-describe-behavior-not-implementation — vague-test-title
// ---------------------------------------------------------------------------

const VAGUE_TITLE_NUMBERED_RE = /^test ?\d*$/i;
const VAGUE_TITLE_WORDS_RE = /^(works|it works|handles it)$/i;

export const vagueTestTitleChecker: FileChecker = {
  ruleId: "testing/layout/test-descriptions-describe-behavior-not-implementation",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const name = identName(member(n, "callee"));
      if (!name || !["it", "test", "describe"].includes(name)) return;
      const first = callArgs(n)[0];
      const value = literalString(first);
      if (value === undefined) return;
      const trimmed = value.trim();
      if (VAGUE_TITLE_NUMBERED_RE.test(trimmed) || VAGUE_TITLE_WORDS_RE.test(trimmed)) {
        hits.push({ ...locStart(n), message: `Vague test title '${trimmed}' — describe the behavior under test, not a placeholder` });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 7. testing/mocking/mock-adobe-aio-sdk-core-logger-as-a-jest-fn — unmocked-core-logger
// ---------------------------------------------------------------------------

export const unmockedCoreLoggerChecker: FileChecker = {
  ruleId: "testing/mocking/mock-adobe-aio-sdk-core-logger-as-a-jest-fn",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const testsAction = /(require\(|from\s+)['"][^'"]*\/actions\/[^'"]*['"]/.test(ctx.content);
    if (!testsAction) return [];
    const mocksAioSdk = /jest\.mock\(\s*['"]@adobe\/aio-sdk['"]/.test(ctx.content);
    if (mocksAioSdk) return [];
    return [
      {
        line: 1,
        message:
          "Test exercises an actions/ module but never calls jest.mock('@adobe/aio-sdk') — Core.Logger will write real logs during tests",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 8. testing/mocking/reset-mocks-in-beforeeach... — missing-clear-mocks
// ---------------------------------------------------------------------------

export const missingClearMocksChecker: FileChecker = {
  ruleId: "testing/mocking/reset-mocks-in-beforeeach-never-let-state-leak-between-tests",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;

    let hasJestMockUsage = false;
    let hasClearInBeforeEach = false;

    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");

      if (callee?.type === "MemberExpression") {
        const obj = identName(member(callee, "object"));
        const prop = identName(member(callee, "property"));
        if (obj === "jest" && (prop === "fn" || prop === "mock")) hasJestMockUsage = true;
      }

      if (identName(callee) === "beforeEach") {
        const args = callArgs(n);
        const fn = args[args.length - 1];
        if (fn && (fn.type === "ArrowFunctionExpression" || fn.type === "FunctionExpression")) {
          walkAst(fn, (inner) => {
            if (inner.type !== "CallExpression") return;
            const c = member(inner, "callee");
            if (c?.type !== "MemberExpression") return;
            const o = identName(member(c, "object"));
            const p = identName(member(c, "property"));
            if (o === "jest" && (p === "clearAllMocks" || p === "resetAllMocks")) hasClearInBeforeEach = true;
          });
        }
      }
    });

    if (hasJestMockUsage && !hasClearInBeforeEach) {
      return [
        {
          line: 1,
          message:
            "File uses jest.fn()/jest.mock() but has no beforeEach(() => jest.clearAllMocks()/resetAllMocks()) — mock state can leak between tests",
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 9. testing/mocking/mock-external-http... — unmocked-http-in-unit-test
// ---------------------------------------------------------------------------

async function tryReadActionSource(testFilePath: string, importPath: string): Promise<string | null> {
  if (!importPath.startsWith(".")) return null;
  const dir = dirname(testFilePath);
  const base = join(dir, importPath);
  const candidates = [base, `${base}.js`, `${base}.ts`, join(base, "index.js"), join(base, "index.ts")];
  for (const candidate of candidates) {
    try {
      return await readFile(candidate, "utf8");
    } catch {
      continue;
    }
  }
  return null;
}

export const unmockedHttpInUnitTestChecker: FileChecker = {
  ruleId: "testing/mocking/mock-external-http-never-hit-real-apis-in-unit-tests",
  kind: "file",
  appliesTo: (p) => (/\/test\/actions\//.test(p) || /\/__tests__\//.test(p)) && !/e2eTest/.test(p),
  async check(ctx) {
    const importRe = /(?:require\(|from\s+)['"]([^'"]*\/actions\/[^'"]*)['"]/g;
    let m: RegExpExecArray | null;
    let foundActionImport = false;
    let actionSourceHasHttp = false;
    while ((m = importRe.exec(ctx.content)) !== null) {
      const importPath = m[1];
      if (!importPath) continue;
      foundActionImport = true;
      const src = await tryReadActionSource(ctx.filePath, importPath);
      if (src && (/\bfetch\s*\(/.test(src) || /\baxios[.(]/.test(src))) {
        actionSourceHasHttp = true;
      }
    }
    if (!foundActionImport || !actionSourceHasHttp) return [];

    const hasMock =
      /\bnock\s*\(/.test(ctx.content) ||
      /\bmsw\b/.test(ctx.content) ||
      /jest\.mock\(\s*['"](axios|node-fetch|https?)['"]/.test(ctx.content);
    if (hasMock) return [];

    return [
      {
        line: 1,
        message:
          "Test imports an action that calls fetch/axios but never mocks the HTTP client (nock/msw/jest.mock) — risk of hitting real APIs in unit tests",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 10. testing/rtl/react-tests-use-testing-library-queries... — enzyme-import
// ---------------------------------------------------------------------------

export const enzymeImportChecker: FileChecker = {
  ruleId: "testing/rtl/react-tests-use-testing-library-queries-never-enzyme-or-raw-dom",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    ctx.content.split("\n").forEach((line, idx) => {
      if (/require\(\s*['"]enzyme['"]\s*\)|from\s+['"]enzyme['"]/.test(line)) {
        hits.push({ line: idx + 1, message: "Imports 'enzyme' — deprecated for React 19; use @testing-library/react queries instead" });
      }
    });

    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return hits;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression" || identName(member(callee, "property")) !== "find") return;
      const arg = callArgs(n)[0];
      const value = literalString(arg);
      if (value !== undefined && /^[.#]/.test(value)) {
        hits.push({
          ...locStart(n),
          message: `'.find(${JSON.stringify(value)})' is a CSS-selector-style Enzyme query — use Testing Library role/text/label queries instead`,
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 11. testing/rtl/test-user-behavior-not-implementation-details — implementation-detail-test
// ---------------------------------------------------------------------------

export const implementationDetailTestChecker: FileChecker = {
  ruleId: "testing/rtl/test-user-behavior-not-implementation-details",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;

    const wrapperVars = new Set<string>();
    walkAst(parsed.ast, (n) => {
      if (n.type !== "VariableDeclarator") return;
      const id = member(n, "id");
      const init = member(n, "init");
      if (id?.type !== "Identifier" || init?.type !== "CallExpression") return;
      const calleeName = identName(member(init, "callee"));
      if (calleeName === "render" || calleeName === "mount" || calleeName === "shallow") {
        const name = identName(id);
        if (name) wrapperVars.add(name);
      }
    });

    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type === "CallExpression") {
        const callee = member(n, "callee");
        if (callee?.type === "MemberExpression") {
          const obj = identName(member(callee, "object"));
          const prop = identName(member(callee, "property"));
          if (obj === "jest" && prop === "spyOn") {
            const firstArg = callArgs(n)[0];
            if (identName(firstArg) === "React") {
              hits.push({
                ...locStart(n),
                message: "jest.spyOn(React, ...) couples the test to React's internals — assert on rendered output instead",
              });
            }
          }
          if (prop === "instance") {
            hits.push({
              ...locStart(n),
              message: "'.instance()' reaches into a component instance's internals — assert on rendered output instead",
            });
          }
        }
      }
      if (n.type === "MemberExpression") {
        const obj = member(n, "object");
        const prop = identName(member(n, "property"));
        if (prop === "state" && obj?.type === "Identifier" && wrapperVars.has(identName(obj) ?? "")) {
          hits.push({
            ...locStart(n),
            message: "Direct '.state' access on a rendered wrapper couples the test to implementation — assert on visible output instead",
          });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 12. testing/rtl/use-waitfor-findby-for-async-ui... — raw-settimeout-in-test
// ---------------------------------------------------------------------------

export const rawSettimeoutInTestChecker: FileChecker = {
  ruleId: "testing/rtl/use-waitfor-findby-for-async-ui-never-raw-settimeout",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "NewExpression" || identName(member(n, "callee")) !== "Promise") return;
      const executor = callArgs(n)[0];
      if (!executor || (executor.type !== "ArrowFunctionExpression" && executor.type !== "FunctionExpression")) return;
      let usesSetTimeout = false;
      walkAst(executor, (inner) => {
        if (inner.type === "CallExpression" && identName(member(inner, "callee")) === "setTimeout") usesSetTimeout = true;
      });
      if (usesSetTimeout) {
        hits.push({
          ...locStart(n),
          message: "Manual `new Promise(resolve => setTimeout(...))` sleep — use waitFor/findBy* instead of a raw timer",
        });
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 13. testing/strategy/every-action-test-must-verify-main-is-exported — missing-main-export-test
// ---------------------------------------------------------------------------

function containsMainAccess(node: AstNode): boolean {
  let has = false;
  walkAst(node, (n) => {
    if (n.type === "Identifier" && identName(n) === "main") has = true;
    if (n.type === "MemberExpression" && identName(member(n, "property")) === "main") has = true;
  });
  return has;
}

export const missingMainExportTestChecker: FileChecker = {
  ruleId: "testing/strategy/every-action-test-must-verify-main-is-exported",
  kind: "file",
  appliesTo: isTestFile,
  check(ctx) {
    const relevant =
      /\/test\/actions\//.test(ctx.filePath) || /(require\(|from\s+)['"][^'"]*\/actions\/[^'"]*['"]/.test(ctx.content);
    if (!relevant) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;

    let found = false;
    walkAst(parsed.ast, (n) => {
      if (found || n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type !== "MemberExpression" || identName(member(callee, "property")) !== "toBe") return;
      const arg = callArgs(n)[0];
      if (literalString(arg) !== "function") return;

      const receiver = member(callee, "object");
      if (receiver?.type !== "CallExpression" || identName(member(receiver, "callee")) !== "expect") return;
      const expectArg = callArgs(receiver)[0];
      if (expectArg?.type === "UnaryExpression" && (expectArg as AstNode & { operator?: string }).operator === "typeof") {
        const operand = member(expectArg, "argument");
        if (operand && containsMainAccess(operand)) found = true;
      }
    });

    if (!found) {
      return [
        {
          line: 1,
          message:
            "No `expect(typeof ...main).toBe('function')` assertion found — action tests must verify main is exported as a function",
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 14. testing/strategy/test-the-missing-input-400-path — missing-400-path-test
// ---------------------------------------------------------------------------

export const missing400PathTestChecker: FileChecker = {
  ruleId: "testing/strategy/test-the-missing-input-400-path",
  kind: "file",
  appliesTo: (p) => /\/test\/actions\//.test(p),
  check(ctx) {
    if (!/\bmain\s*\(/.test(ctx.content)) return [];
    const has400 =
      /statusCode\s*===?\s*400/.test(ctx.content) ||
      /\.error\.statusCode\s*===?\s*400/.test(ctx.content) ||
      /400\s*===?\s*[\w.]*statusCode/.test(ctx.content) ||
      // Jest assertion idiom: expect(x.statusCode).toBe(400) / .toEqual(400)
      /statusCode\s*\)\s*\.to(?:Be|Equal)\(\s*400\s*\)/.test(ctx.content);
    if (has400) return [];
    return [
      {
        line: 1,
        message:
          "No assertion checking statusCode === 400 found — approximate check: verify the action's missing-input (400) error path is tested",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 15. testing/strategy/test-the-error-response-shape... — missing-error-shape-test
// ---------------------------------------------------------------------------

export const missingErrorShapeTestChecker: FileChecker = {
  ruleId: "testing/strategy/test-the-error-response-shape-error-statuscode-body",
  kind: "file",
  appliesTo: (p) => /\/test\/actions\//.test(p),
  check(ctx) {
    const hasDirectChain = /\.error\.statusCode\b/.test(ctx.content);
    const hasSeparateAssertions = /\.error\)\.toBeDefined/.test(ctx.content) && /\.statusCode\b/.test(ctx.content);
    if (hasDirectChain || hasSeparateAssertions) return [];
    return [
      {
        line: 1,
        message: "No assertion verifies the error response shape { error: { statusCode, body } } — test both .error and .statusCode",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 16. testing/strategy/e2etest-folder-for-cross-action-and-frontend-to-action-flows — e2etest-folder
// ---------------------------------------------------------------------------

export const e2etestFolderChecker: RepoChecker = {
  ruleId: "testing/strategy/e2etest-folder-for-cross-action-and-frontend-to-action-flows",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const testFiles = ctx.allFiles.filter((f) => isTestFile(f) && !/e2eTest/.test(f));
    for (const file of testFiles) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      const actionDirs = new Set<string>();
      const re = /['"][^'"]*\/actions\/([^'"/]+)\/[^'"]*['"]/g;
      let m: RegExpExecArray | null;
      while ((m = re.exec(content)) !== null) {
        if (m[1]) actionDirs.add(m[1]);
      }
      if (actionDirs.size >= 2) {
        hits.push({
          path: file,
          line: 1,
          message: `Test references ${actionDirs.size} different actions/ modules (${[...actionDirs].join(", ")}) — cross-action tests belong under an e2eTest/ folder`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 17. testing/strategy/separate-smoke-tests-from-e2e... — smoke-e2e-separation
// ---------------------------------------------------------------------------

export const smokeE2eSeparationChecker: RepoChecker = {
  ruleId: "testing/strategy/separate-smoke-tests-from-e2e-smoke-verifies-deploy-e2e-verifies",
  kind: "repo",
  check(ctx) {
    const norm = (p: string) => p.replace(/\\/g, "/");
    const hasE2e = ctx.allFiles.some((f) => /e2eTest|test\/e2e/.test(norm(f)));
    const hasSmoke = ctx.allFiles.some((f) => /smoke/i.test(norm(f)));
    if (hasE2e && !hasSmoke) {
      return [
        {
          line: 1,
          message:
            "Repo has an e2e test folder but no separate smoke/ test directory — smoke tests (verify deploy) should be kept apart from e2e tests (verify flows)",
        },
      ];
    }
    return [];
  },
};
