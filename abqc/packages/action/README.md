# @abqc/action

GitHub Action wrapper for `appbuilder-quality-check` — **v0.2 placeholder**.

When implemented, this package will:

- Take inputs: `severity`, `llm-api-key`, `sarif-upload`, `baseline-path`.
- Compute `git diff --name-only $BASE_SHA $HEAD_SHA` and pass the file list to the CLI with `--diff`.
- Parse the resulting SARIF and post inline review comments via `@actions/github`.
- Upload SARIF to GitHub Code Scanning via `github/codeql-action/upload-sarif@v3`.

For v0.1, use the CLI directly inside any GitHub workflow:

```yaml
- run: node packages/cli/dist/bin.js review src --format=sarif > findings.sarif
```
