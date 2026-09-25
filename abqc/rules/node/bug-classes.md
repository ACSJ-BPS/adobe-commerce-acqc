# Node.js — Bug Classes

> Part of [Node.js](./README.md).

## No eval, new Function, or vm.runInThisContext [error] [injection, security, bugs]

Dynamic code evaluation is the highest-impact code-injection vector in Node. A single `params`-derived value reaching `eval` gives the attacker arbitrary code execution. There is essentially no use case in App Builder code that justifies it.

### Bad
```js
return { body: { result: eval(params.formula) } }
```

### Good
```js
const { evaluate } = require('mathjs')
return { body: { result: evaluate(params.formula) } }
```

---

## No child_process with shell:true or unsanitized args [error] [injection, security, bugs]

`child_process.exec` and `spawn({ shell: true })` interpret arguments through `/bin/sh`. Any unescaped user input is a command-injection vulnerability. When subprocesses are needed, use `spawn` with an arg array and never set `shell: true`.

### Check
```yaml
type: builtin
id: child-process-shell
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const { exec } = require('node:child_process')
exec(`convert ${params.filename} out.png`)
```

### Good
```js
const { spawn } = require('node:child_process')
await new Promise((resolve, reject) => {
  const p = spawn('convert', [params.filename, 'out.png'])
  p.on('exit', code => code === 0 ? resolve() : reject(new Error(`exit ${code}`)))
})
```

---

## Validate regex for ReDoS — no nested quantifiers on user input [error] [redos, perf, bugs]

Regexes with nested or overlapping quantifiers (`(a+)+`, `(.*)*`, `(a|a)+`) hit catastrophic backtracking on adversarial input. Inside a 60-second-capped action, a single ReDoS request stalls the whole worker. Pin user-input regexes with `safe-regex` or rewrite with non-backtracking patterns.

### Check
```yaml
type: builtin
id: redos-nested-quantifier
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
/^([a-zA-Z0-9]+)+@example\.com$/.test(params.email)
```

### Good
```js
/^[a-zA-Z0-9]+@example\.com$/.test(params.email)
```

---

## No sync fs APIs in the request path [error] [perf, event-loop, bugs]

`readFileSync`, `writeFileSync`, `existsSync`, and friends block the event loop. In a single-threaded worker servicing many concurrent requests, one slow disk read becomes a latency spike for every other in-flight invocation in the container.

### Check
```yaml
type: builtin
id: sync-fs-in-handler
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const fs = require('node:fs')
const config = JSON.parse(fs.readFileSync('./config.json', 'utf8'))
```

### Good
```js
const fs = require('node:fs/promises')
const config = JSON.parse(await fs.readFile('./config.json', 'utf8'))
```

---

## Use crypto.randomUUID(), not Math.random(), for IDs and tokens [error] [security, randomness, bugs]

`Math.random()` is a non-cryptographic PRNG with predictable output — anything used as a token, session ID, password reset link, or correlation ID in a security context must use `crypto.randomUUID()` or `crypto.randomBytes()`.

### Check
```yaml
type: builtin
id: math-random-ids
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const resetToken = Math.random().toString(36).slice(2)
```

### Good
```js
const { randomUUID } = require('node:crypto')
const resetToken = randomUUID()
```

---

## Buffer.alloc() not new Buffer() [error] [security, deprecated, bugs]

`new Buffer(size)` returns uninitialized memory that may contain data from prior allocations — historically a source of memory disclosure CVEs. The constructor has been deprecated since Node 6. Always use `Buffer.alloc()` (zeroed) or `Buffer.from()` (data).

### Check
```yaml
type: regex
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
pattern: "\\bnew\\s+Buffer\\s*\\("
```

### Bad
```js
const buf = new Buffer(1024)
buf.write(payload)
```

### Good
```js
const buf = Buffer.alloc(1024)
buf.write(payload)
```

---

## No CPU-bound sync work in handlers — fan out or use workers [warn] [event-loop, perf]

A 200ms JSON.parse, sync hash, or large array `.sort()` blocks every concurrent request landing in the same container. For large transforms, use `worker_threads`, fan out via Runtime triggers, or stream the work.

### Check
```yaml
type: builtin
id: cpu-bound-sync-handler
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const giant = await fetchPayload()
const sorted = giant.sort(complexCompare)
```

### Good
```js
const { Worker } = require('node:worker_threads')
const sorted = await new Promise((resolve, reject) => {
  const w = new Worker('./sort-worker.js', { workerData: giant })
  w.on('message', resolve)
  w.on('error', reject)
})
```

---

## Set explicit timeouts on all outbound HTTP — no infinite hangs [error] [runtime, reliability]

`fetch` and `http.request` default to no timeout. A slow upstream can hold an action open until the 60s web-action cap kills it, by which time you've already returned 504 to the client and burned the warm container.

### Check
```yaml
type: builtin
id: http-timeout-retry
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
const res = await fetch('https://upstream.example.com/data')
```

### Good
```js
const res = await fetch('https://upstream.example.com/data', {
  signal: AbortSignal.timeout(10_000)
})
```

---

## Validate inputs at the boundary with a schema library [warn] [validation, bugs]

`checkMissingRequestInputs` (appbuilder.md) handles presence; for shape validation use Zod, Joi, or AJV at the top of `main`. Catching a malformed payload here means the rest of the action body can rely on types and avoid a ladder of defensive checks.

### Check
```yaml
type: builtin
id: schema-validate-boundary
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
for (const line of params.order.lines) {
  await reserve(line.sku, line.qty)   // crashes on malformed input
}
```

### Good
```js
const { z } = require('zod')
const Schema = z.object({ order: z.object({ lines: z.array(z.object({ sku: z.string(), qty: z.number().int().positive() })) }) })
const parsed = Schema.safeParse(params)
if (!parsed.success) return { error: { statusCode: 400, body: parsed.error.format() } }
for (const line of parsed.data.order.lines) await reserve(line.sku, line.qty)
```
