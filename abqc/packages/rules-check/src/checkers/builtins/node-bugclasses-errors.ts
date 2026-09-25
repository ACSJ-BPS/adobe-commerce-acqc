import { dirname } from "node:path";
import { readFile } from "node:fs/promises";
import type { CheckHit, CheckContext, FileChecker, RepoChecker, RepoContext } from "../../types.js";
import { isSourceFile, isActionFile } from "@abqc/core";
import { walkAst, findFunctions, locStart, type AstNode } from "../../utils/parse.js";
import { requireParsed, isCheckHitArray, member, identName } from "./shared.js";

function calleeName(callee: AstNode | undefined): string | undefined {
  if (!callee) return undefined;
  if (callee.type === "Identifier") return identName(callee);
  if (callee.type === "MemberExpression") {
    const prop = member(callee, "property");
    return identName(prop);
  }
  return undefined;
}

function calleeFullName(callee: AstNode | undefined): string {
  if (!callee) return "";
  if (callee.type === "Identifier") return identName(callee) ?? "";
  if (callee.type === "MemberExpression") {
    const obj = member(callee, "object");
    const prop = member(callee, "property");
    const objName = obj && obj.type === "Identifier" ? identName(obj) : undefined;
    const propName = identName(prop);
    if (objName && propName) return `${objName}.${propName}`;
    return propName ?? "";
  }
  return "";
}

function callArgs(n: AstNode): AstNode[] {
  return (n as AstNode & { arguments?: AstNode[] }).arguments ?? [];
}

/**
 * `node/bug-classes/validate-regex-for-redos-no-nested-quantifiers-on-user-input`
 *
 * Known limits: a true ReDoS-complexity analysis (e.g. the safe-regex algorithm)
 * is needed for exotic alternation-based blowups this simple pattern misses; a
 * nested-quantifier pattern applied only to length-bounded input is practically
 * safe but still flagged since the bound isn't statically verifiable.
 */
export const redosNestedQuantifierChecker: FileChecker = {
  ruleId: "node/bug-classes/validate-regex-for-redos-no-nested-quantifiers-on-user-input",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const hasEscapeHelper = /escapeRegex|safeRegex/i.test(ctx.content);
    const nestedQuantifierRe = /\([^)]*[+*][^)]*\)[+*]/;
    walkAst(parsed.ast, (n) => {
      if (n.type !== "NewExpression") return;
      const callee = member(n, "callee");
      if (!callee || identName(callee) !== "RegExp") return;
      const args = callArgs(n);
      const first = args[0];
      if (!first) return;
      if (first.type === "Literal") {
        const val = (first as AstNode & { value?: unknown }).value;
        if (typeof val === "string" && nestedQuantifierRe.test(val)) {
          hits.push({ ...locStart(n), message: "Regex has nested quantifiers — vulnerable to catastrophic backtracking (ReDoS) on user input" });
        }
        return;
      }
      if (!hasEscapeHelper) {
        hits.push({ ...locStart(n), message: "RegExp built from a dynamic expression with no escape/safety helper in this file — verify against ReDoS" });
      }
    });
    return hits;
  },
};

/**
 * `node/bug-classes/no-child-process-with-shell-true-or-unsanitized-args`
 */
export const childProcessShellChecker: FileChecker = {
  ruleId: "node/bug-classes/no-child-process-with-shell-true-or-unsanitized-args",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      const name = calleeName(callee);
      if (!name) return;
      const args = callArgs(n);
      if (name === "exec" || name === "execSync") {
        const first = args[0];
        if (first && first.type === "TemplateLiteral") {
          const exprs = (first as AstNode & { expressions?: AstNode[] }).expressions ?? [];
          if (exprs.length > 0) {
            hits.push({ ...locStart(n), message: `${name}() built from a template literal with interpolation — unsanitized shell input` });
          }
        } else if (first && first.type === "BinaryExpression" && (first as AstNode & { operator?: string }).operator === "+") {
          hits.push({ ...locStart(n), message: `${name}() built via string concatenation — unsanitized shell input` });
        }
      }
      if (name === "exec" || name === "execSync" || name === "spawn") {
        const hasShellTrue = args.some((a) => {
          if (a.type !== "ObjectExpression") return false;
          const props = (a as AstNode & { properties?: AstNode[] }).properties ?? [];
          return props.some((p) => {
            if (p.type !== "Property") return false;
            const key = member(p, "key");
            const keyName = key ? (key.type === "Identifier" ? identName(key) : key.type === "Literal" ? String((key as AstNode & { value?: unknown }).value) : undefined) : undefined;
            if (keyName !== "shell") return false;
            const value = member(p, "value");
            return !!value && value.type === "Literal" && (value as AstNode & { value?: unknown }).value === true;
          });
        });
        if (hasShellTrue) {
          hits.push({ ...locStart(n), message: `${name}() called with shell: true — risk of shell injection` });
        }
      }
    });
    return hits;
  },
};

/**
 * `node/bug-classes/no-sync-fs-apis-in-the-request-path`
 */
export const syncFsInHandlerChecker: FileChecker = {
  ruleId: "node/bug-classes/no-sync-fs-apis-in-the-request-path",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const mains = findFunctions(parsed.ast).filter((f) => f.name === "main");
    for (const { node } of mains) {
      const root = node.body ?? node;
      walkAst(root, (n) => {
        if (n.type !== "CallExpression") return;
        const callee = member(n, "callee");
        if (!callee || callee.type !== "MemberExpression") return;
        const obj = member(callee, "object");
        const prop = member(callee, "property");
        const propName = identName(prop);
        const objName = identName(obj);
        if (propName && /Sync$/.test(propName) && objName && /^fs$/i.test(objName)) {
          hits.push({ ...locStart(n), message: `Synchronous fs API '${objName}.${propName}' blocks the event loop in the request path` });
        }
      });
    }
    return hits;
  },
};

/**
 * `node/bug-classes/no-cpu-bound-sync-work-in-handlers-fan-out-or-use-workers`
 *
 * Known limits: real cost depends on input size, unmeasurable statically —
 * systematically over-flags benign small-scale computation of the same
 * syntactic shape (e.g. sorting a 5-item array looks identical to sorting
 * 500,000).
 */
export const cpuBoundSyncHandlerChecker: FileChecker = {
  ruleId: "node/bug-classes/no-cpu-bound-sync-work-in-handlers-fan-out-or-use-workers",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx: CheckContext) {
    if (/worker_threads|\bWorker\b/.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const LOOP_TYPES = new Set(["ForStatement", "WhileStatement"]);
    const mains = findFunctions(parsed.ast).filter((f) => f.name === "main");
    for (const { node } of mains) {
      const root = node.body ?? node;
      walkAst(root, (n) => {
        if (n.type === "CallExpression") {
          const callee = member(n, "callee");
          const args = callArgs(n);
          if (callee && callee.type === "MemberExpression") {
            const propName = identName(member(callee, "property"));
            const objName = identName(member(callee, "object"));
            if (propName === "sort" && args.length > 0) {
              hits.push({ ...locStart(n), message: "Custom-comparator .sort() in a request handler — fan out or move to a worker for large inputs" });
            }
            if (objName === "JSON" && propName === "parse") {
              hits.push({ ...locStart(n), message: "JSON.parse() with no visible size guard in a request handler — validate/limit payload size or move to a worker" });
            }
          }
        }
        if (LOOP_TYPES.has(n.type)) {
          let hasNested = false;
          walkAst(n, (inner) => {
            if (inner !== n && LOOP_TYPES.has(inner.type)) hasNested = true;
          });
          if (hasNested) {
            hits.push({ ...locStart(n), message: "Nested loop in a request handler — CPU-bound work should fan out or move to a worker" });
          }
        }
      });
    }
    return hits;
  },
};

function hasObjectProp(obj: AstNode | undefined, key: string): boolean {
  if (!obj || obj.type !== "ObjectExpression") return false;
  const props = (obj as AstNode & { properties?: AstNode[] }).properties ?? [];
  return props.some((p) => {
    if (p.type !== "Property") return false;
    const k = member(p, "key");
    if (!k) return false;
    const name = k.type === "Identifier" ? identName(k) : k.type === "Literal" ? String((k as AstNode & { value?: unknown }).value) : undefined;
    return name === key;
  });
}

function findObjectArg(args: AstNode[]): AstNode | undefined {
  return args.find((a) => a.type === "ObjectExpression");
}

/**
 * `node/bug-classes/set-explicit-timeouts-on-all-outbound-http-no-infinite-hangs`
 * Also serves `appbuilder/runtime/wrap-external-http-calls-in-sequences-with-timeouts-retries-with`
 * and `node/reliability/apply-circuit-breakers-on-calls-to-external-dependencies`.
 *
 * Known limits: timeout set globally via a pre-configured axios.create({timeout})
 * instance is not recognized — under-flags calls made through such an instance;
 * also over-flags if the instance itself isn't configured with a timeout, since
 * we can't trace the instance's origin reliably.
 */
export const httpTimeoutRetryChecker: FileChecker = {
  ruleId: "node/bug-classes/set-explicit-timeouts-on-all-outbound-http-no-infinite-hangs",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];

    walkAst(parsed.ast, (n) => {
      if (n.type !== "CallExpression") return;
      const callee = member(n, "callee");
      const args = callArgs(n);
      if (callee && callee.type === "Identifier" && identName(callee) === "fetch") {
        const opts = args[1];
        if (args.length <= 1) {
          hits.push({ ...locStart(n), message: "(timeout) fetch() call has no timeout/AbortSignal — can hang indefinitely" });
        } else if (opts && opts.type === "ObjectExpression" && !hasObjectProp(opts, "signal")) {
          hits.push({ ...locStart(n), message: "(timeout) fetch() options object is missing a 'signal' (timeout via AbortController)" });
        }
      }
      if (callee && callee.type === "Identifier" && identName(callee) === "axios") {
        const cfg = args[0];
        if (!cfg || cfg.type !== "ObjectExpression" || !hasObjectProp(cfg, "timeout")) {
          hits.push({ ...locStart(n), message: "(timeout) axios(config) call is missing a 'timeout'" });
        }
      } else if (callee && callee.type === "MemberExpression" && identName(member(callee, "object")) === "axios") {
        const cfg = findObjectArg(args);
        if (!cfg || !hasObjectProp(cfg, "timeout")) {
          hits.push({ ...locStart(n), message: "(timeout) axios call is missing a 'timeout' in its config object" });
        }
      }
    });

    if (!/circuitbreaker|opossum/i.test(ctx.content)) {
      walkAst(parsed.ast, (n) => {
        if (n.type !== "ForStatement" && n.type !== "WhileStatement" && n.type !== "DoWhileStatement") return;
        let hasAwaitedHttpCall = false;
        walkAst(n, (inner) => {
          if (inner.type !== "AwaitExpression") return;
          const arg = member(inner, "argument");
          if (!arg || arg.type !== "CallExpression") return;
          const innerCallee = member(arg, "callee");
          if (!innerCallee) return;
          if (innerCallee.type === "Identifier") {
            const nm = identName(innerCallee) ?? "";
            if (/^fetch$/.test(nm) || /axios/i.test(nm)) hasAwaitedHttpCall = true;
          } else if (innerCallee.type === "MemberExpression") {
            const objName = identName(member(innerCallee, "object")) ?? "";
            const propName = identName(member(innerCallee, "property")) ?? "";
            if (/axios/i.test(objName)) hasAwaitedHttpCall = true;
            if ((objName === "http" || objName === "https") && propName === "request") hasAwaitedHttpCall = true;
          }
        });
        if (hasAwaitedHttpCall) {
          hits.push({ ...locStart(n), message: "(circuit-breaker) Retry loop around an external HTTP call with no circuit breaker (e.g. opossum) referenced in this file" });
        }
      });
    }

    return hits;
  },
};

function paramsChainDepth(n: AstNode): number {
  if (n.type !== "MemberExpression") return 0;
  const obj = member(n, "object");
  if (!obj) return 0;
  if (obj.type === "Identifier" && identName(obj) === "params") return 1;
  if (obj.type === "MemberExpression") {
    const d = paramsChainDepth(obj);
    return d > 0 ? d + 1 : 0;
  }
  return 0;
}

/**
 * `node/bug-classes/validate-inputs-at-the-boundary-with-a-schema-library`
 */
export const schemaValidateBoundaryChecker: FileChecker = {
  ruleId: "node/bug-classes/validate-inputs-at-the-boundary-with-a-schema-library",
  kind: "file",
  appliesTo: isActionFile,
  check(ctx: CheckContext) {
    if (/\.parse\(|\.safeParse\(|Joi\.|ajv/i.test(ctx.content)) return [];
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    const flagged = new Set<AstNode>();
    const mains = findFunctions(parsed.ast).filter((f) => f.name === "main");
    for (const { node } of mains) {
      const root = node.body ?? node;
      walkAst(root, (n) => {
        if (n.type !== "MemberExpression") return;
        if (paramsChainDepth(n) >= 2 && !flagged.has(n)) {
          flagged.add(n);
          hits.push({ ...locStart(n), message: "Deep access into 'params' with no schema validation (zod/Joi/ajv) at the boundary" });
        }
      });
    }
    return hits;
  },
};

/**
 * `node/errors/no-unnecessary-catch-and-rethrow`
 * Also serves `stylistic/functions/no-useless-catch-that-just-rethrows`.
 */
export const pureRethrowCatchChecker: FileChecker = {
  ruleId: "node/errors/no-unnecessary-catch-and-rethrow",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "CatchClause") return;
      const param = member(n, "param");
      const paramName = identName(param);
      const body = member(n, "body");
      if (!paramName || !body || body.type !== "BlockStatement") return;
      const stmts = (body as AstNode & { body?: AstNode[] }).body ?? [];
      if (stmts.length !== 1) return;
      const stmt = stmts[0];
      if (!stmt || stmt.type !== "ThrowStatement") return;
      const arg = member(stmt, "argument");
      if (identName(arg) === paramName) {
        hits.push({ ...locStart(n), message: "catch block only rethrows the caught error — remove the unnecessary try/catch" });
      }
    });
    return hits;
  },
};

/**
 * `node/errors/handle-the-err-parameter-in-error-first-callbacks`
 */
export const unusedErrParamChecker: FileChecker = {
  ruleId: "node/errors/handle-the-err-parameter-in-error-first-callbacks",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    for (const { node } of findFunctions(parsed.ast)) {
      const first = node.params[0];
      if (!first || first.type !== "Identifier") continue;
      const name = identName(first);
      if (name !== "err" && name !== "error") continue;
      const body = node.body;
      if (!body) continue;
      let used = false;
      walkAst(body, (n) => {
        if (n.type === "Identifier" && identName(n) === name) used = true;
      });
      if (!used) {
        hits.push({ ...locStart(node), message: `Error-first callback parameter '${name}' is never checked/handled` });
      }
    }
    return hits;
  },
};

/**
 * `node/errors/use-typed-domain-error-classes-with-http-status-and-error-code`
 */
export const typedErrorClassesChecker: FileChecker = {
  ruleId: "node/errors/use-typed-domain-error-classes-with-http-status-and-error-code",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx: CheckContext) {
    const parsed = requireParsed(ctx);
    if (isCheckHitArray(parsed)) return parsed;
    const hits: CheckHit[] = [];
    walkAst(parsed.ast, (n) => {
      if (n.type !== "ThrowStatement") return;
      const arg = member(n, "argument");
      if (!arg || arg.type !== "NewExpression") return;
      const callee = member(arg, "callee");
      if (identName(callee) === "Error") {
        hits.push({ ...locStart(n), message: "Throws a bare Error — use a typed domain error class with HTTP status and error code" });
      }
    });
    return hits;
  },
};

/**
 * `node/errors/register-unhandledrejection-and-uncaughtexception-handlers-that-`
 */
export const processCrashHandlersChecker: RepoChecker = {
  ruleId: "node/errors/register-unhandledrejection-and-uncaughtexception-handlers-that-",
  kind: "repo",
  async check(ctx: RepoContext) {
    // A real process entry file lives at the PROJECT ROOT — App Builder has
    // no single "the app's entry point" the way a plain Node server does
    // (each action is its own Runtime-invoked handler), so picking an
    // arbitrary `index.js` from anywhere in the tree (a shared lib helper
    // under `actions/lib/`, one action out of dozens, a browser-side
    // `web-src/src/index.js` bundle entry) isn't a real entry file. No
    // repo-root candidate = not applicable, not "pick whatever's closest."
    const rootCandidates = ctx.allFiles
      .filter((f) => dirname(f) === ctx.projectRoot && /(^|\/)(index|app|server)\.(js|cjs|mjs)$/.test(f.replace(/\\/g, "/")))
      .sort();
    const entry = rootCandidates[0];
    if (!entry) return [];

    let content: string;
    try {
      content = await readFile(entry, "utf8");
    } catch {
      return [];
    }
    const hasUnhandledRejection = /process\.on\(\s*['"]unhandledRejection['"]/.test(content);
    const hasUncaughtException = /process\.on\(\s*['"]uncaughtException['"]/.test(content);
    if (!hasUnhandledRejection || !hasUncaughtException) {
      return [
        {
          line: 1,
          path: entry,
          message: "Entry file does not register both process.on('unhandledRejection', ...) and process.on('uncaughtException', ...)",
        },
      ];
    }
    return [];
  },
};
