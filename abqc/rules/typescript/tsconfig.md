# TypeScript — tsconfig

> Part of [TypeScript](./README.md).

## tsconfig: enable strict mode, no exceptions [error] [config, type-safety]

`"strict": true` in `tsconfig.json` turns on `strictNullChecks`, `noImplicitAny`, `strictFunctionTypes`, `strictBindCallApply`, `strictPropertyInitialization`, `noImplicitThis`, `alwaysStrict`. Each one catches a real class of bug. Disabling any — even temporarily — almost always becomes permanent and erodes the rest.

### Check
```yaml
type: builtin
id: tsconfig-strict
scope: repo
```

### Bad
```json
{ "compilerOptions": { "strict": false, "noImplicitAny": false } }
```

### Good
```json
{ "compilerOptions": { "strict": true, "noUncheckedIndexedAccess": true, "exactOptionalPropertyTypes": true } }
```

---

## tsconfig: enable noUncheckedIndexedAccess [warn] [config, null-safety]

By default, `arr[0]` is typed `T` even when `arr` may be empty — TS lies about runtime undefined-ness. `noUncheckedIndexedAccess` types it as `T | undefined`, forcing you to handle the empty case. One of the highest-value flags TS has shipped.

### Check
```yaml
type: builtin
id: tsconfig-unchecked-indexed-access
scope: repo
```

### Bad
```ts
const first = orders[0]   // typed as Order, but may be undefined at runtime
console.log(first.id)     // crash if orders is empty
```

### Good
```ts
const first = orders[0]   // typed as Order | undefined
if (first) console.log(first.id)
```
