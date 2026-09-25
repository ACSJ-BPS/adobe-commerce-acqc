import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdtemp, mkdir } from "node:fs/promises";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import type { CheckContext, FileChecker, RepoChecker } from "../types.js";
import {
  requireAuthFalseUndocumentedChecker,
  secretInStatePutChecker,
  logRawParamsChecker,
  corsWildcardAuthenticatedChecker,
  missingTenantIsolationChecker,
  implicitFlowOrNoPkceChecker,
  tokenInLocalStorageChecker,
  missingIoMgmtScopeChecker,
  unusedOauthScopeChecker,
} from "../checkers/builtins/security-adobe.js";
import {
  timingUnsafeCompareChecker,
  bidiCharsChecker,
  unvalidatedDeepMergeChecker,
  ssrfUserUrlChecker,
  massAssignmentChecker,
  httpLiteralUrlChecker,
  sqlStringConcatChecker,
  weakPasswordHashChecker,
  missingCspHeaderChecker,
  tlsVerificationDisabledChecker,
  unloggedAuthDenialChecker,
  secretInUrlQueryChecker,
} from "../checkers/builtins/security-owasp.js";

const __dir = dirname(fileURLToPath(import.meta.url));
const fixtures = join(__dir, "../../src/test/fixtures");

interface FileCase {
  name: string;
  checker: FileChecker;
  bad: string;
  good: string;
  filePath: string;
}

const FILE_CASES: FileCase[] = [
  {
    name: "timing-unsafe-compare",
    checker: timingUnsafeCompareChecker,
    bad: "bad-timing-unsafe-compare.js",
    good: "good-timing-unsafe-compare.js",
    filePath: "/proj/src/webhook.js",
  },
  {
    name: "require-auth-false-undocumented",
    checker: requireAuthFalseUndocumentedChecker,
    bad: "bad-require-auth-undocumented.yaml",
    good: "good-require-auth-undocumented.yaml",
    filePath: "/proj/actions.config.yaml",
  },
  {
    name: "secret-in-state-put",
    checker: secretInStatePutChecker,
    bad: "bad-secret-in-state-put.js",
    good: "good-secret-in-state-put.js",
    filePath: "/proj/actions/query-db/index.js",
  },
  {
    name: "log-raw-params",
    checker: logRawParamsChecker,
    bad: "bad-log-raw-params.js",
    good: "good-log-raw-params.js",
    filePath: "/proj/actions/log-action/index.js",
  },
  {
    name: "cors-wildcard-authenticated",
    checker: corsWildcardAuthenticatedChecker,
    bad: "bad-cors-wildcard.js",
    good: "good-cors-wildcard.js",
    filePath: "/proj/actions/cors-action/index.js",
  },
  {
    name: "missing-tenant-isolation",
    checker: missingTenantIsolationChecker,
    bad: "bad-missing-tenant-isolation.js",
    good: "good-missing-tenant-isolation.js",
    filePath: "/proj/actions/get-order/index.js",
  },
  {
    name: "implicit-flow-or-no-pkce",
    checker: implicitFlowOrNoPkceChecker,
    bad: "bad-implicit-flow.js",
    good: "good-implicit-flow.js",
    filePath: "/proj/web-src/src/auth.js",
  },
  {
    name: "token-in-localstorage",
    checker: tokenInLocalStorageChecker,
    bad: "bad-token-in-localstorage.js",
    good: "good-token-in-localstorage.js",
    filePath: "/proj/web-src/src/session.js",
  },
  {
    name: "bidi-chars",
    checker: bidiCharsChecker,
    bad: "bad-bidi-chars.js",
    good: "good-bidi-chars.js",
    filePath: "/proj/src/message.js",
  },
  {
    name: "unvalidated-deep-merge",
    checker: unvalidatedDeepMergeChecker,
    bad: "bad-unvalidated-deep-merge.js",
    good: "good-unvalidated-deep-merge.js",
    filePath: "/proj/src/profile.js",
  },
  {
    name: "ssrf-user-url",
    checker: ssrfUserUrlChecker,
    bad: "bad-ssrf-user-url.js",
    good: "good-ssrf-user-url.js",
    filePath: "/proj/actions/webhook/index.js",
  },
  {
    name: "mass-assignment",
    checker: massAssignmentChecker,
    bad: "bad-mass-assignment.js",
    good: "good-mass-assignment.js",
    filePath: "/proj/actions/update-order/index.js",
  },
  {
    name: "http-literal-url",
    checker: httpLiteralUrlChecker,
    bad: "bad-http-literal-url.js",
    good: "good-http-literal-url.js",
    filePath: "/proj/src/pricing.js",
  },
  {
    name: "sql-string-concat",
    checker: sqlStringConcatChecker,
    bad: "bad-sql-string-concat.js",
    good: "good-sql-string-concat.js",
    filePath: "/proj/src/db.js",
  },
  {
    name: "weak-password-hash",
    checker: weakPasswordHashChecker,
    bad: "bad-weak-password-hash.js",
    good: "good-weak-password-hash.js",
    filePath: "/proj/src/auth.js",
  },
  {
    name: "missing-csp-header",
    checker: missingCspHeaderChecker,
    bad: "bad-missing-csp-header.js",
    good: "good-missing-csp-header.js",
    filePath: "/proj/actions/web-response/index.js",
  },
  {
    name: "tls-verification-disabled",
    checker: tlsVerificationDisabledChecker,
    bad: "bad-tls-verification-disabled.js",
    good: "good-tls-verification-disabled.js",
    filePath: "/proj/src/https-client.js",
  },
  {
    name: "unlogged-auth-denial",
    checker: unloggedAuthDenialChecker,
    bad: "bad-unlogged-auth-denial.js",
    good: "good-unlogged-auth-denial.js",
    filePath: "/proj/actions/protected/index.js",
  },
  {
    name: "secret-in-url-query",
    checker: secretInUrlQueryChecker,
    bad: "bad-secret-in-url-query.js",
    good: "good-secret-in-url-query.js",
    filePath: "/proj/src/url-builder.js",
  },
];

async function runFileCheck(checker: FileChecker, fixtureName: string, filePath: string) {
  const content = await readFile(join(fixtures, fixtureName), "utf8");
  const ctx: CheckContext = {
    filePath,
    content,
    ext: filePath.slice(filePath.lastIndexOf(".")),
    projectRoot: "/proj",
    actionEntryFiles: filePath.includes("/actions/") ? new Set([filePath]) : undefined,
  };
  assert.ok(checker.appliesTo(filePath), `checker.appliesTo should be true for ${filePath}`);
  return checker.check(ctx);
}

describe("security domain batch — file-scoped builtins", () => {
  for (const c of FILE_CASES) {
    it(`${c.name} flags bad fixture`, async () => {
      const hits = await runFileCheck(c.checker, c.bad, c.filePath);
      assert.ok(hits.length > 0, `expected findings for ${c.bad}`);
    });
    it(`${c.name} passes good fixture`, async () => {
      const hits = await runFileCheck(c.checker, c.good, c.filePath);
      assert.equal(hits.length, 0, `expected no findings for ${c.good}`);
    });
  }
});

describe("security domain batch — repo-scoped builtins", () => {
  it("missing-io-mgmt-scope flags a services list with no IOManagementSDK/read_organizations", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-bad-"));
    const configContent = await readFile(join(fixtures, "bad-missing-io-mgmt-scope.yaml"), "utf8");
    const configPath = join(root, "app.config.yaml");
    await writeFile(configPath, configContent, "utf8");
    const hits = await (missingIoMgmtScopeChecker as RepoChecker).check({
      projectRoot: root,
      allFiles: [configPath],
    });
    assert.ok(hits.length > 0);
  });

  it("missing-io-mgmt-scope passes when IOManagementSDK is declared", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-good-"));
    const configContent = await readFile(join(fixtures, "good-missing-io-mgmt-scope.yaml"), "utf8");
    const configPath = join(root, "app.config.yaml");
    await writeFile(configPath, configContent, "utf8");
    const hits = await (missingIoMgmtScopeChecker as RepoChecker).check({
      projectRoot: root,
      allFiles: [configPath],
    });
    assert.equal(hits.length, 0);
  });

  it("missing-io-mgmt-scope returns no hits when no app.config.yaml is present", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-none-"));
    const hits = await (missingIoMgmtScopeChecker as RepoChecker).check({ projectRoot: root, allFiles: [] });
    assert.equal(hits.length, 0);
  });

  it("unused-oauth-scope flags a declared scope with zero references in source", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-bad-"));
    const configContent = await readFile(join(fixtures, "bad-unused-oauth-scope.yaml"), "utf8");
    const sourceContent = await readFile(join(fixtures, "unused-oauth-scope-source.js"), "utf8");
    const configPath = join(root, "app.config.yaml");
    const sourcePath = join(root, "actions", "demo", "index.js");
    await mkdir(join(root, "actions", "demo"), { recursive: true });
    await writeFile(configPath, configContent, "utf8");
    await writeFile(sourcePath, sourceContent, "utf8");
    const hits = await (unusedOauthScopeChecker as RepoChecker).check({
      projectRoot: root,
      allFiles: [configPath, sourcePath],
    });
    assert.ok(hits.length > 0);
    assert.ok(hits.some((h) => h.message.includes("write_organizations")));
  });

  it("unused-oauth-scope passes when every declared scope is referenced somewhere", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-repo-good-"));
    const configContent = await readFile(join(fixtures, "good-unused-oauth-scope.yaml"), "utf8");
    const sourceContent = await readFile(join(fixtures, "unused-oauth-scope-source.js"), "utf8");
    const configPath = join(root, "app.config.yaml");
    const sourcePath = join(root, "actions", "demo", "index.js");
    await mkdir(join(root, "actions", "demo"), { recursive: true });
    await writeFile(configPath, configContent, "utf8");
    await writeFile(sourcePath, sourceContent, "utf8");
    const hits = await (unusedOauthScopeChecker as RepoChecker).check({
      projectRoot: root,
      allFiles: [configPath, sourcePath],
    });
    assert.equal(hits.length, 0);
  });
});
