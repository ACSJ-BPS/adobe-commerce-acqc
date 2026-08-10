# TypeScript — Style

> Part of [TypeScript](./README.md).

## Use type-only imports for types [warn] [style, build-perf]

`import type { Order } from './types'` (or `import { type Order }`) emits no JS at compile time and avoids accidentally importing runtime code along with the type. `@typescript-eslint/consistent-type-imports` enforces it.

### Bad
```ts
import { Order, processOrder } from './orders'
function format (o: Order): string { return JSON.stringify(o) }
// Order is used only as a type, but the import emits the full module
```

### Good
```ts
import type { Order } from './orders'
function format (o: Order): string { return JSON.stringify(o) }
```

---

## Don't disable typescript-eslint with @ts-ignore — use @ts-expect-error [warn] [style, hygiene]

`@ts-expect-error` becomes an error itself when the underlying issue is fixed — i.e. when the suppression is no longer needed. `@ts-ignore` silently keeps suppressing forever, including for new errors that weren't there when it was added. Always include a comment explaining why.

### Bad
```ts
// @ts-ignore
return legacyApi.frobnicate(input)
```

### Good
```ts
// @ts-expect-error legacyApi has no types; tracking in TS-1234
return legacyApi.frobnicate(input)
```

---

## No confusing void expressions [warn] [type-safety, bugs]

`@typescript-eslint/no-confusing-void-expression`. Returning a `void` expression (a function call that returns `void`) from an arrow function or assigning it to a variable looks intentional but is almost always a refactor leftover. The rule catches `() => doVoidThing()` where the surrounding code expects a value.

### Bad
```ts
const log = (m: string): void => console.log(m)
const result: void = doVoidThing()      // result is typed void, useless
button.onClick = () => save()            // save returns void; arrow returns void; ambiguous
```

### Good
```ts
function log (m: string): void {
  console.log(m)
}
button.onClick = () => { save() }        // explicit block — clearly void
```

---

## No meaningless void operator [warn] [type-safety]

`@typescript-eslint/no-meaningless-void-operator`. `void someFunction()` is meaningful when the function returns a Promise you intend to ignore. `void someVoidFunction()` is just noise — the operator does nothing.

### Bad
```ts
function syncCleanup (): void { /* ... */ }
void syncCleanup()         // void operator on already-void function
```

### Good
```ts
syncCleanup()              // just call it
void doAsyncWork()         // legit: explicitly ignoring a Promise
```

---

## No unnecessary conditions — TypeScript proved them already [warn] [type-safety, dead-code]

`@typescript-eslint/no-unnecessary-condition`. If TypeScript narrowed a value to a non-nullable type, checking `if (value)` is dead code. Either the type narrowing is wrong (fix it) or the check is wasted bytes. Type-aware lint catches this.

### Bad
```ts
function process (order: Order): string {
  if (order) {                    // Order is non-nullable; always truthy
    return order.id
  }
  return ''                        // unreachable
}
```

### Good
```ts
function process (order: Order): string {
  return order.id
}
```

---

## Prefer string.startsWith / endsWith over slice/indexOf [info] [readability, perf]

`@typescript-eslint/prefer-string-starts-ends-with`. `s.slice(0, 4) === 'http'` is verbose and creates a substring; `s.startsWith('http')` is shorter and skips the allocation.

### Bad
```ts
if (url.indexOf('http') === 0) { /* ... */ }
if (filename.slice(-4) === '.tsx') { /* ... */ }
```

### Good
```ts
if (url.startsWith('http')) { /* ... */ }
if (filename.endsWith('.tsx')) { /* ... */ }
```

---

## Prefer Array.includes over indexOf [info] [readability]

`@typescript-eslint/prefer-includes`. `.includes(x)` returns boolean directly; `.indexOf(x) !== -1` requires reading "not negative one means present" which is clunky.

### Bad
```ts
if (allowedTiers.indexOf(user.tier) !== -1) { /* ... */ }
if (~adminIds.indexOf(userId)) { /* ... */ }   // bitwise NOT trick — even worse
```

### Good
```ts
if (allowedTiers.includes(user.tier)) { /* ... */ }
if (adminIds.includes(userId)) { /* ... */ }
```

---

## Prefer Array.find over filter()[0] [info] [readability, perf]

`@typescript-eslint/prefer-find`. `.find()` short-circuits on the first match; `.filter()[0]` walks the entire array and allocates the intermediate array. Same intent, better implementation.

### Bad
```ts
const admin = users.filter(u => u.role === 'admin')[0]
const first = items.filter(i => i.active)[0]
```

### Good
```ts
const admin = users.find(u => u.role === 'admin')
const first = items.find(i => i.active)
```

---

## Strict boolean expressions — no implicit coercion in conditions [warn] [type-safety, bugs]

`@typescript-eslint/strict-boolean-expressions`. `if (someString)` is true for `"0"` and `"false"` and false for `""` — the implicit coercion bites when types change. Force explicit comparisons so the intent is unambiguous.

### Bad
```ts
function greet (name: string | undefined) {
  if (name) return `Hello ${name}`   // "" is also falsy, may not be intended
  return 'Hello stranger'
}
```

### Good
```ts
function greet (name: string | undefined) {
  if (name !== undefined && name.length > 0) return `Hello ${name}`
  return 'Hello stranger'
}
```

---

## Type-only imports use the type modifier — consistent-type-imports [warn] [build-perf, style]

`@typescript-eslint/consistent-type-imports`. Already covered in the original collection but worth restating with the **modifier** form: `import { type Foo, bar } from './x'` lets you mix types and values in one import without the runtime cost of importing the type as a value.

### Bad
```ts
import { Order, processOrder } from './orders'
function format (o: Order): string { return processOrder(o).id }
// Order is used only as a type but emits a runtime import
```

### Good
```ts
import { type Order, processOrder } from './orders'
function format (o: Order): string { return processOrder(o).id }
```
