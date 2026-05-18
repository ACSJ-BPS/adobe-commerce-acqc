import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { flag, opt } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerConfigTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_config",
    {
      title: "ACQC config",
      description:
        "Configuration management (init, show, set, get, validate, export, import, reset, upgrade). Uses workspace root when projectPath is not provided.",
      inputSchema: {
        action: z.enum(["init", "show", "set", "get", "validate", "export", "import", "reset", "upgrade"]),
        key: z.string().optional(),
        value: z.string().optional(),
        projectPath: z.string().optional(),
        profile: z
          .enum(["minimal", "developer", "enterprise", "ci-cd", "security", "performance"])
          .optional(),
        environment: z
          .enum(["development", "testing", "staging", "production"])
          .optional(),
        template: z.string().optional(),
        format: z.enum(["yaml", "json", "table", "list"]).optional(),
        category: z.string().optional(),
        detailed: z.boolean().optional(),
        input: z.string().optional(),
        output: z.string().optional(),
        merge: z.boolean().optional(),
        strict: z.boolean().optional(),
        backup: z.boolean().optional(),
        dryRun: z.boolean().optional(),
        force: z.boolean().optional(),
        warningsAsErrors: z.boolean().optional(),
        migrate: z.boolean().optional(),
        defaults: z.boolean().optional(),
        config: z.string().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const argv: string[] = [
          "config",
          args.action,
          ...(args.key ? [args.key] : []),
          ...(args.value === undefined ? [] : [args.value]),
          ...opt("config", args.config),
          ...opt("profile", args.profile),
          ...opt("environment", args.environment),
          ...opt("template", args.template),
          ...opt("format", args.format ?? "json"),
          ...opt("category", args.category),
          ...flag("detailed", args.detailed),
          ...opt("input", args.input),
          ...opt("output", args.output),
          ...flag("merge", args.merge),
          ...flag("strict", args.strict),
          ...flag("backup", args.backup),
          ...flag("dry-run", args.dryRun),
          ...flag("force", args.force),
          ...flag("warnings-as-errors", args.warningsAsErrors),
          ...flag("migrate", args.migrate),
          ...flag("defaults", args.defaults),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );
}
