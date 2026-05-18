import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { flag, opt, repeatOpt, stripScanAiFlags } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerAnalysisTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_audit",
    {
      title: "ACQC audit",
      description:
        "Run comprehensive project-wide quality audit. Uses workspace root when projectPath is not provided.",
      inputSchema: {
        projectPath: z.string().optional(),
        output: z.enum(["json", "html", "console"]).optional(),
        severity: z.enum(["info", "warning", "error"]).optional(),
        exclude: z.array(z.string()).optional(),
        config: z.string().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const target =
          args.projectPath === undefined ? "." : path.resolve(args.projectPath);
        const argv: string[] = [
          "audit",
          target,
          ...opt("output", args.output ?? "json"),
          ...(args.severity ? (["--severity", args.severity] as const) : []),
          ...repeatOpt("exclude", args.exclude),
          ...(args.config ? (["--config", args.config] as const) : []),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_analyze",
    {
      title: "ACQC analyze",
      description:
        "Granular file/module-level code analysis. Uses workspace root when projectPath is not provided.",
      inputSchema: {
        projectPath: z.string().optional(),
        analyzers: z.string().optional(),
        useCase: z
          .enum(["magento-full", "pwa-full", "security", "pre-deployment", "quick-scan"])
          .optional(),
        category: z.string().optional(),
        profile: z.enum(["magento-developer", "magento-lead", "ci-pipeline"]).optional(),
        format: z.enum(["table", "json", "xml", "html"]).optional(),
        severity: z.enum(["info", "warning", "error", "critical"]).optional(),
        module: z.boolean().optional(),
        modulePath: z.string().optional(),
        allModules: z.boolean().optional(),
        baseline: z.string().optional(),
        outputFile: z.string().optional(),
        parallel: z.boolean().optional(),
        exclude: z.array(z.string()).optional(),
        include: z.array(z.string()).optional(),
        fix: z.boolean().optional(),
        generateFix: z.boolean().optional(),
        listAnalyzers: z.boolean().optional(),
        listUseCases: z.boolean().optional(),
        listProfiles: z.boolean().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const target =
          args.projectPath === undefined ? "." : path.resolve(args.projectPath);
        const argv: string[] = [
          "analyze",
          target,
          ...opt("format", args.format ?? "json"),
          ...opt("analyzers", args.analyzers),
          ...opt("use-case", args.useCase),
          ...opt("category", args.category),
          ...opt("profile", args.profile),
          ...(args.severity ? (["--severity", args.severity] as const) : []),
          ...flag("module", args.module),
          ...opt("module-path", args.modulePath),
          ...flag("all-modules", args.allModules),
          ...opt("baseline", args.baseline),
          ...opt("output-file", args.outputFile),
          ...flag("parallel", args.parallel),
          ...repeatOpt("exclude", args.exclude),
          ...repeatOpt("include", args.include),
          ...flag("fix", args.fix),
          ...flag("generate-fix", args.generateFix),
          ...flag("list-analyzers", args.listAnalyzers),
          ...flag("list-use-cases", args.listUseCases),
          ...flag("list-profiles", args.listProfiles),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_scan",
    {
      title: "ACQC scan",
      description:
        "Advanced code scan (AI-related flags are never passed). Uses workspace root when projectPath is not provided.",
      inputSchema: {
        projectPath: z.string().optional(),
        format: z
          .enum(["json", "xml", "html", "pdf", "junit", "csv", "txt", "console"])
          .optional(),
        output: z.string().optional(),
        role: z
          .enum(["developer", "lead", "architect", "security", "performance", "ci"])
          .optional(),
        severity: z.array(z.string()).optional(),
        include: z.array(z.string()).optional(),
        exclude: z.array(z.string()).optional(),
        rules: z.string().optional(),
        skipRules: z.string().optional(),
        parallel: z.number().optional(),
        timeout: z.number().optional(),
        baseline: z.string().optional(),
        updateBaseline: z.boolean().optional(),
        driftOnly: z.boolean().optional(),
        fix: z.boolean().optional(),
        fixRisky: z.boolean().optional(),
        dryRun: z.boolean().optional(),
        noCache: z.boolean().optional(),
        profile: z.string().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const target =
          args.projectPath === undefined ? "." : path.resolve(args.projectPath);
        const argv = stripScanAiFlags([
          "scan",
          target,
          ...opt("format", args.format ?? "json"),
          ...opt("output", args.output),
          ...opt("role", args.role),
          ...repeatOpt("severity", args.severity),
          ...repeatOpt("include", args.include),
          ...repeatOpt("exclude", args.exclude),
          ...opt("rules", args.rules),
          ...opt("skip-rules", args.skipRules),
          ...(args.parallel === undefined ? [] : ["--parallel", String(args.parallel)]),
          ...opt("timeout", args.timeout),
          ...opt("baseline", args.baseline),
          ...flag("update-baseline", args.updateBaseline),
          ...flag("drift-only", args.driftOnly),
          ...flag("fix", args.fix),
          ...flag("fix-risky", args.fixRisky),
          ...flag("dry-run", args.dryRun),
          ...flag("no-cache", args.noCache),
          ...opt("profile", args.profile),
        ]);
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );
}
