import { defaultRulesDir } from "../rules-dir.js";
import { Engine } from "../engine.js";
import type { Rule } from "../types.js";

const RULES_CHECK_PKG = "@abqc/rules-check";

async function main() {
  const rulesDir = defaultRulesDir();
  const engine = new Engine({ rulesDir, staticOnly: true });
  await engine.ready();
  const rules = engine.rules().all();

  const failures: string[] = [];
  const warnings: string[] = [];

  // A hard floor guards the actual failure mode that matters — catastrophic
  // rule loss (a bad merge, a misconfigured rules dir, a loader regression).
  // The exact count is a soft warning like the per-domain counts below (not a
  // hard failure): the corpus grows by design every time a rule is added, so
  // an exact-match assertion here previously meant CI failed on every single
  // rule addition/removal, coupling the self-test to a number nobody should
  // have to remember to bump.
  const MIN_TOTAL_RULES = 300;
  if (rules.length < MIN_TOTAL_RULES) {
    failures.push(`rule count ${rules.length} is below the sanity floor of ${MIN_TOTAL_RULES} — likely a loader regression`);
  }
  const EXPECTED_TOTAL = 387;
  if (rules.length !== EXPECTED_TOTAL) {
    warnings.push(`rule count changed: loaded ${rules.length}, snapshot expected ${EXPECTED_TOTAL} (update the snapshot if intentional)`);
  }

  let missingBad = 0;
  let missingGood = 0;
  let missingRationale = 0;
  let missingTags = 0;
  for (const rule of rules) {
    if (!rule.bad.trim()) missingBad++;
    if (!rule.good.trim()) missingGood++;
    if (!rule.rationale.trim()) missingRationale++;
    if (rule.tags.length === 0) missingTags++;
  }
  if (missingBad > 0) failures.push(`${missingBad} rule(s) missing Bad content`);
  if (missingGood > 0) failures.push(`${missingGood} rule(s) missing Good content`);
  if (missingRationale > 0) failures.push(`${missingRationale} rule(s) missing rationale`);
  if (missingTags > 0) failures.push(`${missingTags} rule(s) missing tags`);

  // Per-domain sanity: these domains must always exist (any rule count ≥ 1).
  // Hardcoded counts are replaced with a snapshot-diff warning that doesn't fail CI.
  const REQUIRED_DOMAINS = [
    "appbuilder", "appbuilder/admin-ui-sdk", "appbuilder/api-mesh",
    "node", "react", "security", "typescript", "stylistic",
    "a11y", "testing", "runtime", "deployment", "imports", "process", "documentation",
  ];

  const actualByDomain = new Map<string, number>();
  for (const rule of rules) {
    actualByDomain.set(rule.domain, (actualByDomain.get(rule.domain) ?? 0) + 1);
  }

  // Hard failure: a required domain has disappeared entirely.
  for (const domain of REQUIRED_DOMAINS) {
    if (!actualByDomain.has(domain)) {
      failures.push(`domain "${domain}" has disappeared — 0 rules loaded`);
    }
  }

  // Soft warning: per-domain counts changed (informational, not a blocker).
  // Set ABQC_STRICT_COUNTS=1 to promote these to hard failures (e.g. in pre-release CI).
  const strictCounts = process.env.ABQC_STRICT_COUNTS === "1";
  const EXPECTED_BY_DOMAIN: Record<string, number> = {
    "appbuilder": 61, "appbuilder/admin-ui-sdk": 16, "appbuilder/api-mesh": 33,
    "node": 46, "react": 37, "security": 36, "typescript": 36, "stylistic": 25,
    "a11y": 18, "testing": 17, "runtime": 15, "deployment": 17, "imports": 12,
    "process": 12, "documentation": 6,
  };
  for (const [domain, expected] of Object.entries(EXPECTED_BY_DOMAIN)) {
    const actual = actualByDomain.get(domain) ?? 0;
    if (actual !== expected) {
      const msg = `domain "${domain}": expected ${expected}, got ${actual}`;
      if (strictCounts) failures.push(msg);
      else warnings.push(msg);
    }
  }

  // Routing sanity: a .tsx file must NOT receive api-mesh or admin-ui-sdk rules
  // router is guaranteed non-null — engine.ready() was awaited above.
  const router = engine.getRouter() ?? (() => { throw new Error("router not initialized"); })();
  const tsxRouted = router.routeFile("/work/web-src/src/Component.tsx");
  const hasMeshRule = tsxRouted.rules.some((r) => r.domain === "appbuilder/api-mesh");
  const hasAdminUiSdkRule = tsxRouted.rules.some((r) => r.domain === "appbuilder/admin-ui-sdk");
  if (hasMeshRule) failures.push("router leaked api-mesh rules into .tsx file");
  if (hasAdminUiSdkRule) failures.push("router leaked admin-ui-sdk rules into web-src .tsx file");

  // Routing positive: actions/foo.js MUST receive appbuilder rules
  const actionRouted = router.routeFile("/work/actions/sync-products/index.js");
  const hasAppbuilderRule = actionRouted.rules.some((r) => r.domain === "appbuilder");
  if (!hasAppbuilderRule) failures.push("router missing appbuilder rules for actions/*");

  // Routing negative (regression guard): an action file routes to bare "appbuilder"
  // and MUST NOT inherit api-mesh / admin-ui-sdk sub-product rules — those are
  // first-class products with their own routes (meshConfig.json, commerce-backend-ui-1/).
  const actionHasMesh = actionRouted.rules.some((r) => r.domain === "appbuilder/api-mesh");
  const actionHasAdminUiSdk = actionRouted.rules.some((r) => r.domain === "appbuilder/admin-ui-sdk");
  if (actionHasMesh) failures.push("router leaked api-mesh rules into actions/*.js file");
  if (actionHasAdminUiSdk) failures.push("router leaked admin-ui-sdk rules into actions/*.js file");

  try {
    const { validateRuleChecks, automatableRuleIds, findBrokenEslintMappings } = await import(RULES_CHECK_PKG) as {
      validateRuleChecks: (rules: Rule[]) => void;
      automatableRuleIds: (rules: Rule[]) => string[];
      findBrokenEslintMappings: (rules: Rule[]) => Array<{ eslintId: string; libraryId: string }>;
    };
    validateRuleChecks(rules);
    const autoCount = automatableRuleIds(rules).length;
    const EXPECTED_AUTOMATABLE = 22;
    if (autoCount < EXPECTED_AUTOMATABLE) {
      failures.push(`automatable rule count ${autoCount} < expected ${EXPECTED_AUTOMATABLE}`);
    }
    console.log(`  mechanical checks          → ${autoCount} automatable rules validated`);

    const brokenMappings = findBrokenEslintMappings(rules);
    if (brokenMappings.length > 0) {
      for (const b of brokenMappings) {
        failures.push(`eslint-map target missing: ${b.eslintId} → ${b.libraryId}`);
      }
    } else {
      console.log(`  eslint map                 → all targets resolve to real rules`);
    }
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    if (msg.includes("Cannot find package") || msg.includes("Cannot find module")) {
      warnings.push("@abqc/rules-check not installed — skipping ### Check + eslint-map validation");
    } else {
      failures.push(`rules-check validation failed: ${msg}`);
    }
  }

  if (warnings.length > 0) {
    console.warn(`Self-test WARNINGS (${warnings.length}) — domain counts changed (set ABQC_STRICT_COUNTS=1 to fail on these):`);
    for (const w of warnings) console.warn("  ~ " + w);
  }

  if (failures.length > 0) {
    console.error(`Self-test FAILED with ${failures.length} issue(s):`);
    for (const f of failures) console.error("  - " + f);
    process.exit(1);
  }

  console.log(`Self-test PASS — ${rules.length} rules loaded across ${actualByDomain.size} domains.`);
  console.log(`  router(.tsx)             → ${tsxRouted.rules.length} rules from ${tsxRouted.domains.join(", ")}`);
  console.log(`  router(actions/*.js)     → ${actionRouted.rules.length} rules from ${actionRouted.domains.join(", ")}`);
}

main().catch((e) => {
  console.error("Self-test crashed:", e);
  process.exit(1);
});
