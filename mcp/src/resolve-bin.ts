import fs from "node:fs";
import path from "node:path";

export type ResolvedBin = {
  binPath: string;
  /** ACQC repository root (parent of `bin/`) when layout is `.../bin/acqc`. */
  installRoot: string;
};

function installRootFromBinPath(binPath: string): string {
  const binDir = path.dirname(binPath);
  if (path.basename(binDir) === "bin") {
    return path.dirname(binDir);
  }
  return binDir;
}

/**
 * Resolves `bin/acqc` per spec: ACQC_BIN, then projectPath, then cwd.
 */
export function resolveAcqcBin(options: {
  cwd: string;
  projectPath?: string;
}): ResolvedBin {
  const envBin = process.env.ACQC_BIN;
  if (envBin !== undefined && envBin.length > 0) {
    const binPath = path.resolve(envBin);
    if (!fs.existsSync(binPath)) {
      throw new Error(
        `ACQC_BIN is set but file does not exist: ${binPath}`,
      );
    }
    const stat = fs.statSync(binPath);
    if (!stat.isFile()) {
      throw new Error(`ACQC_BIN must point to the acqc file, not a directory: ${binPath}`);
    }
    return { binPath, installRoot: installRootFromBinPath(binPath) };
  }

  const base =
    options.projectPath === undefined
      ? path.resolve(options.cwd)
      : path.resolve(options.projectPath);
  const binPath = path.resolve(base, "bin/acqc");
  if (!fs.existsSync(binPath)) {
    throw new Error(
      `Could not find acqc at ${binPath}. Set ACQC_BIN or run from a repo with bin/acqc.`,
    );
  }
  return { binPath, installRoot: installRootFromBinPath(binPath) };
}
