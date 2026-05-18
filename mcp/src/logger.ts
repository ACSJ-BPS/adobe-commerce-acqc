import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { LoggingLevel } from "@modelcontextprotocol/sdk/types.js";

const LOGGER_NAME = "acqc-cli";

let _server: McpServer | undefined;

export function initLogger(server: McpServer): void {
  _server = server;
}

export function log(level: LoggingLevel, data: unknown): void {
  if (!_server) {
    return;
  }
  _server.sendLoggingMessage({ level, logger: LOGGER_NAME, data }).catch(() => {
    // Best-effort: swallow errors if client disconnected or logging not supported.
  });
}
