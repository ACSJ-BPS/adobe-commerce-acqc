import { describe, it, before, after } from "node:test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { resolve, dirname } from "node:path";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";

// Exercises the registered `review_code_completely` prompt end-to-end over a real
// stdio connection to the compiled server — importing server.ts directly would
// call main() at module load (connects a real StdioServerTransport), so a
// subprocess client is the only way to test prompt registration in isolation.

const serverEntry = resolve(dirname(fileURLToPath(import.meta.url)), "../server.js");

let client: Client;

before(async () => {
  client = new Client({ name: "abqc-prompts-test", version: "0.0.0" });
  const transport = new StdioClientTransport({ command: process.execPath, args: [serverEntry] });
  await client.connect(transport);
});

after(async () => {
  await client.close();
});

describe("review_code_completely prompt", () => {
  it("is listed with a target argument", async () => {
    const { prompts } = await client.listPrompts();
    const prompt = prompts.find((p) => p.name === "review_code_completely");
    assert.ok(prompt, "review_code_completely should be registered");
    assert.ok(prompt?.arguments?.some((a) => a.name === "target"));
  });

  it("returns a workflow that names every coverage-loop tool and forbids skipping rules", async () => {
    const result = await client.getPrompt({ name: "review_code_completely", arguments: { target: "src/foo.ts" } });
    const text = result.messages.map((m) => (m.content.type === "text" ? m.content.text : "")).join("\n");
    assert.match(text, /src\/foo\.ts/);
    assert.match(text, /abqc_review_code/);
    assert.match(text, /abqc_submit_judgment/);
    assert.match(text, /abqc_get_coverage_status/);
    assert.match(text, /never (submit generic evidence|skip a rule)/i);
  });

  it("defaults the target to the project root when omitted", async () => {
    const result = await client.getPrompt({ name: "review_code_completely", arguments: {} });
    const text = result.messages.map((m) => (m.content.type === "text" ? m.content.text : "")).join("\n");
    assert.match(text, /the project root/i);
  });
});
