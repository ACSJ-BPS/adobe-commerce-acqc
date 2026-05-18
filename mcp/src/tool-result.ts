import type { CallToolResult } from "@modelcontextprotocol/sdk/types.js";
import type { CliRunResult } from "./cli-runner.js";

function tryParseJson(text: string): unknown {
  const trimmed = text.trim();
  if (!trimmed) {
    return null;
  }
  try {
    return JSON.parse(trimmed) as unknown;
  } catch {
    return null;
  }
}

export function cliResultToToolResult(result: CliRunResult): CallToolResult {
  const { stdout, stderr, code, timedOut } = result;

  if (timedOut) {
    return {
      isError: true,
      content: [
        {
          type: "text",
          text: `Command timed out.\n${stderr || stdout}`,
        },
      ],
    };
  }

  if (code !== 0) {
    const msg = stderr?.trim() || stdout?.trim() || `Process exited with code ${code}`;
    return {
      isError: true,
      content: [{ type: "text", text: msg }],
    };
  }

  const parsed = tryParseJson(stdout);
  const text = parsed === null ? stdout : JSON.stringify(parsed, null, 2);

  return {
    content: [{ type: "text", text }],
  };
}

export function spawnErrorToToolResult(err: unknown): CallToolResult {
  const message =
    err instanceof Error
      ? err.message
      : "Unknown error spawning PHP / m2-quality process.";
  return {
    isError: true,
    content: [{ type: "text", text: `PHP not found or could not spawn process: ${message}` }],
  };
}

export function wrapToolHandler(
  fn: () => Promise<CallToolResult>,
): Promise<CallToolResult> {
  return fn().catch((err: unknown) => spawnErrorToToolResult(err));
}
