import { readdir, readFile } from "node:fs/promises";
import { join, relative } from "node:path";
import type { Rule, Severity } from "./types.js";

// Matches: ## Rule Title [severity] [tag1, tag2]
const RULE_HEADING_RE = /^##\s+(.+?)\s+\[(error|warn|info)\]\s*\[([^\]]+)\]\s*$/;

// Matches ### Bad, ### Good, or ### Check
const SUB_HEADING_RE = /^###\s+(Bad|Good|Check)\s*$/i;

const ANY_H2_RE = /^##\s/;

// Any H2 heading with at least one bracket group that still didn't match
// RULE_HEADING_RE — almost certainly an attempted rule heading with a typo
// (missing tags bracket, misspelled severity like "[warning]" instead of
// "[warn]", etc.), not an unrelated section header like "## Overview". Loading
// silently dropped these with zero signal — unlike the duplicate-id case a few
// lines below, which does log — so a rule could vanish from the loaded corpus
// with no indication anything was wrong.
const LOOKS_LIKE_MALFORMED_RULE_RE = /^##\s+.+\[.+\]/;

export class RuleLoader {
  private cache = new Map<string, Rule>();
  private lastLoadedAt = 0;

  constructor(private readonly rulesDir: string) {}

  async load(): Promise<Rule[]> {
    const files = (await this.findMarkdownFiles(this.rulesDir)).sort();
    const rules: Rule[] = [];
    const seenIds = new Map<string, string>();

    for (const file of files) {
      if (file.endsWith("README.md") || file.endsWith("eslint-config.md")) continue;
      const text = await readFile(file, "utf8");
      const parsed = this.parseRulesFromFile(text, file);
      for (const rule of parsed) {
        if (seenIds.has(rule.id)) {
          process.stderr.write(
            `[abqc] rule ID collision: "${rule.id}" defined in both ` +
            `"${seenIds.get(rule.id)}" and "${file}" — second definition ignored\n`,
          );
          continue;
        }
        seenIds.set(rule.id, file);
        rules.push(rule);
      }
    }

    this.cache.clear();
    for (const rule of rules) this.cache.set(rule.id, rule);
    this.lastLoadedAt = Date.now();
    return rules;
  }

  get(id: string): Rule | undefined {
    return this.cache.get(id);
  }

  all(): Rule[] {
    return Array.from(this.cache.values());
  }

  byDomain(domain: string): Rule[] {
    return this.all().filter((r) => r.domain === domain);
  }

  bySeverity(severity: Severity): Rule[] {
    return this.all().filter((r) => r.severity === severity);
  }

  private async findMarkdownFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    const entries = await readdir(dir, { withFileTypes: true });
    for (const entry of entries) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) out.push(...(await this.findMarkdownFiles(full)));
      else if (entry.isFile() && entry.name.endsWith(".md")) out.push(full);
    }
    return out;
  }

  private parseRulesFromFile(text: string, filePath: string): Rule[] {
    const rel = relative(this.rulesDir, filePath).replace(/\\/g, "/");
    const parts = rel.replace(/\.md$/, "").split("/");
    const subTopic = parts.pop() ?? "unknown";
    const domain = parts.join("/") || "root";

    const lines = text.split("\n");
    const rules: Rule[] = [];
    let current: Partial<Rule> | null = null;
    let mode: "rationale" | "check" | "bad" | "good" | null = null;
    let buffer: string[] = [];
    let inFence = false;
    let fenceLang = "";

    const flushBuffer = () => {
      if (!current || mode === null) return;
      const content = buffer.join("\n").trim();
      if (mode === "rationale") current.rationale = content;
      else if (mode === "bad") current.bad = content;
      else if (mode === "good") current.good = content;
      else if (mode === "check") current.checkSpec = content;
      buffer = [];
      inFence = false;
      fenceLang = "";
    };

    const finishCurrent = () => {
      if (!current) return;
      flushBuffer();
      if (current.title && current.severity && current.id) {
        rules.push({
          id: current.id,
          domain,
          subTopic,
          title: current.title,
          severity: current.severity,
          tags: current.tags ?? [],
          rationale: current.rationale ?? "",
          bad: current.bad ?? "",
          good: current.good ?? "",
          filePath,
          checkSpec: current.checkSpec,
        });
      }
      current = null;
      mode = null;
      buffer = [];
      inFence = false;
    };

    for (const [lineIdx, line] of lines.entries()) {
      const ruleMatch = RULE_HEADING_RE.exec(line);
      if (!ruleMatch && LOOKS_LIKE_MALFORMED_RULE_RE.test(line)) {
        process.stderr.write(
          `[abqc] ${filePath}:${lineIdx + 1}: heading looks like a rule but doesn't match the ` +
          `"## Title [severity] [tags]" format — SKIPPED, not loaded: ${line.trim()}\n`,
        );
      }
      if (ruleMatch) {
        finishCurrent();
        const [, title, severity, tagsStr] = ruleMatch;
        const tags = (tagsStr ?? "").split(",").map((t) => t.trim()).filter(Boolean);
        const safeTitle = title ?? "";
        const id = `${domain}/${subTopic}/${slugify(safeTitle)}`;
        current = {
          id,
          title: safeTitle.trim(),
          severity: severity as Severity,
          tags,
        };
        mode = "rationale";
        buffer = [];
        continue;
      }

      const subMatch = SUB_HEADING_RE.exec(line);
      if (subMatch && current) {
        flushBuffer();
        const section = (subMatch[1] ?? "bad").toLowerCase();
        mode = section as "check" | "bad" | "good";
        buffer = [];
        inFence = false;
        continue;
      }

      if (ANY_H2_RE.test(line) && current && mode === "rationale") {
        finishCurrent();
        continue;
      }

      if (current && mode === "check") {
        const fenceOpen = /^```(\w*)\s*$/.exec(line.trim());
        if (fenceOpen && !inFence) {
          inFence = true;
          fenceLang = fenceOpen[1] ?? "";
          buffer = [];
          continue;
        }
        if (inFence && line.trim() === "```") {
          inFence = false;
          continue;
        }
        if (inFence) buffer.push(line);
        continue;
      }

      if (current && mode !== null) buffer.push(line);
    }
    finishCurrent();
    return rules;
  }

  loadedAt(): number {
    return this.lastLoadedAt;
  }
}

function slugify(s: string): string {
  return s
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 64);
}
