# ACQC MCP Server

Node/TypeScript MCP server that wraps `php bin/acqc` for Cursor, Claude Desktop, and other MCP clients.

## Build

```bash
cd mcp
npm install
npm run build
```

Entry point: `dist/index.js` (stdio).

## Mode A — In-repo (this repository)

Add to `.cursor/mcp.json` (or Claude Desktop `claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "acqc": {
      "command": "node",
      "args": ["./mcp/dist/index.js"]
    }
  }
}
```

With `ACQC_BIN` unset, the server resolves `bin/acqc` from the workspace or from `projectPath`.

## Mode B — `npx` from GitHub (no npm publish)

```json
{
  "mcpServers": {
    "acqc": {
      "command": "npx",
      "args": ["-y", "github:adobe-commerce-quality/acqc#path:mcp"],
      "env": {
        "ACQC_BIN": "/absolute/path/to/bin/acqc"
      }
    }
  }
}
```

`ACQC_BIN` must point at the **`acqc` file** (not `php`). If subdirectory install fails, use **Mode C** or a local path:

```bash
npx -y /absolute/path/to/acqc/mcp
```

## Mode C — Separate repository (recommended for consumers)

The standalone repo [adobe-commerce-quality/acqc-mcp](https://github.com/adobe-commerce-quality/acqc-mcp) has `package.json` at root. Use:

```json
{
  "mcpServers": {
    "acqc": {
      "command": "npx",
      "args": ["-y", "github:adobe-commerce-quality/acqc-mcp"],
      "env": {
        "ACQC_BIN": "/absolute/path/to/bin/acqc"
      }
    }
  }
}
```

## Available Tools (14)

| Tool | Description |
|------|-------------|
| `acqc_audit` | Full project-wide quality audit |
| `acqc_analyze` | Granular file/module-level analysis |
| `acqc_scan` | Advanced scan (AI flags stripped) |
| `acqc_report` | Generate reports (json/html/excel/pdf) |
| `acqc_project_quality` | Leadership-facing project quality report |
| `acqc_config` | Full config management |
| `acqc_detect_version` | Detect Magento + PHP versions |
| `acqc_upgrade_readiness` | Upgrade compatibility analysis |
| `acqc_checklist` | Role-based quality checklists |
| `acqc_tech_spec` | Generate technical spec documents |
| `acqc_validate` | Validate analyzers and rules |
| `acqc_auto_fix` | Auto-fix upgrade issues |
| `acqc_backup` | Backup lifecycle management |
| `acqc_baseline` | Full baseline lifecycle management |

## Environment

| Variable | Purpose |
|----------|---------|
| `ACQC_BIN` | Absolute path to the `acqc` PHP entry script (optional if `bin/acqc` exists under the workspace / `projectPath`). |
| `ACQC_MCP_TIMEOUT_MS` | Override default CLI timeout (default 120000). |
