import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { runAcqc } from "../cli-runner.js";
import { getDefaultWorkspaceCwd } from "../cli-runner.js";
import { flag, opt } from "../argv.js";
import { cliResultToToolResult, wrapToolHandler } from "../tool-result.js";

export function registerToolingTools(server: McpServer, run: typeof runAcqc): void {
  server.registerTool(
    "acqc_auto_fix",
    {
      title: "ACQC auto-fix",
      description:
        "Automatically fix common upgrade issues (may modify files unless dryRun). Uses workspace root when projectPath is not provided.",
      inputSchema: {
        projectPath: z.string().optional(),
        fixer: z.string().optional(),
        dryRun: z.boolean().optional(),
        noBackup: z.boolean().optional(),
        listFixers: z.boolean().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const argv: string[] = [
          "auto-fix",
          ...(args.listFixers
            ? ["--list-fixers"]
            : [args.projectPath === undefined ? "." : path.resolve(args.projectPath)]),
          ...(args.fixer ? (["--fixer", args.fixer] as const) : []),
          ...flag("dry-run", args.dryRun),
          ...flag("no-backup", args.noBackup),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );

  server.registerTool(
    "acqc_backup",
    {
      title: "ACQC backup",
      description:
        "Create, restore, list, delete, or cleanup backups (destructive actions: restore, delete, cleanup). Uses workspace root when projectPath is not provided.",
      inputSchema: {
        action: z.enum(["create", "restore", "list", "delete", "cleanup"]),
        projectPath: z.string().optional(),
        backupDir: z.string().optional(),
        name: z.string().optional(),
        olderThan: z.number().optional(),
      },
    },
    async (args) =>
      wrapToolHandler(async () => {
        const cwd = getDefaultWorkspaceCwd(args.projectPath);
        const needsPath =
          args.action === "create" ||
          args.action === "restore" ||
          args.action === "delete";
        const argv: string[] = [
          "backup",
          args.action,
          ...(needsPath
            ? [args.projectPath === undefined ? "." : path.resolve(args.projectPath)]
            : []),
          ...opt("backup-dir", args.backupDir),
          ...opt("name", args.name),
          ...opt("older-than", args.olderThan),
        ];
        const result = await run(argv, { cwd, projectPath: args.projectPath });
        return cliResultToToolResult(result);
      }),
  );
}
