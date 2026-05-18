import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { flag, opt } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerVersionTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_detect_version",
    {
      title: "ACQC detect-version",
      description:
        "Detect Magento/PHP versions. Uses workspace root when projectPath is not provided.",
      inputSchema: {
        projectPath: z.string().optional(),
        checkCompatibility: z.boolean().optional(),
        showMatrix: z.boolean().optional(),
        format: z.enum(["table", "json"]).optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const target =
          args.projectPath === undefined ? "." : path.resolve(args.projectPath);
        const argv: string[] = [
          "detect-version",
          target,
          ...flag("check-compatibility", args.checkCompatibility),
          ...flag("show-matrix", args.showMatrix),
          ...opt("format", args.format ?? "json"),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_upgrade_readiness",
    {
      title: "ACQC upgrade-readiness",
      description: "Upgrade compatibility analysis for a Magento project path.",
      inputSchema: {
        projectPath: z.string().min(1),
        currentVersion: z.string().optional(),
        targetVersion: z.string().optional(),
        currentPhp: z.string().optional(),
        targetPhp: z.string().optional(),
        includeDatabase: z.boolean().optional(),
        format: z.enum(["console", "json", "html", "excel"]).optional(),
        output: z.string().optional(),
        generateReport: z.boolean().optional(),
        hourlyRate: z.number().optional(),
        priority: z.string().optional(),
        minPriority: z.string().optional(),
        issueType: z.string().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const pp = path.resolve(args.projectPath);
        const argv: string[] = [
          "upgrade-readiness",
          pp,
          ...opt("current-version", args.currentVersion),
          ...opt("target-version", args.targetVersion),
          ...opt("current-php", args.currentPhp),
          ...opt("target-php", args.targetPhp),
          ...flag("include-database", args.includeDatabase),
          ...opt("format", args.format ?? "json"),
          ...opt("output", args.output),
          ...flag("generate-report", args.generateReport),
          ...opt("hourly-rate", args.hourlyRate),
          ...opt("priority", args.priority),
          ...opt("min-priority", args.minPriority),
          ...opt("issue-type", args.issueType),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );
}
