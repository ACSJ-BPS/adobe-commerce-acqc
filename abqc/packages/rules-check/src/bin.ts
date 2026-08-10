#!/usr/bin/env node
import { checkCommand } from "./commands/check.js";
import { coverageCommand } from "./commands/coverage.js";

async function main() {
  const args = process.argv.slice(2);
  const cmd = args[0];

  if (!cmd || cmd === "--help" || cmd === "-h") {
    printHelp();
    return;
  }
  if (cmd === "--version" || cmd === "-V") {
    console.log("abqc-rules-check 0.1.0");
    return;
  }

  try {
    if (cmd === "check") {
      process.exit(await checkCommand(args.slice(1)));
    } else if (cmd === "coverage") {
      const out = args[1]?.startsWith("--out=") ? args[1].slice("--out=".length) : args[2];
      process.exit(await coverageCommand(out));
    } else {
      process.stderr.write(`unknown command: ${cmd}\n`);
      printHelp();
      process.exit(2);
    }
  } catch (e) {
    process.stderr.write(`rules-check error: ${e instanceof Error ? e.message : e}\n`);
    process.exit(3);
  }
}

function printHelp() {
  console.log(`abqc-rules-check — mechanical (non-AI) judgment rule checker

Usage:
  abqc-rules-check check <path...>              Run mechanical + ESLint (non-AI) rule checks
  abqc-rules-check check <path> --format=sarif|json|terminal
  abqc-rules-check check <path> --severity=error|warn|info
  abqc-rules-check check <path> --no-eslint     Skip the deterministic ESLint layer
  abqc-rules-check coverage [--out=COVERAGE.md]   Write rule coverage report
  abqc-rules-check --version
  abqc-rules-check --help

Exit codes:
  0  clean
  1  warn-only findings
  2  error findings or usage error
  3  internal error
`);
}

main();
