# ESLint Configuration — MCP Server Plugin Coverage

This file is **not a rule list**. It documents the ESLint plugins, configs, and rules that the MCP server's `default-rules.ts` (architecture PDF section 8) should enable on every `review_code` and `review_directory` call. The static-analysis layer described in PDF section 3 runs these in-process via `Linter.verify()`; the markdown rules in this directory cover what static analysis cannot.

Total mechanical rules enabled here: **~800+** across 9 plugins. Combined with the **~250 markdown judgment rules** in this directory, the MCP server delivers ~1,050+ effective review rules per call.

## Recommended ESLint flat config

```js
// eslint.config.js — used by the MCP server's Linter.verify() (PDF section 3, step 2)
import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import react from 'eslint-plugin-react'
import reactHooks from 'eslint-plugin-react-hooks'
import jsxA11y from 'eslint-plugin-jsx-a11y'
import security from 'eslint-plugin-security'
import n from 'eslint-plugin-n'
import importPlugin from 'eslint-plugin-import'
import promise from 'eslint-plugin-promise'

export default [
  js.configs.recommended,                        // ~70 rules
  ...tseslint.configs.strictTypeChecked,         // ~125 rules (requires parserOptions.project)
  ...tseslint.configs.stylisticTypeChecked,      // ~30 rules
  react.configs.flat.recommended,                // ~25 rules
  reactHooks.configs['recommended-latest'],      // ~5 rules
  jsxA11y.flatConfigs.recommended,               // ~36 rules
  security.configs.recommended,                  // 14 rules
  n.configs['flat/recommended'],                 // ~50 rules
  importPlugin.flatConfigs.recommended,          // ~20 rules
  promise.configs['flat/recommended'],           // ~14 rules

  {
    languageOptions: {
      ecmaVersion: 2024,
      sourceType: 'module',
      parserOptions: { project: './tsconfig.json' }
    },
    rules: {
      // Project-specific overrides — promote/demote individual rules here
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/no-misused-promises': 'error',
      'security/detect-object-injection': 'error',
      'react/jsx-no-target-blank': ['error', { allowReferrer: false }],
      'react/jsx-no-leaked-render': 'warn',
      'react/no-unstable-nested-components': 'error',

      // Off — handled by the markdown layer with judgment
      '@typescript-eslint/no-unused-vars': 'off',     // covered by node.md #21 with team conventions
      'react/prop-types': 'off'                        // we use TypeScript
    }
  }
]
```

## Plugin coverage map

### `@eslint/js` (recommended) — ~70 rules

Enables `eslint:recommended`. Catches: `no-undef`, `no-unused-vars`, `no-unreachable`, `no-dupe-keys`, `no-const-assign`, `use-isnan`, `valid-typeof`, `no-async-promise-executor`, `array-callback-return`, `constructor-super`, `for-direction`, `no-class-assign`, `no-compare-neg-zero`, `no-cond-assign`, `no-control-regex`, `no-debugger`, `no-delete-var`, `no-dupe-args`, `no-dupe-class-members`, `no-dupe-else-if`, `no-duplicate-case`, `no-empty-character-class`, `no-empty-pattern`, `no-ex-assign`, `no-extra-boolean-cast`, `no-fallthrough`, `no-func-assign`, `no-global-assign`, `no-import-assign`, `no-invalid-regexp`, `no-irregular-whitespace`, `no-loss-of-precision`, `no-misleading-character-class`, `no-new-symbol`, `no-nonoctal-decimal-escape`, `no-obj-calls`, `no-octal`, `no-prototype-builtins`, `no-redeclare`, `no-regex-spaces`, `no-self-assign`, `no-setter-return`, `no-shadow-restricted-names`, `no-sparse-arrays`, `no-this-before-super`, `no-unexpected-multiline`, `no-unsafe-finally`, `no-unsafe-negation`, `no-unsafe-optional-chaining`, `no-unused-labels`, `no-useless-backreference`, `no-useless-catch`, `no-useless-escape`, `no-with`, `require-yield`.

**These rules are baseline correctness — every project gets them.**

### `typescript-eslint` strict-type-checked + stylistic-type-checked — ~155 rules

Provides full type-aware checking. Highlights: `no-floating-promises`, `no-misused-promises`, `await-thenable`, `no-unsafe-assignment`, `no-unsafe-call`, `no-unsafe-member-access`, `no-unsafe-return`, `no-unsafe-argument`, `no-explicit-any`, `no-non-null-assertion`, `no-non-null-asserted-optional-chain`, `prefer-nullish-coalescing`, `prefer-optional-chain`, `consistent-type-imports`, `no-unnecessary-condition`, `no-unnecessary-type-assertion`, `restrict-template-expressions`, `restrict-plus-operands`, `strict-boolean-expressions`, `switch-exhaustiveness-check`, `prefer-readonly`, `prefer-string-starts-ends-with`, `prefer-includes`, `prefer-find`, `prefer-reduce-type-parameter`, `consistent-type-definitions`, `consistent-type-exports`, `no-confusing-void-expression`, `no-meaningless-void-operator`, `no-redundant-type-constituents`, `no-duplicate-type-constituents`, `unified-signatures`, `prefer-as-const`, `naming-convention`.

**Requires `parserOptions.project` pointing at `tsconfig.json` for type-aware rules. Cost: ~2× lint time. Worth it.**

### `eslint-plugin-react` (recommended) — ~25 rules

`display-name`, `jsx-key`, `jsx-no-comment-textnodes`, `jsx-no-duplicate-props`, `jsx-no-target-blank`, `jsx-no-undef`, `jsx-uses-react`, `jsx-uses-vars`, `no-children-prop`, `no-danger-with-children`, `no-deprecated`, `no-direct-mutation-state`, `no-find-dom-node`, `no-is-mounted`, `no-render-return-value`, `no-string-refs`, `no-unescaped-entities`, `no-unknown-property`, `no-unsafe`, `prop-types`, `react-in-jsx-scope`, `require-render-return`.

**Disable `prop-types` and `react-in-jsx-scope` for TypeScript + React 17+ projects.**

### `eslint-plugin-react-hooks` (recommended) — 5 rules

`rules-of-hooks`, `exhaustive-deps`. Plus React 19 additions: `use-memo`, `use-context`, `use-state`. Critical — never disable.

### `eslint-plugin-jsx-a11y` (recommended) — ~36 rules

Full WCAG-grade a11y coverage: `alt-text`, `anchor-ambiguous-text`, `anchor-has-content`, `anchor-is-valid`, `aria-activedescendant-has-tabindex`, `aria-props`, `aria-proptypes`, `aria-role`, `aria-unsupported-elements`, `autocomplete-valid`, `click-events-have-key-events`, `control-has-associated-label`, `heading-has-content`, `html-has-lang`, `iframe-has-title`, `img-redundant-alt`, `interactive-supports-focus`, `label-has-associated-control`, `lang`, `media-has-caption`, `mouse-events-have-key-events`, `no-access-key`, `no-aria-hidden-on-focusable`, `no-autofocus`, `no-distracting-elements`, `no-interactive-element-to-noninteractive-role`, `no-noninteractive-element-interactions`, `no-noninteractive-element-to-interactive-role`, `no-noninteractive-tabindex`, `no-redundant-roles`, `no-static-element-interactions`, `prefer-tag-over-role`, `role-has-required-aria-props`, `role-supports-aria-props`, `scope`, `tabindex-no-positive`.

**Markdown coverage in `a11y.md` highlights the 15 highest-signal rules with reasoning; ESLint enforces all 36 mechanically.**

### `eslint-plugin-security` (recommended) — 14 rules

`detect-bidi-characters`, `detect-buffer-noassert`, `detect-child-process`, `detect-disable-mustache-escape`, `detect-eval-with-expression`, `detect-new-buffer`, `detect-no-csrf-before-method-override`, `detect-non-literal-fs-filename`, `detect-non-literal-regexp`, `detect-non-literal-require`, `detect-object-injection`, `detect-possible-timing-attacks`, `detect-pseudoRandomBytes`, `detect-unsafe-regex`.

**All 14 covered with markdown reasoning in `security.md`.**

### `eslint-plugin-n` (recommended) — ~50 rules

`callback-return`, `exports-style`, `file-extension-in-import`, `global-require`, `handle-callback-err`, `hashbang`, `no-callback-literal`, `no-deprecated-api`, `no-exports-assign`, `no-extraneous-import`, `no-extraneous-require`, `no-hide-core-modules`, `no-missing-import`, `no-missing-require`, `no-mixed-requires`, `no-new-require`, `no-path-concat`, `no-process-env`, `no-process-exit`, `no-restricted-import`, `no-restricted-require`, `no-sync`, `no-unpublished-bin`, `no-unpublished-import`, `no-unpublished-require`, `no-unsupported-features/es-builtins`, `no-unsupported-features/es-syntax`, `no-unsupported-features/node-builtins`, `prefer-global/buffer`, `prefer-global/console`, `prefer-global/process`, `prefer-global/text-decoder`, `prefer-global/text-encoder`, `prefer-global/url`, `prefer-global/url-search-params`, `prefer-node-protocol`, `prefer-promises/dns`, `prefer-promises/fs`, `process-exit-as-throw`, `shebang`.

### `eslint-plugin-import` (recommended) — ~20 rules

`default`, `export`, `named`, `namespace`, `no-amd`, `no-cycle`, `no-deprecated`, `no-duplicates`, `no-empty-named-blocks`, `no-extraneous-dependencies`, `no-named-as-default`, `no-named-as-default-member`, `no-self-import`, `no-unresolved`, `no-useless-path-segments`. Plus opt-ins worth enabling: `order`, `newline-after-import`, `first`, `no-default-export` (project preference), `no-relative-parent-imports`.

### `eslint-plugin-promise` (recommended) — ~14 rules

`always-return`, `avoid-new`, `catch-or-return`, `no-callback-in-promise`, `no-multiple-resolved`, `no-nesting`, `no-new-statics`, `no-promise-in-callback`, `no-return-in-finally`, `no-return-wrap`, `param-names`, `prefer-await-to-callbacks`, `prefer-await-to-then`, `valid-params`.

### Optional: `eslint-plugin-sonarjs` — ~60 rules

Deep code-quality analysis: `cognitive-complexity`, `no-identical-functions`, `no-duplicate-string`, `no-collapsible-if`, `no-redundant-jump`, `no-small-switch`, `no-useless-catch`, `prefer-immediate-return`, `prefer-single-boolean-return`, etc. Off by default; enable per-project if your team values strict cognitive-complexity scoring.

### Optional: `eslint-plugin-unicorn` — ~120 rules

Modern JS idioms: `prefer-string-replace-all`, `prefer-array-flat-map`, `prefer-set-has`, `no-array-callback-reference`, `prefer-top-level-await`, etc. Highly opinionated; some rules conflict with team style. Cherry-pick rather than enabling the recommended preset.

## How the MCP server consumes this

Per architecture PDF section 3, step 2: ESLint runs **in-process** via `Linter.verify(code, flatConfig)`. The `flatConfig` is constructed from this file's recommended config at server startup. Adding a plugin is a 2-step operation:

1. `npm install --save-dev <plugin>` in the MCP server's package.json.
2. Edit `eslint.config.js` to import + enable the plugin.

No server rebuild needed for *rule* changes — those live in the markdown layer. Plugin changes do require restart.

## Severity strategy

Three layers of severity for any given finding:
1. **Plugin default** — what `recommended` config sets the rule to.
2. **Project override** — `.eslintrc` / `eslint.config.js` rules block.
3. **Profile override** — the MCP server's profile (standard / strict / relaxed / security-only / performance-only) takes the final say per PDF section 8.

Rules in the markdown layer (this directory) override whatever ESLint says when the same rule appears in both — markdown wins because the LLM applies it with judgment. Per PDF section 3, step 5, the normalizer dedups by rule ID.

## Sources

- [ESLint flat config](https://eslint.org/docs/latest/use/configure/configuration-files)
- [typescript-eslint configs](https://typescript-eslint.io/users/configs/)
- [eslint-plugin-react](https://github.com/jsx-eslint/eslint-plugin-react)
- [eslint-plugin-react-hooks](https://www.npmjs.com/package/eslint-plugin-react-hooks)
- [eslint-plugin-jsx-a11y](https://github.com/jsx-eslint/eslint-plugin-jsx-a11y)
- [eslint-plugin-security](https://github.com/eslint-community/eslint-plugin-security)
- [eslint-plugin-n](https://github.com/eslint-community/eslint-plugin-n)
- [eslint-plugin-import](https://github.com/import-js/eslint-plugin-import)
- [eslint-plugin-promise](https://github.com/eslint-community/eslint-plugin-promise)
- [eslint-plugin-sonarjs](https://github.com/SonarSource/eslint-plugin-sonarjs)
- [eslint-plugin-unicorn](https://github.com/sindresorhus/eslint-plugin-unicorn)
