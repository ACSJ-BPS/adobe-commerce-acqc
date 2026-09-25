import type { CheckHit, CheckContext, FileChecker } from "../../types.js";
import { isSourceFile, isYamlConfig } from "@abqc/core";

export const mathRandomIdsChecker: FileChecker = {
  ruleId: "node/bug-classes/use-crypto-randomuuid-not-math-random-for-ids-and-tokens",
  kind: "file",
  appliesTo: isSourceFile,
  check(ctx) {
    const hits: CheckHit[] = [];
    const lines = ctx.content.split("\n");
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      if (!/Math\.random\s*\(/.test(line)) continue;
      if (/\b(id|token|secret|key|uuid|nonce|salt)\b/i.test(line)) {
        hits.push({
          line: i + 1,
          message: "Use crypto.randomUUID() or crypto.randomBytes() for IDs/tokens — not Math.random()",
        });
      }
    }
    return hits;
  },
};

export const inlineSecretChecker: FileChecker = {
  ruleId: "security/supply-chain/no-hard-coded-api-keys-in-source-params-git-history",
  kind: "file",
  // Also scan App Builder YAML config (actions.config.yaml `inputs:` etc.). The
  // kvRe (`key: 'value'`) and highEntropyRe (sk_live_…, AKIA…) below already work
  // on YAML lines, and isLikelySecretValue already treats $-prefixed / process.env
  // values as non-secrets — so `$ENV_VAR` references stay clean. Unquoted generic
  // YAML scalars are intentionally not matched (kvRe requires quotes) to avoid
  // false positives across ordinary config values.
  appliesTo: (p) => isSourceFile(p) || isYamlConfig(p),
  check(ctx) {
    if (/\.(test|spec)\.(js|ts)$/.test(ctx.filePath)) return [];
    const hits: CheckHit[] = [];
    const kvRe = /(?<key>[A-Za-z0-9_]*(?:api[_-]?key|secret|token|password|passwd|private[_-]?key)[A-Za-z0-9_]*)\s*[=:]\s*['"](?<val>[^'"]{6,})['"]/gi;
    const highEntropyRe = /\b(?:AKIA[0-9A-Z]{12,}|sk_live_[A-Za-z0-9]{10,}|sk_test_[A-Za-z0-9]{10,}|gh[pousr]_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9-]{10,})\b/;
    const lines = ctx.content.split("\n");
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i] ?? "";
      let flagged = false;
      kvRe.lastIndex = 0;
      let m: RegExpExecArray | null;
      while ((m = kvRe.exec(line)) !== null) {
        const key = m.groups?.key ?? "";
        const val = m.groups?.val ?? "";
        if (isLikelySecretValue(val, key)) {
          hits.push({ line: i + 1, message: "Possible hard-coded secret — use environment variables or Secret Vault" });
          flagged = true;
          break;
        }
      }
      if (!flagged && highEntropyRe.test(line)) {
        hits.push({ line: i + 1, message: "Possible hard-coded credential token — use environment variables or Secret Vault" });
      }
    }
    return hits;
  },
};

function isLikelySecretValue(val: string, key: string): boolean {
  const v = val.trim();
  if (!v) return false;
  if (/^\$/.test(v) || /^\$\{/.test(v) || /process\.env/.test(v)) return false;
  if (/^[A-Z0-9_]+$/.test(v)) return false;
  if (/^[A-Za-z0-9_.-]+$/.test(v) && v.toLowerCase() === key.toLowerCase()) return false;
  if (/(your[_-]?|example|changeme|placeholder|dummy|test[_-]?|xxx+|<.+>|\bfake\b)/i.test(v)) return false;
  if (/^https?:\/\//.test(v) && !/:[^/@]+@/.test(v)) return false;
  if (/^\.?\.?\//.test(v)) return false;
  if (/\s/.test(v)) return false;
  const hasLower = /[a-z]/.test(v);
  const hasUpper = /[A-Z]/.test(v);
  const hasDigit = /[0-9]/.test(v);
  const mixed = (hasLower && hasDigit) || (hasUpper && hasLower && hasDigit) || v.length >= 20;
  return mixed;
}
