import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { flag, opt, repeatOpt } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerBaselineTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_baseline",
    {
      title: "ACQC baseline",
      description:
        "Create, update, compare, and manage quality baselines. Uses workspace root when projectPath is not provided.",
      inputSchema: {
        action: z.enum(["create", "update", "compare", "show", "list", "clean", "export"]),
        projectPath: z.string().optional(),
        baseline: z.string().optional(),
        name: z.string().optional(),
        description: z.string().optional(),
        compareWith: z.string().optional(),
        tolerance: z.number().optional(),
        ignoreNew: z.boolean().optional(),
        ignoreResolved: z.boolean().optional(),
        severity: z.array(z.string()).optional(),
        category: z.array(z.string()).optional(),
        include: z.string().optional(),
        exclude: z.string().optional(),
        format: z.enum(["json", "xml", "html", "csv", "console"]).optional(),
        output: z.string().optional(),
        detailed: z.boolean().optional(),
        force: z.boolean().optional(),
        backup: z.boolean().optional(),
        keepVersions: z.number().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const target =
          args.projectPath === undefined ? "." : path.resolve(args.projectPath);
        const argv: string[] = [
          "baseline",
          args.action,
          target,
          ...opt("baseline", args.baseline),
          ...opt("name", args.name),
          ...opt("description", args.description),
          ...opt("compare-with", args.compareWith),
          ...opt("tolerance", args.tolerance),
          ...flag("ignore-new", args.ignoreNew),
          ...flag("ignore-resolved", args.ignoreResolved),
          ...repeatOpt("severity", args.severity),
          ...repeatOpt("category", args.category),
          ...opt("include", args.include),
          ...opt("exclude", args.exclude),
          ...opt("format", args.format ?? "json"),
          ...opt("output", args.output),
          ...flag("detailed", args.detailed),
          ...flag("force", args.force),
          ...flag("backup", args.backup),
          ...(args.keepVersions === undefined ? [] : opt("keep-versions", args.keepVersions)),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );
}
