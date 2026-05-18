import { spawn } from "node:child_process";
import path from "node:path";
import { log } from "./logger.js";
import { resolveAcqcBin } from "./resolve-bin.js";

export type CliRunResult = {
  stdout: string;
  stderr: string;
  code: number;
  timedOut?: boolean;
};

const DEFAULT_TIMEOUT_MS = 120_000;
const MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

export type RunAcqcOptions = {
  /** Working directory for the PHP process (project root). */
  cwd?: string;
  /** Optional project path used only for resolving `bin/acqc` when ACQC_BIN is unset. */
  projectPath?: string;
  timeoutMs?: number;
  /**
   * When true, spawn cwd is the ACQC install root (for `validate`, `tech-spec`).
   */
  spawnFromInstall?: boolean;
};

/**
 * Spawn `php <bin> ...args`. Resolves with `{ code }` even on non-zero exit
 * (reject only on spawn failure / missing binary).
 */
export async function runAcqc(
  args: string[],
  options: RunAcqcOptions = {},
): Promise<CliRunResult> {
  const cwd = options.cwd ?? process.cwd();
  const timeoutMs =
    options.timeoutMs ??
    Number(process.env.ACQC_MCP_TIMEOUT_MS ?? DEFAULT_TIMEOUT_MS);

  const { binPath, installRoot } = resolveAcqcBin({
    cwd,
    projectPath: options.projectPath,
  });

  const spawnCwd = options.spawnFromInstall ? installRoot : cwd;
  const startTime = Date.now();
  const command = args[0] ?? "unknown";

  log("info", { event: "cli_spawn", command, args, cwd: spawnCwd, binPath });

  return await new Promise((resolve, reject) => {
    const child = spawn("php", [binPath, ...args], {
      cwd: spawnCwd,
      shell: false,
      stdio: ["ignore", "pipe", "pipe"],
      env: process.env,
    });

    let stdout = "";
    let stderr = "";
    let killed = false;
    const timeoutTimer = setTimeout(() => {
      killed = true;
      child.kill("SIGTERM");
    }, timeoutMs);

    const cap = (chunk: Buffer, acc: { s: string; truncated: boolean }) => {
      const next = acc.s.length + chunk.length;
      if (next > MAX_OUTPUT_BYTES) {
        acc.truncated = true;
        const take = Math.max(0, MAX_OUTPUT_BYTES - acc.s.length);
        acc.s += chunk.subarray(0, take).toString("utf8");
        return;
      }
      acc.s += chunk.toString("utf8");
    };

    const outAcc = { s: "", truncated: false };
    const errAcc = { s: "", truncated: false };

    child.stdout?.on("data", (d: Buffer) => cap(d, outAcc));
    child.stderr?.on("data", (d: Buffer) => cap(d, errAcc));

    child.on("error", (err: Error) => {
      clearTimeout(timeoutTimer);
      reject(err);
    });

    child.on("close", (code: number | null, signal: NodeJS.Signals | null) => {
      clearTimeout(timeoutTimer);
      const durationMs = Date.now() - startTime;
      stdout = outAcc.s;
      stderr = errAcc.s;
      if (outAcc.truncated) {
        stdout += "\n[stdout] Output truncated (size limit reached).\n";
      }
      if (errAcc.truncated) {
        stderr += "\n[stderr] Output truncated (size limit reached).\n";
      }
      if (killed) {
        log("warning", { event: "cli_timeout", command, durationMs });
        resolve({
          stdout,
          stderr,
          code: code ?? 1,
          timedOut: true,
        });
        return;
      }
      const exitCode = code ?? (signal ? 1 : 0);
      log(exitCode === 0 ? "info" : "error", {
        event: "cli_done",
        command,
        exitCode,
        durationMs,
      });
      resolve({
        stdout,
        stderr,
        code: exitCode,
      });
    });
  });
}

export function getDefaultWorkspaceCwd(projectPath?: string): string {
  if (projectPath === undefined) {
    return process.cwd();
  }
  return path.resolve(projectPath);
}
