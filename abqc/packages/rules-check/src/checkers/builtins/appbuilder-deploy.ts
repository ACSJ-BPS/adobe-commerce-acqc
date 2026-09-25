import { readFile, access } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { join } from "node:path";
import type { CheckHit, FileChecker, RepoChecker } from "../../types.js";
import { isYamlConfig } from "@abqc/core";

/**
 * STATIC (heuristic). Known limits: gameable by adding any matching comment text
 * regardless of whether a real review happened.
 */
export const disableDownloadUndocumentedChecker: FileChecker = {
  ruleId: "appbuilder/deploy/disable-download-is-irreversible-set-deliberately",
  kind: "file",
  appliesTo: isYamlConfig,
  check(ctx) {
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    const disableRe = /disable-download\s*:\s*['"]?true['"]?/i;
    const reviewRe = /review|ticket|approved|documented/i;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (!disableRe.test(line)) continue;
      const inlineComment = line.includes("#") ? line.slice(line.indexOf("#")) : "";
      const above1 = lines[i - 1] ?? "";
      const above2 = lines[i - 2] ?? "";
      const documented =
        (inlineComment && reviewRe.test(inlineComment)) ||
        (above1.trim().startsWith("#") && reviewRe.test(above1)) ||
        (above2.trim().startsWith("#") && reviewRe.test(above2));
      if (!documented) {
        hits.push({
          line: i + 1,
          message:
            "disable-download: true is irreversible once set — document the deliberate decision with a nearby comment (review/ticket/approved/documented) before shipping.",
        });
      }
    }
    return hits;
  },
};

async function fileExists(path: string): Promise<boolean> {
  try {
    await access(path);
    return true;
  } catch {
    return false;
  }
}

/**
 * STATIC (heuristic) (P1+P5). Known limits: validates the documented estimate's
 * presence only, not that it matches Adobe's actual pack size.
 */
export const licenseQuotaDocChecker: RepoChecker = {
  ruleId: "appbuilder/deploy/know-your-license-pack-quotas-design-within-them",
  kind: "repo",
  async check(ctx) {
    const docPath = join(ctx.projectRoot, "docs", "license-quotas.md");
    const inAllFiles = ctx.allFiles.some((f) => f.replace(/\\/g, "/").endsWith("docs/license-quotas.md"));
    if (inAllFiles || (await fileExists(docPath))) return [];
    return [
      {
        line: 1,
        path: docPath,
        message:
          "No docs/license-quotas.md found — document your Adobe I/O license pack quotas and design within them (concurrency/invocation limits vary by pack).",
      },
    ];
  },
};

/**
 * STATIC (heuristic) (P4). Deliberately simple best-effort version — see task spec.
 * Known limits: correlates only with 'just committed', not actual deploy
 * timing; a legitimate same-day iteration gets flagged.
 */
export const gatewayProvisioningWindowChecker: RepoChecker = {
  ruleId: "appbuilder/deploy/api-gateway-provisioning-takes-5-10-minutes-plan-deploys-around-",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const candidates = ctx.allFiles.filter((f) =>
      /(^|[\\/])(app\.config\.ya?ml|[^\\/]*actions?\.config\.ya?ml)$/.test(f.replace(/\\/g, "/")),
    );
    for (const file of candidates) {
      let content: string;
      try {
        content = await readFile(file, "utf8");
      } catch {
        continue;
      }
      if (!/\bapis\s*:/.test(content)) continue;
      try {
        const log = execFileSync("git", ["log", "--format=%H %ct", "--", file], {
          cwd: ctx.projectRoot,
          encoding: "utf8",
          stdio: ["ignore", "pipe", "ignore"],
        });
        const firstLine = log.split("\n").find((l) => l.trim());
        if (!firstLine) continue;
        const ct = Number(firstLine.trim().split(" ")[1]);
        if (!Number.isFinite(ct)) continue;
        const ageSeconds = Date.now() / 1000 - ct;
        if (ageSeconds < 900) {
          hits.push({
            line: 1,
            path: file,
            message:
              "app.config.yaml apis: block was committed less than 15 minutes ago — API gateway provisioning takes 5-10 minutes, plan deploys around it before considering this deployed.",
          });
        }
      } catch {
        continue;
      }
    }
    return hits;
  },
};
