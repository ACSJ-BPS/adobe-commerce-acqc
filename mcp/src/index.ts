import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { runAcqc } from "./cli-runner.js";
import { initLogger } from "./logger.js";
import { registerAnalysisTools } from "./tools/analysis.js";
import { registerBaselineTools } from "./tools/baseline.js";
import { registerConfigTools } from "./tools/config.js";
import { registerQualityTools } from "./tools/quality.js";
import { registerReportingTools } from "./tools/reporting.js";
import { registerRulesTools } from "./tools/rules.js";
import { registerToolingTools } from "./tools/tooling.js";
import { registerVersionTools } from "./tools/version.js";

const server = new McpServer({
  name: "acqc",
  version: "1.0.0",
});

server.server.registerCapabilities({ logging: {} });
initLogger(server);

registerAnalysisTools(server, runAcqc);
registerReportingTools(server, runAcqc);
registerConfigTools(server, runAcqc);
registerVersionTools(server, runAcqc);
registerQualityTools(server, runAcqc);
registerToolingTools(server, runAcqc);
registerBaselineTools(server, runAcqc);
registerRulesTools(server, runAcqc);

const transport = new StdioServerTransport();
await server.connect(transport);
