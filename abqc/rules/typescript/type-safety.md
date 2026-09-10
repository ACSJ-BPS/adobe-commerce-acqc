# TypeScript — Type Safety

> Part of [TypeScript](./README.md).

## No explicit any [error] [type-safety]

`any` opts out of every type check. A single `any` in a chain propagates: `any.foo.bar.baz()` type-checks. `@typescript-eslint/no-explicit-any` flags it. Use `unknown` when you genuinely don't know the type and intend to narrow before use.

### Bad
```ts
function process (params: any) {
  return params.user.tier.toUpperCase()
}
```

### Good
```ts
function process (params: unknown) {
  if (typeof params === 'object' && params && 'user' in params) {
    // narrowed; further checks needed
  }
}
```

---

## No unsafe-assignment / unsafe-call / unsafe-member-access [error] [type-safety]

The `*-unsafe-*` family from `@typescript-eslint` flags places where `any`-typed values flow into typed code: assigning `any` to a typed variable, calling an `any` value, accessing properties on `any`. Together they prevent the type system from being silently bypassed by external libraries with poor types or `JSON.parse`.

### Check
```yaml
type: builtin
id: unsafe-any-boundary-approx
```

### Bad
```ts
const data = JSON.parse(raw)        // typed any
const total: number = data.total    // unsafe-assignment
data.process()                       // unsafe-call
const id = data.user.id              // unsafe-member-access
```

### Good
```ts
import { z } from 'zod'
const Schema = z.object({ total: z.number(), user: z.object({ id: z.string() }) })
const data = Schema.parse(JSON.parse(raw))
const total: number = data.total       // type-safe
const id = data.user.id                  // type-safe
```

---

## No unsafe-return [error] [type-safety]

A function declared to return `Order` that returns `any` silently breaks every caller's assumptions. `@typescript-eslint/no-unsafe-return` catches this — usually it's a wrapper around an untyped library call that needs a parser/validator.

### Check
```yaml
type: builtin
id: unsafe-any-boundary-approx
```

### Bad
```ts
async function getOrder (id: string): Promise<Order> {
  const res = await fetch(`/api/orders/${id}`)
  return res.json()   // returns any — caller thinks it's Order
}
```

### Good
```ts
async function getOrder (id: string): Promise<Order> {
  const res = await fetch(`/api/orders/${id}`)
  return OrderSchema.parse(await res.json())
}
```

---

## No unsafe-argument [error] [type-safety]

Passing an `any`-typed value to a typed parameter erases the parameter's type guarantees inside the callee. Same root cause as the others; same fix (validate at the boundary).

### Check
```yaml
type: builtin
id: unsafe-any-boundary-approx
```

### Bad
```ts
function chargeCard (order: Order) { /* assumes order is well-formed */ }
const raw = JSON.parse(payload)
chargeCard(raw)   // unsafe-argument
```

### Good
```ts
const order = OrderSchema.parse(JSON.parse(payload))
chargeCard(order)
```

---

## Avoid non-null assertion (!) [warn] [null-safety]

`value!` tells TS "trust me, this is not null" — bypassing a real check. Every `!` is a place where a null at runtime turns into a crash. Refactor to a real null check, an early return, or a default value.

### Bad
```ts
const user = users.find(u => u.id === id)!
return user.name
```

### Good
```ts
const user = users.find(u => u.id === id)
if (!user) return { error: 'user not found' }
return user.name
```

---

## No non-null assertion on optional chain [error] [null-safety]

`obj?.foo!` is contradictory: optional chaining says "this might be null"; the `!` says "no it isn't." `@typescript-eslint/no-non-null-asserted-optional-chain` flags it as the logic error it is.

### Check
```yaml
type: builtin
id: non-null-on-optional-chain
files: ["**/*.{ts,tsx}"]
```

### Bad
```ts
const name = user?.profile!.name
```

### Good
```ts
const name = user?.profile?.name ?? 'unknown'
```

---

## Prefer nullish coalescing over logical OR [info] [null-safety, style]

`x || default` returns the default for falsy values — `0`, `''`, `false`, `NaN` — usually not what you want. `x ?? default` returns the default only for `null`/`undefined`. Use `??` when defaulting is for missing values, not for falsy ones.

### Check
```yaml
type: builtin
id: logical-or-default-approx
files: ["**/*.{ts,tsx}"]
```

### Bad
```ts
const limit = params.limit || 100   // limit=0 silently becomes 100
const name = user.name || 'anon'    // name='' silently becomes 'anon'
```

### Good
```ts
const limit = params.limit ?? 100
const name = user.name ?? 'anon'
```

---

## Prefer optional chaining over manual null guards [info] [style]

`obj?.foo?.bar` is shorter, harder to mistype, and TS-narrows correctly. `obj && obj.foo && obj.foo.bar` is verbose and mistyping a property fails silently.

### Check
```yaml
type: builtin
id: manual-null-guard-chain
files: ["**/*.{ts,tsx}"]
```

### Bad
```ts
const street = user && user.address && user.address.street
```

### Good
```ts
const street = user?.address?.street
```

---

## No unnecessary type assertions [warn] [style, type-safety]

`(value as string).toUpperCase()` when `value` is already typed as `string` adds noise without value. `@typescript-eslint/no-unnecessary-type-assertion` catches it. Often signals a stale assertion left behind from a refactor.

### Check
```yaml
type: builtin
id: unnecessary-type-assertion-approx
```

### Bad
```ts
function greet (name: string) {
  return (name as string).toUpperCase()
}
```

### Good
```ts
function greet (name: string) {
  return name.toUpperCase()
}
```

---

## Prefer Record<string, T> or Map over index signatures for unknown keys [info] [style, type-safety]

`{ [key: string]: T }` is fine for fixed-shape lookup tables, but `Map<string, T>` gives you size, iteration order, and proper key types for dynamic dictionaries. `Record<K, V>` is the readable form for fixed-shape.

### Check
```yaml
type: builtin
id: index-signature-dict
files: ["**/*.{ts,tsx}"]
```

### Bad
```ts
const cache: { [k: string]: Order } = {}
cache[orderId] = order
```

### Good
```ts
const cache = new Map<string, Order>()
cache.set(orderId, order)
```

---

## No redundant or duplicate union/intersection members [warn] [type-safety, dead-code]

`@typescript-eslint/no-redundant-type-constituents` + `no-duplicate-type-constituents`. `string | string` is just `string`; `Order & { id: string }` when `Order` already has `id: string` is redundant. Almost always a leftover from refactoring a type.

### Check
```yaml
type: builtin
id: duplicate-union-member
files: ["**/*.{ts,tsx}"]
```

### Bad
```ts
type ID = string | string                    // duplicate
type Status = 'active' | 'active' | 'gone'   // duplicate
type AnyOrAll = any | Order                  // any swallows the rest
```

### Good
```ts
type ID = string
type Status = 'active' | 'gone'
type Result = Order   // if any was the intent, that's a separate problem
```

---

## Don't pass non-strings to String() concatenation without explicit conversion [error] [type-safety, bugs]

`@typescript-eslint/restrict-template-expressions`. Template literal interpolation calls `.toString()` on whatever you embed — for objects, that produces `[object Object]`. Force explicit conversion at the call site so the bug is visible.

### Check
```yaml
type: builtin
id: non-string-template-interp
```

### Bad
```ts
const order: { id: number; total: number } = { id: 42, total: 99 }
const msg = `Processing order ${order}`   // "Processing order [object Object]"
```

### Good
```ts
const msg = `Processing order ${order.id} ($${order.total})`
// or, when serializing whole objects:
const msg = `Processing order ${JSON.stringify(order)}`
```
