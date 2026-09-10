import { join } from "node:path";
import { access, readFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { parse as parseYaml } from "yaml";
import type { CheckHit, FileChecker, RepoChecker, RepoContext } from "../../types.js";
import { isActionFile, isSourceFile } from "@abqc/core";
import { findFunctions, walkAst, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

// ---------------------------------------------------------------------------
// Shared helpers (deployment domain)
// ---------------------------------------------------------------------------

function normalizePath(p: string): string {
  return p.replace(/\\/g, "/");
}

async function readSafe(p: string): Promise<string | null> {
  try {
    return await readFile(p, "utf8");
  } catch {
    return null;
  }
}

async function fileExists(p: string): Promise<boolean> {
  try {
    await access(p);
    return true;
  } catch {
    return false;
  }
}

function isWorkflowFile(p: string): boolean {
  return /\.github\/workflows\/[^/]+\.ya?ml$/i.test(normalizePath(p));
}

interface WorkflowFile {
  path: string;
  content: string;
}

async function listWorkflows(ctx: RepoContext): Promise<WorkflowFile[]> {
  const out: WorkflowFile[] = [];
  for (const f of ctx.allFiles) {
    if (!isWorkflowFile(f)) continue;
    const content = await readSafe(f);
    if (content !== null) out.push({ path: f, content });
  }
  return out;
}

interface WfStep {
  name?: string;
  run?: string;
  uses?: string;
}

interface WfJob {
  needs?: string | string[];
  if?: string;
  name?: string;
  steps?: WfStep[];
}

interface WfDoc {
  jobs?: Record<string, WfJob>;
}

/** Best-effort YAML parse of a GitHub Actions workflow — returns null on any parse failure so callers can skip the file rather than crash on a malformed/templated workflow. */
function tryParseWorkflow(content: string): WfDoc | null {
  try {
    const doc = parseYaml(content);
    if (doc && typeof doc === "object") return doc as WfDoc;
    return null;
  } catch {
    return null;
  }
}

function jobText(job: WfJob, jobKey: string): string {
  const parts = [jobKey, job.name ?? ""];
  for (const s of job.steps ?? []) {
    parts.push(s.name ?? "", s.run ?? "", s.uses ?? "");
  }
  return parts.join(" ");
}

// ---------------------------------------------------------------------------
// 1. no-stage-before-prod
// ---------------------------------------------------------------------------

export const noStageBeforeProdChecker: RepoChecker = {
  ruleId: "deployment/environments/deploy-to-a-stage-workspace-before-production-never-straight-to-",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const prodRe = /--workspace[=\s]+production/gi;
    const stageRe = /--workspace[=\s]+stage/gi;
    for (const wf of await listWorkflows(ctx)) {
      const stageIdxs: number[] = [];
      stageRe.lastIndex = 0;
      let sm: RegExpExecArray | null;
      while ((sm = stageRe.exec(wf.content)) !== null) stageIdxs.push(sm.index);

      prodRe.lastIndex = 0;
      let pm: RegExpExecArray | null;
      while ((pm = prodRe.exec(wf.content)) !== null) {
        const gatedByEarlierStage = stageIdxs.some((si) => si < pm!.index);
        if (!gatedByEarlierStage) {
          const line = wf.content.slice(0, pm.index).split("\n").length;
          hits.push({
            line,
            path: wf.path,
            message:
              "Workflow deploys to '--workspace Production' with no preceding '--workspace Stage' step in the same file — deploy to Stage first, run smoke/e2e, then Production.",
          });
          break;
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 2. env-branching-logic — STATIC (heuristic) (P5)
// ---------------------------------------------------------------------------

function isEnvVarRead(node: AstNode | undefined): boolean {
  if (!node || node.type !== "MemberExpression") return false;
  const obj = member(node, "object");
  const prop = member(node, "property");
  if (!obj || obj.type !== "MemberExpression") return false;
  const inner = member(obj, "object");
  const innerProp = member(obj, "property");
  if (identName(inner) !== "process" || identName(innerProp) !== "env") return false;
  const propName =
    identName(prop) ?? (prop?.type === "Literal" ? String((prop as AstNode & { value?: unknown }).value) : undefined);
  return !!propName && /^(NODE_ENV|APP_ENV|ENVIRONMENT|ENV)$/i.test(propName);
}

function containsEnvVarRead(node: AstNode): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (!found && isEnvVarRead(n)) found = true;
  });
  return found;
}

function isUrlLikeName(name: string | undefined): boolean {
  return !!name && /url|endpoint|host/i.test(name);
}

function assignmentTargetName(n: AstNode): string | undefined {
  if (n.type === "VariableDeclarator") {
    const id = member(n, "id");
    if (id?.type === "Identifier") return identName(id);
  }
  if (n.type === "AssignmentExpression") {
    const left = member(n, "left");
    if (left?.type === "Identifier") return identName(left);
    if (left?.type === "MemberExpression") {
      const prop = member(left, "property");
      return identName(prop);
    }
  }
  return undefined;
}

function hasUrlLikeAssignment(node: AstNode): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (found) return;
    if (isUrlLikeName(assignmentTargetName(n))) found = true;
  });
  return found;
}

/**
 * STATIC (heuristic) (P5). Known limits: only recognizes NODE_ENV/APP_ENV/ENVIRONMENT/ENV-
 * named process.env reads, and only flags when the env-branch assigns to a
 * variable/property whose name literally contains url/endpoint/host — a
 * differently-named config var (e.g. `dbConnectionString`) evades detection.
 */
export const envBranchingLogicChecker: FileChecker = {
  ruleId: "deployment/environments/use-environment-specific-workspaces-not-branches-for-env-separat",
  kind: "file",
  appliesTo: (p) => isSourceFile(p) && isActionFile(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const flagged = new Set<AstNode>();

    walkAst(parsed.ast, (n) => {
      if (n.type === "VariableDeclarator" || n.type === "AssignmentExpression") {
        const name = assignmentTargetName(n);
        const valueNode = n.type === "VariableDeclarator" ? member(n, "init") : member(n, "right");
        if (isUrlLikeName(name) && valueNode?.type === "ConditionalExpression") {
          const test = member(valueNode, "test");
          if (test && containsEnvVarRead(test) && !flagged.has(n)) {
            flagged.add(n);
            hits.push({
              ...locStart(n),
              message: `Environment branching on process.env (NODE_ENV-like) assigns a different value to '${name}' per branch — use App Builder workspaces, not branch/env-based config switching.`,
            });
          }
        }
      }
      if (n.type === "IfStatement") {
        const test = member(n, "test");
        const consequent = member(n, "consequent");
        const alternate = member(n, "alternate");
        if (
          test &&
          consequent &&
          alternate &&
          containsEnvVarRead(test) &&
          hasUrlLikeAssignment(consequent) &&
          hasUrlLikeAssignment(alternate) &&
          !flagged.has(n)
        ) {
          flagged.add(n);
          hits.push({
            ...locStart(n),
            message:
              "Environment branching on process.env (NODE_ENV-like) assigns different DB/API URLs per branch — use App Builder workspaces, not branch/env-based config switching.",
          });
        }
      }
      if (n.type === "SwitchStatement") {
        const disc = member(n, "discriminant");
        if (disc && containsEnvVarRead(disc)) {
          const cases = (n as AstNode & { cases?: AstNode[] }).cases ?? [];
          const withUrlAssign = cases.filter((c) => hasUrlLikeAssignment(c));
          if (withUrlAssign.length >= 2 && !flagged.has(n)) {
            flagged.add(n);
            hits.push({
              ...locStart(n),
              message:
                "Environment branching (switch on process.env) assigns different DB/API URLs per case — use App Builder workspaces, not branch/env-based config switching.",
            });
          }
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 3. missing-predeploy-gate
// ---------------------------------------------------------------------------

export const missingPredeployGateChecker: RepoChecker = {
  ruleId: "deployment/pipeline/pre-deploy-gate-code-review-tests-security-scan-perf-check-docs",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const wf of await listWorkflows(ctx)) {
      const doc = tryParseWorkflow(wf.content);
      if (!doc?.jobs) continue;
      for (const [key, job] of Object.entries(doc.jobs)) {
        if (!/deploy/i.test(key) && !/deploy/i.test(job.name ?? "")) continue;
        const needs = !job.needs ? [] : Array.isArray(job.needs) ? job.needs : [job.needs];
        if (needs.length === 0) {
          hits.push({
            line: 1,
            path: wf.path,
            message: `Job '${key}' has no 'needs:' gate — add a dependency on a job that runs code review/tests/security scan/perf check before deploying.`,
          });
          continue;
        }
        const gated = needs.some((depName) => {
          if (/test|audit|lint/i.test(depName)) return true;
          const dep = doc.jobs?.[depName];
          return dep ? /test|audit|lint/i.test(jobText(dep, depName)) : false;
        });
        if (!gated) {
          hits.push({
            line: 1,
            path: wf.path,
            message: `Job '${key}' needs [${needs.join(", ")}] but none of them appear to run tests/audit/lint — add a real pre-deploy gate.`,
          });
        }
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 4. deploy-hook-mutates-state
// ---------------------------------------------------------------------------

export const deployHookMutatesStateChecker: FileChecker = {
  ruleId: "deployment/pipeline/use-deployment-hooks-post-app-deploy-js-for-verification-not-for",
  kind: "file",
  appliesTo: (p) => /post-app-deploy\.js$/i.test(p),
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      if (callee?.type === "Identifier") {
        const name = identName(callee);
        if (name && /migrate|seed|createSchema/i.test(name)) {
          hits.push({
            ...locStart(n),
            message: `Deployment hook calls '${name}()' — post-app-deploy.js should verify the deploy (smoke checks), not run migrations/seeds/schema changes.`,
          });
        }
      }
      if (callee?.type === "MemberExpression") {
        const prop = member(callee, "property");
        const propName = identName(prop);
        if (propName === "insert" || propName === "update" || propName === "create") {
          hits.push({
            ...locStart(n),
            message: `Deployment hook calls '.${propName}(' (a DB write) — post-app-deploy.js should verify the deploy, never mutate state.`,
          });
        }
      }
    });
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 5. missing-no-build-flag
// ---------------------------------------------------------------------------

export const missingNoBuildFlagChecker: RepoChecker = {
  ruleId: "deployment/pipeline/use-aio-app-deploy-no-build-when-the-build-runs-in-a-separate-ci",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const wf of await listWorkflows(ctx)) {
      const buildMatch = /aio\s+app\s+build\b/i.exec(wf.content);
      if (!buildMatch) continue;
      const afterBuildIdx = buildMatch.index + buildMatch[0].length;
      const afterBuild = wf.content.slice(afterBuildIdx);
      const deployMatch = /aio\s+app\s+deploy\b[^\n]*/i.exec(afterBuild);
      if (!deployMatch) continue;
      if (!/--no-build/.test(deployMatch[0])) {
        const line = wf.content.slice(0, afterBuildIdx + deployMatch.index).split("\n").length;
        hits.push({
          line,
          path: wf.path,
          message:
            "Workflow runs 'aio app build' then 'aio app deploy' without --no-build — the deploy step re-builds, doubling CI time and risking a different artifact than the one tested.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 6. ungated-shared-secrets
// ---------------------------------------------------------------------------

export const ungatedSharedSecretsChecker: RepoChecker = {
  ruleId: "deployment/pipeline/each-git-branch-or-environment-maps-to-specific-aio-project-and-",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const wf of await listWorkflows(ctx)) {
      const doc = tryParseWorkflow(wf.content);
      if (!doc?.jobs) continue;
      const deployJobs = Object.entries(doc.jobs).filter(
        ([key, job]) => /deploy/i.test(key) || /deploy/i.test(job.name ?? ""),
      );
      if (deployJobs.length !== 1) continue;
      const [key, job] = deployJobs[0]!;
      const ifCond = job.if ?? "";
      if (!/branch|tag|ref/i.test(ifCond)) {
        hits.push({
          line: 1,
          path: wf.path,
          message: `Job '${key}' is the only deploy job in this workflow and has no 'if:' condition gating it to a specific branch/tag/ref — map each environment to explicit, gated secrets instead of implicit "whatever was selected last".`,
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 7. undeploy-prod-action
// ---------------------------------------------------------------------------

const UNDEPLOY_SCAN_EXTENSIONS = new Set([".yml", ".yaml", ".js", ".ts", ".mjs", ".cjs", ".sh", ".json", ".md"]);

export const undeployProdActionChecker: RepoChecker = {
  ruleId: "deployment/releases/don-t-undeploy-a-production-action-version-it-instead",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const f of ctx.allFiles) {
      const ext = f.slice(f.lastIndexOf("."));
      if (!UNDEPLOY_SCAN_EXTENSIONS.has(ext)) continue;
      const content = await readSafe(f);
      if (!content || !content.includes("aio app undeploy")) continue;
      if (/production|prod\b/i.test(content)) {
        hits.push({
          line: 1,
          path: f,
          message:
            "File runs 'aio app undeploy' in a context referencing Production/prod — undeploying removes the action entirely (404 for in-flight callers); version it instead.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 8. untagged-prod-deploy — STATIC (heuristic) (P4)
// ---------------------------------------------------------------------------

/**
 * STATIC (heuristic) (P4). Known limits: only checks for git tags matching `prod-*` —
 * a team using a different tagging convention (e.g. release/vX or plain
 * semver tags) is flagged even if it tags every release consistently.
 */
export const untaggedProdDeployChecker: RepoChecker = {
  ruleId: "deployment/releases/tag-and-document-every-prod-release-single-source-of-truth",
  kind: "repo",
  async check(ctx) {
    let tags: string[] = [];
    try {
      const out = execFileSync("git", ["tag", "--list"], { cwd: ctx.projectRoot, encoding: "utf8" });
      tags = out.split("\n").map((s) => s.trim()).filter(Boolean);
    } catch {
      return [];
    }
    const hasProdTag = tags.some((t) => /^prod-/.test(t));
    if (!hasProdTag) {
      return [
        {
          line: 1,
          message:
            "No git tags matching 'prod-*' found — tag and document every Production release (git tag + changelog + release notes) as the single source of truth.",
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 9. missing-post-deploy-monitoring
// ---------------------------------------------------------------------------

export const missingPostDeployMonitoringChecker: RepoChecker = {
  ruleId: "deployment/rollback/monitor-activation-logs-after-deploy-error-rate-p95-throttling",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const wf of await listWorkflows(ctx)) {
      const deployMatch = /deploy/i.exec(wf.content);
      if (!deployMatch) continue;
      const after = wf.content.slice(deployMatch.index + deployMatch[0].length);
      if (!/monitor|activation|rollback/i.test(after)) {
        hits.push({
          line: 1,
          path: wf.path,
          message:
            "Workflow deploys but has no later step referencing monitoring/activation logs/rollback — monitor error rate, p95, and throttling after every deploy.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 10. missing-rollback-script
// ---------------------------------------------------------------------------

export const missingRollbackScriptChecker: RepoChecker = {
  ruleId: "deployment/rollback/have-a-one-command-rollback-never-redeploy-the-previous-commit-u",
  kind: "repo",
  async check(ctx) {
    const hasScript = ctx.allFiles.some((f) => /scripts?\/rollback/i.test(normalizePath(f)));
    if (hasScript) return [];
    for (const wf of await listWorkflows(ctx)) {
      if (/rollback/i.test(wf.content)) return [];
    }
    return [
      {
        line: 1,
        message:
          "No scripts/rollback* file and no CI workflow references 'rollback' — have a one-command rollback ready before the next incident.",
      },
    ];
  },
};

// ---------------------------------------------------------------------------
// 11. missing-precommit-secrets-scan
// ---------------------------------------------------------------------------

export const missingPrecommitSecretsScanChecker: RepoChecker = {
  ruleId: "deployment/secrets/enforce-secrets-scanning-in-pre-commit-hooks-block-secrets-befor",
  kind: "repo",
  async check(ctx) {
    const preCommitPath = join(ctx.projectRoot, ".pre-commit-config.yaml");
    const gitleaksPath = join(ctx.projectRoot, ".gitleaks.toml");
    const preCommitContent = await readSafe(preCommitPath);
    const hasGitleaksToml = await fileExists(gitleaksPath);
    if (preCommitContent === null && !hasGitleaksToml) {
      return [
        {
          line: 1,
          message:
            "No .pre-commit-config.yaml or .gitleaks.toml found — enforce secrets scanning (gitleaks) in pre-commit hooks before secrets hit git.",
        },
      ];
    }
    if (preCommitContent !== null && !/gitleaks/i.test(preCommitContent)) {
      return [
        {
          line: 1,
          path: preCommitPath,
          message: ".pre-commit-config.yaml exists but doesn't mention gitleaks — add the gitleaks hook to block secrets before commit.",
        },
      ];
    }
    return [];
  },
};

// ---------------------------------------------------------------------------
// 12. static-ci-token
// ---------------------------------------------------------------------------

export const staticCiTokenChecker: RepoChecker = {
  ruleId: "deployment/secrets/generate-oauth-tokens-in-ci-via-aio-apps-action-oauth-sts-don-t-",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    for (const wf of await listWorkflows(ctx)) {
      if (!/AIO_RUNTIME_AUTH\s*:\s*\$\{\{\s*secrets\./i.test(wf.content)) continue;
      const hasOauthSts = /aio-apps-action/i.test(wf.content) && /oauth_sts/i.test(wf.content);
      if (!hasOauthSts) {
        hits.push({
          line: 1,
          path: wf.path,
          message:
            "AIO_RUNTIME_AUTH is set from a static secret — generate short-lived OAuth tokens in CI via aio-apps-action oauth_sts instead of storing long-lived static tokens.",
        });
      }
    }
    return hits;
  },
};

// ---------------------------------------------------------------------------
// 13. latency-risk-action — STATIC (heuristic) (P2)
// ---------------------------------------------------------------------------

const LOOP_TYPES = new Set(["ForStatement", "ForInStatement", "ForOfStatement", "WhileStatement", "DoWhileStatement"]);
const FN_TYPES = new Set(["FunctionDeclaration", "FunctionExpression", "ArrowFunctionExpression"]);

function containsStateGetCall(node: AstNode): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (found || n.type !== "CallExpression") return;
    const callee = member(n, "callee");
    if (callee?.type === "MemberExpression" && identName(member(callee, "object")) === "state" && identName(member(callee, "property")) === "get") {
      found = true;
    }
  });
  return found;
}

function hasSortWithComparator(node: AstNode): boolean {
  let found = false;
  walkAst(node, (n) => {
    if (found || n.type !== "CallExpression") return;
    const callee = member(n, "callee");
    if (callee?.type === "MemberExpression" && identName(member(callee, "property")) === "sort") {
      const args = (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
      if (args.length > 0) found = true;
    }
  });
  return found;
}

function hasNestedLoop(root: AstNode): boolean {
  let found = false;
  const go = (n: AstNode, loopDepth: number): void => {
    if (found) return;
    for (const key of Object.keys(n)) {
      if (found) return;
      if (key === "loc" || key === "range" || key === "parent") continue;
      const val = n[key];
      if (!val || typeof val !== "object") continue;
      const children = Array.isArray(val) ? val : [val];
      for (const child of children) {
        if (found) return;
        if (!child || typeof child !== "object" || !("type" in child)) continue;
        const c = child as AstNode;
        if (FN_TYPES.has(c.type) && c !== root) continue;
        if (LOOP_TYPES.has(c.type) && loopDepth >= 1) {
          found = true;
          return;
        }
        go(c, LOOP_TYPES.has(c.type) ? loopDepth + 1 : loopDepth);
      }
    }
  };
  go(root, 0);
  return found;
}

/**
 * STATIC (heuristic) (P2). Known limits: this is an independent, simplified
 * implementation of the same "expensive synchronous work with no cache
 * check" heuristic as the `cpu-bound-sync-handler` builtin (implemented in a
 * parallel batch, node-bugclasses-errors.ts, per ledger #340 merging into
 * #131) — the two checkers were written without shared code since each
 * batch's files couldn't see the other's in-progress work. FLAG FOR
 * DEDUPLICATION during integration: pick one canonical implementation.
 */
export const latencyRiskActionChecker: FileChecker = {
  ruleId: "deployment/slos/action-response-time-slo-p95-500ms",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const mainEntry = findFunctions(parsed.ast).find((f) => f.name === "main");
    const mainNode = mainEntry?.node;
    if (!mainNode || !mainNode.body) return [];
    const body = mainNode.body;
    if (containsStateGetCall(body)) return [];
    const hits: CheckHit[] = [];
    if (hasSortWithComparator(body)) {
      hits.push({
        ...locStart(mainNode),
        message:
          "main() calls .sort() with a custom comparator and has no cache-check (state.get) before it — this risks blowing the p95 < 500ms action SLO on larger inputs.",
      });
    }
    if (hasNestedLoop(body)) {
      hits.push({
        ...locStart(mainNode),
        message:
          "main() has a nested loop with no cache-check (state.get) before it — this risks blowing the p95 < 500ms action SLO.",
      });
    }
    return hits;
  },
};
