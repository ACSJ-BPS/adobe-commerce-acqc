import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { flag, opt } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerQualityTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_checklist",
    {
      title: "ACQC checklist",
      description:
        "Role-based quality checklists. Uses workspace root when projectPath is not provided.",
      inputSchema: {
        role: z.enum(["junior-developer", "senior-developer", "architect"]),
        projectPath: z.string().optional(),
        format: z.enum(["table", "json", "xml", "html"]).optional(),
        category: z.string().optional(),
        severity: z.enum(["info", "warning", "error", "critical"]).optional(),
        output: z.string().optional(),
        detailed: z.boolean().optional(),
        toolsOnly: z.boolean().optional(),
        summary: z.boolean().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const target =
          args.projectPath === undefined ? "." : path.resolve(args.projectPath);
        const argv: string[] = [
          "checklist",
          args.role,
          target,
          ...opt("format", args.format ?? "json"),
          ...opt("category", args.category),
          ...(args.severity ? (["--severity", args.severity] as const) : []),
          ...opt("output", args.output),
          ...flag("detailed", args.detailed),
          ...flag("tools-only", args.toolsOnly),
          ...flag("summary", args.summary),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_tech_spec",
    {
      title: "ACQC tech-spec",
      description:
        "Generate technical specification for a role. Operates on the ACQC installation (no projectPath).",
      inputSchema: {
        role: z.enum(["junior-developer", "senior-developer", "architect"]),
        format: z.enum(["markdown", "json", "yaml", "html"]).optional(),
        output: z.string().optional(),
        includeTools: z.boolean().optional(),
        includeExamples: z.boolean().optional(),
        includeMetrics: z.boolean().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const argv: string[] = [
          "tech-spec",
          args.role,
          ...opt("format", args.format ?? "json"),
          ...opt("output", args.output),
          ...flag("include-tools", args.includeTools),
          ...flag("include-examples", args.includeExamples),
          ...flag("include-metrics", args.includeMetrics),
        ];
        const result = await run(argv, { spawnFromInstall: true });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_validate",
    {
      title: "ACQC validate",
      description:
        "Validate analyzers and rules for the ACQC installation (no projectPath).",
      inputSchema: {
        export: z.string().optional(),
        format: z.enum(["json", "yaml", "md"]).optional(),
        detailed: z.boolean().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const argv: string[] = [
          "validate",
          ...opt("export", args.export),
          ...opt("format", args.format ?? "json"),
          ...flag("detailed", args.detailed),
        ];
        const result = await run(argv, { spawnFromInstall: true });
        return cliResultToToolResult(result);
      }),
  );
}
