import { describe, expect, it, vi } from "vitest";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { CliRunResult, RunAcqcOptions } from "../cli-runner.js";
import { registerAnalysisTools } from "./analysis.js";
import { registerReportingTools } from "./reporting.js";
import { registerConfigTools } from "./config.js";
import { registerVersionTools } from "./version.js";
import { registerQualityTools } from "./quality.js";
import { registerToolingTools } from "./tooling.js";
import { registerBaselineTools } from "./baseline.js";

/** Captured call from a mock run. */
type CapturedCall = { args: string[]; options: RunAcqcOptions };

function createTestHarness() {
  const calls: CapturedCall[] = [];

  const mockRun = vi.fn(
    async (args: string[], options: RunAcqcOptions = {}): Promise<CliRunResult> => {
      calls.push({ args, options });
      return { stdout: '{"ok":true}', stderr: "", code: 0 };
    },
  );

  const server = new McpServer({ name: "test", version: "0.0.0" });

  registerAnalysisTools(server, mockRun);
  registerReportingTools(server, mockRun);
  registerConfigTools(server, mockRun);
  registerVersionTools(server, mockRun);
  registerQualityTools(server, mockRun);
  registerToolingTools(server, mockRun);
  registerBaselineTools(server, mockRun);

  // Access internal registered tools to invoke handlers directly.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const tools = (server as any)._registeredTools as Record<
    string,
    { handler: (args: Record<string, unknown>) => Promise<unknown> }
  >;

  async function callTool(name: string, args: Record<string, unknown> = {}) {
    const tool = tools[name];
    if (!tool) {
      throw new Error(`Tool "${name}" not registered`);
    }
    calls.length = 0;
    const result = await tool.handler(args);
    const captured = calls[0];
    if (!captured) {
      throw new Error(`Tool "${name}" did not invoke run`);
    }
    return { result, captured };
  }

  return { callTool, calls, mockRun };
}

// ---------------------------------------------------------------------------
// Analysis tools
// ---------------------------------------------------------------------------

describe("acqc_audit", () => {
  it("assembles default argv with json output", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_audit", {});
    expect(captured.args[0]).toBe("audit");
    expect(captured.args).toContain("--output");
    expect(captured.args[captured.args.indexOf("--output") + 1]).toBe("json");
  });

  it("passes severity and exclude", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_audit", {
      severity: "error",
      exclude: ["vendor/*", "test/*"],
    });
    expect(captured.args).toContain("--severity");
    expect(captured.args[captured.args.indexOf("--severity") + 1]).toBe("error");
    expect(captured.args).toContain("--exclude");
    // Two --exclude flags for two values
    const excludeIndices = captured.args
      .map((a, i) => (a === "--exclude" ? i : -1))
      .filter((i) => i >= 0);
    expect(excludeIndices).toHaveLength(2);
  });
});

describe("acqc_analyze", () => {
  it("defaults format to json", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_analyze", {});
    expect(captured.args).toContain("--format");
    expect(captured.args[captured.args.indexOf("--format") + 1]).toBe("json");
  });

  it("passes use-case and parallel flag", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_analyze", {
      useCase: "security",
      parallel: true,
    });
    expect(captured.args).toContain("--use-case");
    expect(captured.args[captured.args.indexOf("--use-case") + 1]).toBe("security");
    expect(captured.args).toContain("--parallel");
  });

  it("passes list-analyzers flag", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_analyze", { listAnalyzers: true });
    expect(captured.args).toContain("--list-analyzers");
  });
});

describe("acqc_scan", () => {
  it("strips AI flags from argv", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_scan", { format: "json" });
    expect(captured.args).not.toContain("--ai-insights");
    expect(captured.args).not.toContain("--predictive");
  });

  it("passes parallel as number", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_scan", { parallel: 4 });
    expect(captured.args).toContain("--parallel");
    expect(captured.args[captured.args.indexOf("--parallel") + 1]).toBe("4");
  });

  it("passes role and baseline flags", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_scan", {
      role: "security",
      baseline: "base.json",
      driftOnly: true,
    });
    expect(captured.args).toContain("--role");
    expect(captured.args[captured.args.indexOf("--role") + 1]).toBe("security");
    expect(captured.args).toContain("--baseline");
    expect(captured.args).toContain("--drift-only");
  });
});

// ---------------------------------------------------------------------------
// Reporting tools
// ---------------------------------------------------------------------------

describe("acqc_report", () => {
  it("defaults format to json", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_report", {});
    expect(captured.args[0]).toBe("report");
    expect(captured.args[captured.args.indexOf("--format") + 1]).toBe("json");
  });

  it("passes role and type", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_report", {
      role: "executive",
      type: "security",
    });
    expect(captured.args).toContain("--role");
    expect(captured.args[captured.args.indexOf("--role") + 1]).toBe("executive");
    expect(captured.args).toContain("--type");
    expect(captured.args[captured.args.indexOf("--type") + 1]).toBe("security");
  });
});

describe("acqc_project_quality", () => {
  it("passes projectPath as positional and metadata opts", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_project_quality", {
      projectPath: "/tmp/magento",
      region: "EMEA",
      sprint: 5,
    });
    expect(captured.args[0]).toBe("project-quality");
    expect(captured.args).toContain("--region");
    expect(captured.args[captured.args.indexOf("--region") + 1]).toBe("EMEA");
    expect(captured.args).toContain("--sprint");
    expect(captured.args[captured.args.indexOf("--sprint") + 1]).toBe("5");
  });
});

// ---------------------------------------------------------------------------
// Config tool
// ---------------------------------------------------------------------------

describe("acqc_config", () => {
  it("assembles set action with key and value", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_config", {
      action: "set",
      key: "ai.provider",
      value: "claude",
    });
    expect(captured.args[0]).toBe("config");
    expect(captured.args[1]).toBe("set");
    expect(captured.args[2]).toBe("ai.provider");
    expect(captured.args[3]).toBe("claude");
  });

  it("passes init with template and force", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_config", {
      action: "init",
      template: "enterprise",
      force: true,
    });
    expect(captured.args[1]).toBe("init");
    expect(captured.args).toContain("--template");
    expect(captured.args[captured.args.indexOf("--template") + 1]).toBe("enterprise");
    expect(captured.args).toContain("--force");
  });
});

// ---------------------------------------------------------------------------
// Version tools
// ---------------------------------------------------------------------------

describe("acqc_detect_version", () => {
  it("defaults format to json", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_detect_version", {});
    expect(captured.args[0]).toBe("detect-version");
    expect(captured.args[captured.args.indexOf("--format") + 1]).toBe("json");
  });

  it("passes check-compatibility flag", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_detect_version", {
      checkCompatibility: true,
    });
    expect(captured.args).toContain("--check-compatibility");
  });
});

describe("acqc_upgrade_readiness", () => {
  it("does not inject hardcoded defaults when versions omitted", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_upgrade_readiness", {
      projectPath: "/tmp/magento",
    });
    expect(captured.args[0]).toBe("upgrade-readiness");
    // No --target-version or --target-php should appear when not provided
    expect(captured.args).not.toContain("--target-version");
    expect(captured.args).not.toContain("--target-php");
  });

  it("passes explicit versions through", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_upgrade_readiness", {
      projectPath: "/tmp/magento",
      targetVersion: "2.4.9",
      targetPhp: "8.4",
    });
    expect(captured.args).toContain("--target-version");
    expect(captured.args[captured.args.indexOf("--target-version") + 1]).toBe("2.4.9");
    expect(captured.args).toContain("--target-php");
    expect(captured.args[captured.args.indexOf("--target-php") + 1]).toBe("8.4");
  });

  it("passes hourly-rate and priority filters", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_upgrade_readiness", {
      projectPath: "/tmp/magento",
      hourlyRate: 150,
      priority: "critical",
    });
    expect(captured.args).toContain("--hourly-rate");
    expect(captured.args[captured.args.indexOf("--hourly-rate") + 1]).toBe("150");
    expect(captured.args).toContain("--priority");
    expect(captured.args[captured.args.indexOf("--priority") + 1]).toBe("critical");
  });
});

// ---------------------------------------------------------------------------
// Quality tools
// ---------------------------------------------------------------------------

describe("acqc_checklist", () => {
  it("passes role as positional arg", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_checklist", {
      role: "architect",
    });
    expect(captured.args[0]).toBe("checklist");
    expect(captured.args[1]).toBe("architect");
  });
});

describe("acqc_tech_spec", () => {
  it("uses spawnFromInstall (no projectPath)", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_tech_spec", {
      role: "senior-developer",
      format: "markdown",
    });
    expect(captured.args[0]).toBe("tech-spec");
    expect(captured.args[1]).toBe("senior-developer");
    expect(captured.options.spawnFromInstall).toBe(true);
  });
});

describe("acqc_validate", () => {
  it("uses spawnFromInstall and defaults format to json", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_validate", {});
    expect(captured.args[0]).toBe("validate");
    expect(captured.args[captured.args.indexOf("--format") + 1]).toBe("json");
    expect(captured.options.spawnFromInstall).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Tooling
// ---------------------------------------------------------------------------

describe("acqc_auto_fix", () => {
  it("passes dry-run flag", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_auto_fix", { dryRun: true });
    expect(captured.args[0]).toBe("auto-fix");
    expect(captured.args).toContain("--dry-run");
  });

  it("passes list-fixers without positional path", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_auto_fix", { listFixers: true });
    expect(captured.args).toContain("--list-fixers");
    // When listFixers is true, no positional path should follow "auto-fix"
    expect(captured.args[1]).toBe("--list-fixers");
  });
});

describe("acqc_backup", () => {
  it("includes positional path for create action", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_backup", { action: "create" });
    expect(captured.args[0]).toBe("backup");
    expect(captured.args[1]).toBe("create");
    // positional path follows action for create
    expect(captured.args[2]).toBe(".");
  });

  it("omits positional path for list action", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_backup", { action: "list" });
    expect(captured.args[0]).toBe("backup");
    expect(captured.args[1]).toBe("list");
    // No "." after list
    expect(captured.args[2]).not.toBe(".");
  });

  it("passes older-than for cleanup", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_backup", {
      action: "cleanup",
      olderThan: 7,
    });
    expect(captured.args).toContain("--older-than");
    expect(captured.args[captured.args.indexOf("--older-than") + 1]).toBe("7");
  });
});

// ---------------------------------------------------------------------------
// Baseline tool
// ---------------------------------------------------------------------------

describe("acqc_baseline", () => {
  it("passes action as positional arg", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_baseline", { action: "create" });
    expect(captured.args[0]).toBe("baseline");
    expect(captured.args[1]).toBe("create");
  });

  it("passes compare-with and tolerance", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_baseline", {
      action: "compare",
      compareWith: "old-baseline.json",
      tolerance: 10,
    });
    expect(captured.args).toContain("--compare-with");
    expect(captured.args[captured.args.indexOf("--compare-with") + 1]).toBe(
      "old-baseline.json",
    );
    expect(captured.args).toContain("--tolerance");
    expect(captured.args[captured.args.indexOf("--tolerance") + 1]).toBe("10");
  });

  it("passes severity as repeated flags", async () => {
    const { callTool } = createTestHarness();
    const { captured } = await callTool("acqc_baseline", {
      action: "show",
      severity: ["error", "critical"],
    });
    const sevIndices = captured.args
      .map((a, i) => (a === "--severity" ? i : -1))
      .filter((i) => i >= 0);
    expect(sevIndices).toHaveLength(2);
  });
});
