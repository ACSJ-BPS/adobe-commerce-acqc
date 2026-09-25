import { join, relative, dirname } from "node:path";
import { access, readFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import type { CheckHit, RepoChecker } from "../types.js";

async function fileExists(path: string): Promise<boolean> {
  try {
    await access(path);
    return true;
  } catch {
    return false;
  }
}

async function readGitignore(projectRoot: string): Promise<string> {
  try {
    return await readFile(join(projectRoot, ".gitignore"), "utf8");
  } catch {
    return "";
  }
}

function gitignoreCovers(gitignore: string, pattern: string): boolean {
  const lines = gitignore.split("\n").map((l) => l.trim()).filter(Boolean);
  const base = pattern.replace(/^\./, "");
  return lines.some((l) => {
    if (l === pattern || l === `${pattern}/` || l === `/${pattern}`) return true;
    if (l === base || l === `*.${base}` || l === `**/${pattern}` || l === `**/${pattern}/`) return true;
    if (l === "*.env" && pattern === ".env") return true;
    if (l.includes("*") && globLineMatches(l, pattern)) return true;
    return false;
  });
}

function globLineMatches(globLine: string, name: string): boolean {
  const re = new RegExp(
    "^" + globLine.replace(/\./g, "\\.").replace(/\*\*/g, "§§").replace(/\*/g, "[^/]*").replace(/§§/g, ".*") + "$",
  );
  return re.test(name) || re.test(`foo/${name}`);
}

// console.json (Dev Console credentials download) is checked the same way as
// .env/.aio — an exact tracked-file name. *.pem/*.key are glob patterns, so
// `git ls-files --error-unmatch <name>` needs an exact path per match rather
// than the glob itself — found via ctx.allFiles, then verified individually.
const EXACT_SECRET_NAMES = [".env", ".aio", "console.json"];
const GLOB_SECRET_RE = /\.(pem|key)$/i;

export const secretsGitChecker: RepoChecker = {
  ruleId: "appbuilder/secrets/never-commit-env-or-aio-to-git",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];
    const gitignorePath = join(ctx.projectRoot, ".gitignore");
    const gitignore = await readGitignore(ctx.projectRoot);
    for (const name of EXACT_SECRET_NAMES) {
      if (!gitignoreCovers(gitignore, name)) {
        hits.push({
          message: `${name} is not listed in .gitignore`,
          line: 1,
          path: gitignorePath,
        });
      }
      try {
        const tracked = execFileSync("git", ["ls-files", "--error-unmatch", name], {
          cwd: ctx.projectRoot,
          encoding: "utf8",
          stdio: ["ignore", "pipe", "ignore"],
        });
        if (tracked.trim()) {
          hits.push({
            message: `${name} is tracked in git — remove and rotate credentials`,
            line: 1,
            path: join(ctx.projectRoot, name),
          });
        }
      } catch {
        // not tracked — ok
      }
    }

    // *.pem / *.key: iterate the file list the runner already gathered (no
    // separate directory walk) and check each match individually since git
    // ls-files needs a concrete path, not a glob.
    const candidates = (ctx.allFiles ?? []).filter((f) => GLOB_SECRET_RE.test(f));
    for (const abs of candidates) {
      const rel = relative(ctx.projectRoot, abs).replace(/\\/g, "/");
      try {
        const tracked = execFileSync("git", ["ls-files", "--error-unmatch", rel], {
          cwd: ctx.projectRoot,
          encoding: "utf8",
          stdio: ["ignore", "pipe", "ignore"],
        });
        if (tracked.trim()) {
          hits.push({
            message: `${rel} (private key file) is tracked in git — remove and rotate credentials`,
            line: 1,
            path: abs,
          });
        }
      } catch {
        // not tracked — ok
      }
    }
    return hits;
  },
};

const SENSITIVE_PACKAGES = new Set([
  "jsonwebtoken", "jose", "bcrypt", "bcryptjs", "argon2", "crypto-js",
  "@adobe/aio-sdk", "@adobe/aio-lib-ims",
]);

function isUnpinnedVersion(ver: string): boolean {
  const v = ver.trim();
  if (!v) return false;
  if (/^[\^~]/.test(v)) return true;
  if (/[xX]/.test(v)) return true;
  if (/^\*/.test(v) || v === "latest") return true;
  if (/^[<>=]/.test(v)) return true;
  return false;
}

export const pinVersionsChecker: RepoChecker = {
  ruleId: "security/supply-chain/pin-exact-versions-for-security-sensitive-packages-no-semver-ran",
  kind: "repo",
  async check(ctx) {
    const pkgPath = join(ctx.projectRoot, "package.json");
    if (!(await fileExists(pkgPath))) return [];
    const hits: CheckHit[] = [];
    try {
      const pkg = JSON.parse(await readFile(pkgPath, "utf8")) as {
        dependencies?: Record<string, string>;
        devDependencies?: Record<string, string>;
      };
      const all = { ...pkg.dependencies, ...pkg.devDependencies };
      for (const [name, ver] of Object.entries(all ?? {})) {
        if (!ver || !SENSITIVE_PACKAGES.has(name)) continue;
        if (isUnpinnedVersion(ver)) {
          hits.push({
            message: `Security-sensitive package '${name}' uses semver range '${ver}' — pin exact version`,
            line: 1,
            path: pkgPath,
          });
        }
      }
    } catch {
      // invalid package.json
    }
    return hits;
  },
};

export const actionReadmeChecker: RepoChecker = {
  ruleId: "documentation/project/every-action-has-a-readme-purpose-inputs-outputs-examples",
  kind: "repo",
  async check(ctx) {
    const hits: CheckHit[] = [];

    // Action directories = the dirs of resolved action ENTRY files (authoritative,
    // from app.config.yaml) — never a heuristic walk, which mistakes helper subdirs
    // for actions. Only check dirs that overlap the reviewed paths, so a path-scoped
    // review doesn't flag missing READMEs for actions elsewhere in the repo.
    const reviewed = ctx.allFiles;
    const dirsToCheck = new Set<string>();
    for (const entry of ctx.actionEntryFiles ?? []) {
      const dir = dirname(entry);
      const inScope =
        reviewed.length === 0 ||
        reviewed.some((f) => f === entry || f === dir || f.startsWith(dir + "/"));
      if (inScope) dirsToCheck.add(dir);
    }

    for (const dir of dirsToCheck) {
      const rel = relative(ctx.projectRoot, dir).replace(/\\/g, "/");
      if (!rel.startsWith("actions")) continue;
      const hasReadme =
        (await fileExists(join(dir, "README.md"))) ||
        (await fileExists(join(dir, "readme.md")));
      if (!hasReadme) {
        hits.push({
          message: `Action directory '${rel}' is missing README.md`,
          line: 1,
          path: dir,
        });
      }
    }
    return hits;
  },
};
