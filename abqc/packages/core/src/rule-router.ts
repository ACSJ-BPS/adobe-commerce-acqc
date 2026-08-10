import { extname } from "node:path";
import type { Rule } from "./types.js";
import { isYamlConfig } from "./walk.js";

interface RouteSpec {
  match: (filePath: string) => boolean;
  domains: string[];
}

const ROUTES: RouteSpec[] = [
  // App Builder action handlers
  {
    match: (p) => /(^|\/)actions\/.+\.(js|ts|mjs|cjs)$/.test(p),
    domains: ["appbuilder", "node", "security", "stylistic", "imports"],
  },
  // App Builder UI / SPA frontend
  {
    match: (p) => /(^|\/)web-src\/.+\.(jsx|tsx|js|ts)$/.test(p),
    domains: ["react", "a11y", "typescript", "runtime", "security", "stylistic", "imports"],
  },
  // App Builder admin UI SDK extension
  {
    match: (p) => /(^|\/)src\/commerce-backend-ui-1\//.test(p),
    domains: ["appbuilder/admin-ui-sdk", "react", "security"],
  },
  // API Mesh config
  {
    match: (p) => /meshConfig\.json$|mesh\.json$/.test(p),
    domains: ["appbuilder/api-mesh"],
  },
  // App Builder config files. Use the same `isYamlConfig` helper as the walk
  // and rules-check layers so all three agree on what counts as an App Builder
  // YAML config — previously this route matched only app/ext/install.yaml, so
  // actions.config.yaml and manifest.yaml fell through to ALWAYS_INCLUDE and
  // never got appbuilder rules in their judgment applicable-subset even though
  // the mechanical layer checked them. isYamlConfig is App-Builder-specific
  // (app/ext/install/*actions?.config/manifest), so generic *.yaml (e.g. CI
  // workflows) is still correctly excluded.
  {
    match: (p) => isYamlConfig(p),
    domains: ["appbuilder", "deployment"],
  },
  // Test files
  {
    match: (p) => /\.test\.(js|ts|jsx|tsx)$/.test(p) || /(^|\/)(test|tests|__tests__)\//.test(p),
    domains: ["testing", "node", "stylistic"],
  },
  // TypeScript anywhere
  {
    match: (p) => /\.(ts|tsx)$/.test(p),
    domains: ["typescript", "stylistic", "imports"],
  },
  // Generic JS
  {
    match: (p) => /\.(js|jsx|mjs|cjs)$/.test(p),
    domains: ["node", "stylistic", "imports"],
  },
];

// process = governance/compliance rules — apply to every file regardless of type.
const ALWAYS_INCLUDE: string[] = ["security", "documentation", "process"];

// First-class App Builder sub-products nested under `appbuilder/`. They have their
// own routes (meshConfig.json, commerce-backend-ui-1/) and must NOT be pulled in
// by a bare `appbuilder` request — otherwise every action/config file inherits all
// api-mesh + admin-ui-sdk rules purely because their domain starts with "appbuilder/".
export const SUBPRODUCT_DOMAINS: string[] = ["appbuilder/api-mesh", "appbuilder/admin-ui-sdk"];

export interface RoutedRules {
  filePath: string;
  domains: string[];
  rules: Rule[];
}

export class RuleRouter {
  constructor(private readonly allRules: Rule[]) {}

  routeFile(filePath: string): RoutedRules {
    const matched = new Set<string>();
    for (const route of ROUTES) {
      if (route.match(filePath)) for (const d of route.domains) matched.add(d);
    }
    // ALWAYS_INCLUDE guarantees `matched` is never empty, so a "nothing matched,
    // fall back to stylistic" branch would be dead code — removed rather than
    // kept as unreachable noise. (Binary/sensitive files never reach routeFile
    // at all — they're excluded during the walk, see walk.ts isUnreviewableFile.)
    for (const d of ALWAYS_INCLUDE) matched.add(d);

    const domains = Array.from(matched);
    const rules = this.allRules.filter((r) => domains.some((d) => routeDomainMatches(r.domain, d)));
    return { filePath, domains, rules };
  }
}

/**
 * Does a routed `requested` domain apply to a rule in `ruleDomain`?
 *
 * Exact match, or `ruleDomain` is nested under `requested` — EXCEPT a bare parent
 * (e.g. "appbuilder") never absorbs a designated sub-product (e.g.
 * "appbuilder/api-mesh"): those are first-class and must be requested explicitly.
 * This is the routing/coverage matcher; browsing (abqc_list_rules) stays inclusive.
 */
export function routeDomainMatches(ruleDomain: string, requested: string): boolean {
  if (ruleDomain === requested) return true;
  if (!ruleDomain.startsWith(requested + "/")) return false;
  for (const sub of SUBPRODUCT_DOMAINS) {
    const underSub = ruleDomain === sub || ruleDomain.startsWith(sub + "/");
    const requestedIsAncestorOfSub = sub.startsWith(requested + "/");
    if (underSub && requestedIsAncestorOfSub) return false;
  }
  return true;
}

export function fileTypeForPath(filePath: string): string {
  const ext = extname(filePath).toLowerCase();
  if (ext === ".tsx" || ext === ".jsx") return "react-component";
  if (ext === ".ts") return "typescript";
  if (ext === ".js" || ext === ".mjs" || ext === ".cjs") return "javascript";
  if (ext === ".json") return "json";
  if (ext === ".yaml" || ext === ".yml") return "yaml";
  if (ext === ".md") return "markdown";
  return "other";
}
