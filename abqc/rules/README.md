# abqc rule library

387 rules for Adobe App Builder and Node.js projects, written in Markdown. 351 rules have a
`### Check` section that the engine runs. The other 36 are mapped to ESLint rules. See
[../README.md](../README.md) for how the engine runs them.

The counts on this page come from `npm run abqc -- rules list` and `npm run abqc -- rules validate`,
run from the ACQC root. Run them again after you add or remove rules.

## Domains

| Folder | Rules | Covers |
|---|---|---|
| [appbuilder/](appbuilder/) | 110 | App Builder and I/O Runtime: actions, limits, IMS and OAuth server-to-server auth, I/O Events, manifest, deployment. Includes [api-mesh/](appbuilder/api-mesh/) and [admin-ui-sdk/](appbuilder/admin-ui-sdk/). |
| [node/](node/) | 46 | Node.js: bug classes, async code, promises, errors, complexity, naming |
| [react/](react/) | 37 | React and React Spectrum |
| [security/](security/) | 36 | Adobe security practices, OWASP, supply chain |
| [typescript/](typescript/) | 36 | `tsconfig` strict mode, type safety, async correctness |
| [stylistic/](stylistic/) | 25 | Strings, numbers, control flow, functions, operators |
| [a11y/](a11y/) | 18 | WAI-ARIA and accessible markup |
| [deployment/](deployment/) | 17 | Pipelines, environments, releases, rollback, secrets |
| [testing/](testing/) | 17 | Jest, React Testing Library, mocking, coverage |
| [runtime/](runtime/) | 15 | Core Web Vitals, CSP, loading |
| [imports/](imports/) | 12 | Import cycles, dependencies, ordering, exports |
| [process/](process/) | 12 | Governance, operations, compliance |
| [documentation/](documentation/) | 6 | JSDoc, README, changelog |

By severity: 154 `error`, 165 `warn`, 68 `info`.

[eslint-config.md](eslint-config.md) describes the ESLint layer that runs next to these rules.

## Rule format

Each domain folder has a `README.md` and one Markdown file per topic. A rule is one `##` section:

````markdown
## .then() callbacks must always return a value [error] [promise, bugs]

One paragraph that explains why the rule matters.

### Check
```yaml
type: builtin
id: then-must-return
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
// code that breaks the rule
```

### Good
```js
// code that follows the rule
```
````

The severity in the title is `error`, `warn` or `info`. The `### Check` block decides how the rule is
checked:

| `type` | Rules | How it works |
|---|---|---|
| `builtin` | 331 | Runs a checker written in TypeScript. The `id` must exist in `packages/rules-check/src/checkers/type-checkers/builtin.ts`. |
| `regex` | 10 | Reports lines that match `pattern` in the files that match `files`. |
| `yaml-path` | 10 | Checks YAML files, for example `app.config.yaml`, with a `condition` that is coded in `packages/rules-check/src/checkers/type-checkers/yaml-path.ts`. |

A rule without `### Check` is reported only through the ESLint rule mapped to it in
`packages/rules-check/src/eslint-map.ts`.

## Add a rule

1. Add the rule to the matching `<domain>/<topic>.md` file in the format above.
2. Validate all rules:

   ```bash
   npm run abqc -- rules validate
   ```

3. Run it against a project that should and should not match:

   ```bash
   npm run abqc -- review <path>
   ```

4. Run the tests: `cd abqc && npm test`.
5. Update the counts on this page.
