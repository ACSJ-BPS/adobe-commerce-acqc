# App Builder — Secrets

> Part of [App Builder](./README.md).

## Never edit auto-generated AIO_ secrets in .env [error] [secrets, config]

The CLI generates `AIO_*` entries in `.env` (workspace credentials, runtime auth, IMS client secrets). Manual edits silently corrupt the workspace state. Re-pull with `aio app use` instead of editing.

### Check
```yaml
type: builtin
id: env-partial-edit-history
scope: repo
```

### Bad
```bash
# someone edits .env to "fix" a value
AIO_runtime_auth=manually-changed-value
```

### Good
```bash
aio app use   # CLI rewrites the AIO_ block from Developer Console
```

---

## Never commit .env or .aio to git [error] [secrets, config]

Both files contain workspace credentials and tokens. Adobe's docs require they "should not be committed to version control." A leaked `.env` in git is a credential incident — rotate immediately and audit access.

### Check
```yaml
type: builtin
id: secrets-git
scope: repo
```

### Bad
```bash
git add .
# .env committed alongside the rest
```

### Good
```bash
# .gitignore
.env
.env.*
.aio
```
