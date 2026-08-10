# TypeScript — Modern Features

> Part of [TypeScript](./README.md).

## Prefer interface over type for object shapes [info] [style, performance]

`interface User { ... }` and `type User = { ... }` are nearly equivalent, but interfaces are slightly faster to type-check (TS caches them differently) and support declaration merging. Use `type` for unions, intersections, mapped types — anything an interface can't express.

### Bad
```ts
type User = { id: string; name: string; email: string }
type Admin = { id: string; name: string; email: string; role: 'admin' }
```

### Good
```ts
interface User { id: string; name: string; email: string }
interface Admin extends User { role: 'admin' }
type Status = 'active' | 'suspended' | 'deleted'   // type appropriate here
```

---

## Mark class fields readonly when not reassigned [warn] [style, immutability]

`@typescript-eslint/prefer-readonly`. Fields that are only assigned in the constructor and never reassigned should be `readonly` — turns "could mutate" into a compile error. Documents intent and prevents accidental mutation in subclasses.

### Bad
```ts
class OrderProcessor {
  private logger: Logger
  constructor (logger: Logger) { this.logger = logger }
}
```

### Good
```ts
class OrderProcessor {
  private readonly logger: Logger
  constructor (logger: Logger) { this.logger = logger }
}
```

---

## Naming: PascalCase types, camelCase values, UPPER for enum constants [warn] [style, naming]

`@typescript-eslint/naming-convention` enforces consistency. A type/interface named `order_t` or a const named `MaxRetries` (when not module-level immutable) breaks reader expectations.

### Bad
```ts
interface order_t { id: string }
const MaxRetries = 3
enum status { Active, Suspended }
```

### Good
```ts
interface Order { id: string }
const maxRetries = 3
enum Status { ACTIVE, SUSPENDED }
```

---

## Avoid enum — prefer const-asserted union [info] [style, build-output]

TS `enum`s emit runtime objects (extra bytes, runtime-vs-compile-time confusion). A `const` object with `as const` plus a derived union type gives the same ergonomics with zero runtime cost.

### Bad
```ts
enum OrderStatus { ACTIVE = 'active', CANCELLED = 'cancelled', SHIPPED = 'shipped' }
```

### Good
```ts
const OrderStatus = { ACTIVE: 'active', CANCELLED: 'cancelled', SHIPPED: 'shipped' } as const
type OrderStatus = typeof OrderStatus[keyof typeof OrderStatus]
```

---

## Prefer satisfies over type annotation for literal config [info] [style, type-safety]

`as const satisfies T` lets TS infer the narrowest possible type while checking it conforms to `T`. A plain `: T` annotation widens the inferred type to `T`, losing the literal-narrowness. Especially useful for configuration objects.

### Bad
```ts
const limits: Record<string, number> = { timeout: 30000, retries: 3 }
limits.timeout   // typed as number, lost the literal 30000
```

### Good
```ts
const limits = { timeout: 30000, retries: 3 } as const satisfies Record<string, number>
limits.timeout   // typed as 30000
```

---

## Use unified function signatures — avoid overload duplication [info] [type-safety]

`@typescript-eslint/unified-signatures`. Two function overloads that differ only by an optional parameter can be unified into one signature with the parameter optional. Reduces the maintenance surface.

### Bad
```ts
function send (message: string): void
function send (message: string, channel: string): void
function send (message: string, channel?: string): void {
  // ...
}
```

### Good
```ts
function send (message: string, channel?: string): void {
  // ...
}
```

---

## Switch statements must be exhaustive over discriminated unions [error] [type-safety, bugs]

`@typescript-eslint/switch-exhaustiveness-check`. When switching on a union type, the type checker can prove you handled every case — if a new variant is added later, the check fails until you handle it. Without the rule, new variants silently fall through.

### Bad
```ts
type Shape = { kind: 'circle'; r: number } | { kind: 'square'; s: number } | { kind: 'rect'; w: number; h: number }

function area (s: Shape): number {
  switch (s.kind) {
    case 'circle': return Math.PI * s.r ** 2
    case 'square': return s.s ** 2
    // 'rect' silently returns undefined — typed `number` lies
  }
}
```

### Good
```ts
function area (s: Shape): number {
  switch (s.kind) {
    case 'circle': return Math.PI * s.r ** 2
    case 'square': return s.s ** 2
    case 'rect': return s.w * s.h
    default: {
      const _exhaustive: never = s
      throw new Error(`unhandled shape: ${JSON.stringify(_exhaustive)}`)
    }
  }
}
```
