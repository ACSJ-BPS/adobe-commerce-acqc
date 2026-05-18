import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { opt } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerReportingTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_report",
    {
      title: "ACQC report",
      description:
        "Generate quality reports. Uses workspace root when projectPath is not provided.",
      inputSchema: {
        projectPath: z.string().optional(),
        format: z.enum(["json", "html", "excel", "pdf"]).optional(),
        type: z.string().optional(),
        role: z
          .enum(["developer", "lead", "architect", "manager", "executive"])
          .optional(),
        projectName: z.string().optional(),
        output: z.string().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const target =
          args.projectPath === undefined ? "." : path.resolve(args.projectPath);
        const argv: string[] = [
          "report",
          target,
          ...opt("format", args.format ?? "json"),
          ...opt("type", args.type),
          ...opt("role", args.role),
          ...opt("project-name", args.projectName),
          ...opt("output", args.output),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_project_quality",
    {
      title: "ACQC project-quality",
      description: "Generate leadership Excel / JSON project quality report.",
      inputSchema: {
        projectPath: z.string().min(1),
        projectName: z.string().optional(),
        region: z.enum(["EMEA", "APAC", "NAM"]).optional(),
        kind: z.enum(["Implementation", "R&O"]).optional(),
        solutions: z.string().optional(),
        description: z.string().optional(),
        sprint: z.number().optional(),
        resources: z.number().optional(),
        resourceNames: z.string().optional(),
        hours: z.number().optional(),
        qaPercentage: z.number().optional(),
        requirements: z.number().optional(),
        testCases: z.number().optional(),
        actualHours: z.number().optional(),
        estimatedHours: z.number().optional(),
        velocity: z.number().optional(),
        output: z.string().optional(),
        format: z.enum(["excel", "json"]).optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const pp = path.resolve(args.projectPath);
        const argv: string[] = [
          "project-quality",
          pp,
          ...opt("project-name", args.projectName),
          ...opt("region", args.region),
          ...opt("kind", args.kind),
          ...opt("solutions", args.solutions),
          ...opt("description", args.description),
          ...opt("sprint", args.sprint),
          ...opt("resources", args.resources),
          ...opt("resource-names", args.resourceNames),
          ...opt("hours", args.hours),
          ...opt("qa-percentage", args.qaPercentage),
          ...opt("requirements", args.requirements),
          ...opt("test-cases", args.testCases),
          ...opt("actual-hours", args.actualHours),
          ...opt("estimated-hours", args.estimatedHours),
          ...opt("velocity", args.velocity),
          ...opt("output", args.output),
          ...opt("format", args.format ?? "json"),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );
}
