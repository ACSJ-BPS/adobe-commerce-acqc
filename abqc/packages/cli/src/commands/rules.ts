import { Engine } from "@abqc/core";
import { validateRuleChecks, automatableRuleIds } from "@abqc/rules-check";

export async function rulesCommand(argv: string[]): Promise<number> {
  const sub = argv[0];
  if (!sub || sub === "list") return await listRules(argv.slice(1));
  if (sub === "get") return await getRule(argv.slice(1));
  if (sub === "validate") return await validateRules();
  console.error(`rules: unknown subcommand "${sub}"`);
  return 2;
}

async function validateRules(): Promise<number> {
  const engine = new Engine({ staticOnly: true });
  await engine.ready();
  const rules = engine.rules().all();
  try {
    validateRuleChecks(rules);
  } catch (e) {
    console.error(e instanceof Error ? e.message : e);
    return 2;
  }
  const auto = automatableRuleIds(rules);
  console.log(`OK — ${rules.length} rules loaded, ${auto.length} automatable (### Check specs valid)`);
  return 0;
}

async function listRules(argv: string[]): Promise<number> {
  const opts = parseListArgs(argv);
  const engine = new Engine({ staticOnly: true });
  await engine.ready();
  let rules = engine.rules().all();
  if (opts.domain) rules = rules.filter((r) => r.domain === opts.domain);
  if (opts.severity) rules = rules.filter((r) => r.severity === opts.severity);

  for (const rule of rules) {
    console.log(`${pad(rule.severity, 5)} ${rule.id}`);
    if (opts.verbose) {
      console.log(`        ${rule.title}`);
      if (rule.tags.length > 0) console.log(`        [${rule.tags.join(", ")}]`);
    }
  }
  console.log(`\n${rules.length} rule(s)`);
  return 0;
}

async function getRule(argv: string[]): Promise<number> {
  const id = argv[0];
  if (!id) {
    console.error("rules get: missing rule id");
    return 2;
  }
  const engine = new Engine({ staticOnly: true });
  await engine.ready();
  const rule = engine.rules().get(id);
  if (!rule) {
    console.error(`rule not found: ${id}`);
    return 2;
  }
  console.log(`# ${rule.title} [${rule.severity}] [${rule.tags.join(", ")}]`);
  console.log(`id: ${rule.id}`);
  console.log(`domain: ${rule.domain}`);
  console.log(`file: ${rule.filePath}`);
  console.log("");
  console.log(rule.rationale);
  console.log("\n## Bad\n");
  console.log(rule.bad);
  console.log("\n## Good\n");
  console.log(rule.good);
  return 0;
}

interface ListOpts {
  domain?: string;
  severity?: "error" | "warn" | "info";
  verbose: boolean;
}

function parseListArgs(argv: string[]): ListOpts {
  const opts: ListOpts = { verbose: false };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i] as string;
    if (a.startsWith("--domain=")) opts.domain = a.slice("--domain=".length);
    else if (a === "--domain") opts.domain = argv[++i];
    else if (a.startsWith("--severity=")) {
      const v = a.slice("--severity=".length);
      if (v === "error" || v === "warn" || v === "info") opts.severity = v;
    } else if (a === "-v" || a === "--verbose") opts.verbose = true;
  }
  return opts;
}

function pad(s: string, n: number): string {
  return s + " ".repeat(Math.max(0, n - s.length));
}
