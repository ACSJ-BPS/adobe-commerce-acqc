import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { RuleRouter } from "../rule-router.js";
import type { Rule } from "../types.js";

function mkRule(id: string, domain: string): Rule {
  return {
    id,
    domain,
    subTopic: "x",
    title: id,
    severity: "warn",
    tags: [],
    rationale: "",
    bad: "",
    good: "",
    filePath: "",
    checkSpec: undefined,
  };
}

const RULES = [
  mkRule("appbuilder/runtime/r1", "appbuilder"),
  mkRule("deployment/ci/r2", "deployment"),
  mkRule("security/adobe/r3", "security"),
];

function domainsFor(filePath: string): Set<string> {
  const router = new RuleRouter(RULES);
  return new Set(router.routeFile(filePath).rules.map((r) => r.domain));
}

describe("RuleRouter — App Builder YAML config routing", () => {
  it("routes actions.config.yaml to appbuilder + deployment (regression: was ALWAYS_INCLUDE-only)", () => {
    const domains = domainsFor("/proj/src/actions.config.yaml");
    assert.ok(domains.has("appbuilder"), "actions.config.yaml must get appbuilder rules");
    assert.ok(domains.has("deployment"), "actions.config.yaml must get deployment rules");
  });

  it("routes manifest.yaml to appbuilder", () => {
    assert.ok(domainsFor("/proj/manifest.yaml").has("appbuilder"), "manifest.yaml must get appbuilder rules");
  });

  it("still routes app.config.yaml to appbuilder (no regression)", () => {
    assert.ok(domainsFor("/proj/app.config.yaml").has("appbuilder"));
  });

  it("does NOT route a generic *.yaml (e.g. a CI workflow) to appbuilder", () => {
    const domains = domainsFor("/proj/.github/workflows/deploy.yaml");
    assert.equal(domains.has("appbuilder"), false, "generic yaml must not inherit appbuilder rules");
    // security is ALWAYS_INCLUDE, so it is present for every walked file.
    assert.ok(domains.has("security"));
  });
});
