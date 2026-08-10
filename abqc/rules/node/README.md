# Node.js

46 rules covering Node.js bug classes, modern APIs, ESLint correctness, PHPMD-style complexity, naming, promises, error handling, and reliability.

## Files

| File | Rules | Coverage |
|---|---|---|
| [bug-classes.md](./bug-classes.md) | 9 | eval, child_process, ReDoS, sync fs, Buffer.alloc |
| [async.md](./async.md) | 2 | async/await correctness |
| [modern-apis.md](./modern-apis.md) | 4 | node: prefix, fs/promises |
| [correctness.md](./correctness.md) | 8 | ESLint core — eqeqeq, no-unreachable, prefer-const |
| [complexity.md](./complexity.md) | 5 | Cyclomatic, length, params, nesting |
| [naming.md](./naming.md) | 2 | Naming conventions |
| [promises.md](./promises.md) | 7 | eslint-plugin-promise |
| [errors.md](./errors.md) | 7 | Typed errors, process exit, unhandled handlers |
| [reliability.md](./reliability.md) | 2 | Concurrency caps, circuit breakers |
| **Total** | **46** | |

## Sources

- [Node.js docs — node:fs/promises](https://nodejs.org/api/fs.html#promises-api)
- [Node.js docs — node: prefix imports](https://nodejs.org/api/modules.html#core-modules)
- [Node.js docs — node:crypto.randomUUID](https://nodejs.org/api/crypto.html#cryptorandomuuidoptions)
- [Node.js docs — Buffer.alloc vs new Buffer](https://nodejs.org/api/buffer.html#class-buffer)
- [ESLint Core Rules](https://eslint.org/docs/latest/rules/)
- [eslint-plugin-security](https://www.npmjs.com/package/eslint-plugin-security)
- [eslint-plugin-n](https://www.npmjs.com/package/eslint-plugin-n)
- [safe-regex (ReDoS detection)](https://www.npmjs.com/package/safe-regex)
- [Zod schema validation](https://zod.dev/)
- [Adobe App Builder — Creating Actions (unhandled async)](https://developer.adobe.com/app-builder/docs/guides/runtime_guides/creating-actions)
