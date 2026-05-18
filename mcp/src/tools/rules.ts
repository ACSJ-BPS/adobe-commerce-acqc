import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { flag, opt } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerRulesTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_init",
    {
      title: "ACQC init",
      description:
        "Initialise ACQC for a project: creates .acqc/project.yaml with platform detection and optionally generates a CI workflow file.",
      inputSchema: {
        projectPath: z.string().optional(),
        ci: z.enum(["github", "gitlab", "bitbucket"]).optional(),
        profile: z.string().optional(),
        force: z.boolean().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const argv: string[] = [
          "init",
          ...opt("ci", args.ci),
          ...opt("profile", args.profile),
          ...flag("force", args.force),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_doctor",
    {
      title: "ACQC doctor",
      description:
        "Run all ACQC project health checks (config, rules, last scan, CI, git, suppression debt). Returns a score and action items.",
      inputSchema: {
        projectPath: z.string().optional(),
        json: z.boolean().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const argv: string[] = [
          "doctor",
          ...flag("json", args.json),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_rules_list",
    {
      title: "ACQC rules list",
      description:
        "List all rules for a profile with optional filters. Returns a table, JSON, or CSV of rule IDs, titles, severities, tiers, categories, and statuses.",
      inputSchema: {
        projectPath: z.string().optional(),
        profile: z.string().optional(),
        category: z.string().optional(),
        tier: z.number().optional(),
        severity: z.string().optional(),
        activeOnly: z.boolean().optional(),
        format: z.enum(["table", "json", "csv"]).optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const argv: string[] = [
          "rules:list",
          ...opt("profile", args.profile),
          ...opt("category", args.category),
          ...(args.tier !== undefined ? ["--tier", String(args.tier)] : []),
          ...opt("severity", args.severity),
          ...flag("active-only", args.activeOnly),
          ...opt("format", args.format ?? "json"),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_rules_suppress",
    {
      title: "ACQC rules suppress",
      description:
        "Suppress a rule for the current project by adding it to .acqc/project.yaml. A reason is required.",
      inputSchema: {
        ruleId: z.string(),
        reason: z.string(),
        reviewBy: z.string().optional(),
        projectPath: z.string().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const argv: string[] = [
          "rules:suppress",
          args.ruleId,
          "--reason",
          args.reason,
          ...opt("review-by", args.reviewBy),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_rules_validate",
    {
      title: "ACQC rules validate",
      description:
        "Validate all rule YAML files in a directory against the ACQC schema. Reports pass/fail per file and exits non-zero if any fail.",
      inputSchema: {
        path: z.string().optional(),
        projectPath: z.string().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const argv: string[] = [
          "rules:validate",
          ...(args.path ? [args.path] : []),
        ];
        const result = await run(argv, {
          cwd,
          projectPath: args.projectPath,
          spawnFromInstall: !args.path,
        });
        return cliResultToToolResult(result);
      }),
  );
}
