# Deployment — Secrets

> Part of [Deployment](./README.md).

## Enforce secrets scanning in pre-commit hooks — block secrets before they hit git [warn] [secrets, supply-chain]

A secret committed to git is compromised the moment the commit lands — even on a private repo, even if immediately reverted. Reverts leave the secret in history; git history is accessible to anyone with clone access. A pre-commit hook running `gitleaks` or `detect-secrets` stops the commit before it touches the repo. The "no hard-coded API keys" rule in security.md covers what to write; this rule covers how to enforce it mechanically without relying on code review.

**Why it was missing:** The "no hardcoded keys" security rule was a code-quality judgment. The enforcement mechanism — a pre-commit scanner that makes the rule automatic — belongs in the deployment/pipeline layer, not the security layer, and was missing from both. OWASP SCA methodology specifically calls out pre-commit as the earliest possible intervention point in the SDLC.

### Check
```yaml
type: builtin
id: missing-precommit-secrets-scan
scope: repo
```

### Bad
```bash
# no pre-commit hook — relies entirely on developer discipline and code review
git commit -m "fix auth"
# .env accidentally staged → secret in history
```

### Good
```bash
# .pre-commit-config.yaml
repos:
  - repo: https://github.com/gitleaks/gitleaks
    rev: v8.18.4
    hooks:
      - id: gitleaks
        name: gitleaks — detect secrets in staged files
```

```bash
# install once per clone
pip install pre-commit
pre-commit install

# CI enforcement (catches contributors who skipped install)
- name: Run gitleaks
  uses: gitleaks/gitleaks-action@v2
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

```bash
# .gitleaks.toml — extend baseline with Adobe-specific patterns
[allowlist]
  paths = [".env.example"]   # example files with placeholder values

[[rules]]
  id = "adobe-ims-client-secret"
  description = "Adobe IMS client secret"
  regex = '''[Cc]lient.{0,20}[Ss]ecret.{0,10}[=:].{0,5}[a-zA-Z0-9_-]{32,}'''
  tags = ["adobe", "ims"]
```

---

## Generate OAuth tokens in CI via aio-apps-action oauth_sts — don't store long-lived static tokens [warn] [cicd, secrets]

Long-lived JWT private keys or static runtime auth tokens in CI secrets are a credential-leak risk and force manual rotation. Adobe's `aio-apps-action oauth_sts` exchanges an OAuth S2S client credential for a short-lived runtime token at deploy time — the static credential never touches a long-lived form factor.

### Check
```yaml
type: builtin
id: static-ci-token
scope: repo
```

### Bad
```yaml
env:
  AIO_RUNTIME_AUTH: ${{ secrets.RUNTIME_AUTH_LONG_LIVED_TOKEN }}
```

### Good
```yaml
- uses: adobe/aio-apps-action@v3
  with:
    os: 'linux'
    command: 'oauth_sts'
    CLIENTID:     ${{ secrets.CLIENTID }}
    CLIENTSECRET: ${{ secrets.CLIENTSECRET }}
    TECHNICALACCOUNTID:    ${{ secrets.TECHNICALACCID }}
    TECHNICALACCOUNTEMAIL: ${{ secrets.TECHNICALACCEMAIL }}
    IMSORGID: ${{ secrets.IMSORGID }}
    SCOPES:   ${{ secrets.SCOPES }}
- run: aio app deploy
```

---

## Never check in console.json, private keys, or .aio credentials files [error] [secrets, git]

The `console.json` downloaded from Dev Console contains the project's IMS credentials in plaintext. `.aio` and any `*.pem` private keys do too. Add them to `.gitignore` from day one and verify with a `git ls-files` check in CI; one accidental commit means full credential rotation.

### Check
```yaml
type: builtin
id: secrets-git
scope: repo
```

### Bad
```text
my-app/
├── console.json     # checked in
├── private.key      # checked in
└── .aio             # checked in
```

### Good
```text
# .gitignore
console.json
*.pem
*.key
.aio
.env
```
