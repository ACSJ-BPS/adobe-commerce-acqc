# abqc: App Builder checks for ACQC

abqc is the Node.js engine that ACQC uses to check Adobe App Builder and Node.js projects. ACQC
runs it for you in `acqc audit`, `acqc report` and `acqc appbuilder:lint`. How to use those commands
is in [docs/app-builder.md](../docs/app-builder.md). This page is for people who work on abqc itself.

## How ACQC runs it

ACQC calls the abqc CLI with Node.js:

```bash
node abqc/packages/cli/dist/bin.js review <project-folder> --format=json
```

That runs two layers. Both are deterministic:

| Layer | What it checks |
|---|---|
| Mechanical rules | The rules in [rules/](rules/) that have a `### Check` section: 351 of the 387 rules today. |
| ESLint | ESLint with the TypeScript, React, React Hooks, JSX a11y, Node (`n`), import, promise and security plugins. Each of the other 36 rules is mapped to an ESLint rule in `packages/rules-check/src/eslint-map.ts`, and ESLint findings are reported under that rule. |

## Build

Node.js 20.19 or later. From the ACQC root:

```bash
npm install
```

`npm install` builds the `cli`, `core` and `rules-check` packages into `packages/*/dist/`. After you
change TypeScript code, build again with `npm run build --workspace=abqc`.

## Run it on its own

```bash
npm run abqc -- review <path>                 # findings in the terminal
npm run abqc -- review <path> --format=json   # also sarif, github
npm run abqc -- rules list                    # all rules, with severity
npm run abqc -- rules get <rule-id>           # one rule in full
npm run abqc -- rules validate                # check every ### Check section
npm run abqc -- --help
```

Exit codes: `0` no errors or warnings, `1` warnings only, `2` errors (or a usage error), `3` engine
error.

## Tests

```bash
cd abqc
npm test
```

## Packages

| Package | Used by ACQC |
|---|---|
| `packages/cli` | Yes. The `abqc` command. |
| `packages/core` | Yes. Rule loader and review engine. |
| `packages/rules-check` | Yes. Mechanical checks. Also has its own command, `abqc-rules-check`. |
| `packages/mcp`, `packages/action`, `packages/aio-plugin` | No. Not built by `npm install` in the ACQC root and not documented. |

## AI review is not used

abqc can also send files to an AI provider for review. ACQC does not use this. abqc turns it on
only when you pass `--ai` or `--provider`, or when `ABQC_PROVIDER` is set. Keep `ABQC_PROVIDER` unset
when you run ACQC, because ACQC passes your environment to abqc. See [SECURITY.md](../SECURITY.md).

## Rules

See [rules/README.md](rules/README.md).
