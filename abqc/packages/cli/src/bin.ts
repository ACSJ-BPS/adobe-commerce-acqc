#!/usr/bin/env node
import { reviewCommand } from "./commands/review.js";
import { rulesCommand } from "./commands/rules.js";

async function main() {
  const args = process.argv.slice(2);
  const cmd = args[0];

  if (!cmd || cmd === "--help" || cmd === "-h") {
    printHelp();
    return;
  }
  if (cmd === "--version" || cmd === "-V") {
    console.log("abqc 0.1.0");
    return;
  }

  try {
    if (cmd === "review") {
      // Setting exitCode (rather than calling process.exit()) lets Node drain
      // stdout before exiting. review's --format=json output can exceed the
      // OS pipe buffer (64KB on macOS); a piped consumer (e.g. the ACQC PHP
      // Process wrapper) would otherwise see the JSON truncated mid-object
      // because exit() tears the process down before an in-flight async pipe
      // write finishes.
      process.exitCode = await reviewCommand(args.slice(1));
    } else if (cmd === "rules") {
      process.exitCode = await rulesCommand(args.slice(1));
    } else {
      console.error(`unknown command: ${cmd}`);
      printHelp();
      process.exitCode = 2;
    }
  } catch (e) {
    console.error("engine error:", e instanceof Error ? e.message : e);
    process.exitCode = 3;
  }
}

function printHelp() {
  console.log(`abqc — App Builder code-quality review

Usage:
  abqc review <path...>                  Review files or directories (ESLint only)
  abqc review <path> --ai                Enable LLM judgment layer (requires claude/ANTHROPIC_API_KEY)
  abqc review <path> --provider=claude-cli|anthropic|openai|stub
  abqc review <path> --diff=main         Only review files changed vs git ref
  abqc review <path> --format=sarif|json|github|terminal
  abqc review <path> --severity=error|warn|info
  abqc rules list                        List all loaded rules
  abqc rules list --domain=appbuilder
  abqc rules get <ruleId>                Show one rule's full text
  abqc --version                         Print version
  abqc --help                            Show this help

Environment variables:
  ABQC_PROVIDER=anthropic|openai|claude-cli|stub   — enables LLM judgment + picks provider
  ABQC_MODEL=claude-sonnet-4-6                     — model override for Anthropic/Claude-CLI
  ANTHROPIC_API_KEY                                — enables AnthropicProvider automatically
  OPENAI_API_KEY                                   — enables OpenAIProvider automatically

Exit codes:
  0  clean
  1  warn-only findings
  2  error findings
  3  engine error
`);
}

main().catch((e) => {
  console.error("fatal:", e);
  process.exitCode = 3;
});
