# Node.js — Error Handling

> Part of [Node.js](./README.md).

## Always handle promise rejections — unhandled = container death [error] [runtime, errors, bugs]

In the Node runtime Adobe deploys, an `unhandledRejection` event terminates the process. Worse, the warm-container slot is invalidated, so the *next* invocation pays a cold-start tax. Every async call must be awaited inside try/catch or have an explicit `.catch()`.

### Check
```yaml
type: builtin
id: floating-promise
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function main (params) {
  fireAndForget(params.id)   // Promise rejection unhandled
  return { statusCode: 200 }
}
```

### Good
```js
async function main (params) {
  fireAndForget(params.id).catch(err => logger.error('background failed', err))
  return { statusCode: 200 }
}
```

---

## No unnecessary catch-and-rethrow [warn] [style, errors]

A `try { ... } catch (e) { throw e }` block adds noise without changing behavior. Either handle the error (log, transform, suppress) or remove the try/catch. Worse: `catch (e) { throw new Error(e.message) }` discards the original stack trace.

### Check
```yaml
type: builtin
id: pure-rethrow-catch
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
try {
  return await fetchData(id)
} catch (e) {
  throw e
}
```

### Good
```js
return await fetchData(id)

// or, if context matters:
try {
  return await fetchData(id)
} catch (cause) {
  throw new Error(`fetchData failed for id=${id}`, { cause })
}
```

---

## Don't process.exit() — let the runtime finish gracefully [warn] [runtime, errors]

`n/no-process-exit`. Calling `process.exit()` inside an action drops in-flight work and prevents Runtime from recording the activation correctly. Throw or return a wrapped error instead — Runtime exits the container itself.

### Bad
```js
async function main (params) {
  if (!params.required) {
    console.error('missing required param')
    process.exit(1)
  }
}
```

### Good
```js
async function main (params) {
  if (!params.required) {
    return { error: { statusCode: 400, body: { error: 'missing required param' } } }
  }
}
```

---

## Handle the err parameter in error-first callbacks [error] [errors, bugs]

`n/handle-callback-err`. Every Node error-first callback `(err, result) => ...` must check or propagate `err`. Ignoring it silently drops failures — the most common source of "the function returned but nothing happened" bugs in callback-style code.

### Check
```yaml
type: builtin
id: unused-err-param
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
fs.readFile('./config.json', 'utf8', (err, contents) => {
  const config = JSON.parse(contents)   // crashes if err was set
  applyConfig(config)
})
```

### Good
```js
fs.readFile('./config.json', 'utf8', (err, contents) => {
  if (err) return logger.error('read failed', err)
  applyConfig(JSON.parse(contents))
})

// better: skip callbacks entirely
const contents = await fs.promises.readFile('./config.json', 'utf8')
```

---

## Error-first callback functions: only invoke with an Error or null [error] [conventions, bugs]

`n/no-callback-literal`. Callbacks with `(err, value)` signature should be called with an `Error` (or null) as the first argument, never a string. String-as-error breaks `instanceof Error` checks and discards stack traces.

### Bad
```js
function load (id, callback) {
  if (!id) return callback('id required')   // string as error
  callback(null, cache[id])
}
```

### Good
```js
function load (id, callback) {
  if (!id) return callback(new Error('id required'))
  callback(null, cache[id])
}
```

---

## Use typed domain error classes with HTTP status and error code [warn] [error-handling, conventions]

Throwing raw `Error` objects forces every call site to guess the intended HTTP status and error code. Define a typed error hierarchy — at minimum `AppError` with `status` and `code` fields — and map it centrally to responses. This prevents stack traces and internal details leaking to clients (only `code` and a safe message go out), keeps status-code assignment in one place, and makes error handling in tests explicit and readable. Source: Adobe internal `microservice-base-node` / `microservice-lib-node` patterns.

### Check
```yaml
type: builtin
id: typed-error-classes
files: ["**/*.{js,cjs,mjs,ts,tsx}"]
```

### Bad
```js
async function getUser (id) {
  if (!id) throw new Error('id required')        // caller must know status = 400
  const user = await db.find(id)
  if (!user) throw new Error('not found')        // caller must know status = 404
  return user
}
```

### Good
```js
class AppError extends Error {
  constructor (message, { status = 500, code = 'INTERNAL_ERROR' } = {}) {
    super(message)
    this.name = this.constructor.name
    this.status = status
    this.code = code
  }
}
class NotFoundError extends AppError {
  constructor (msg = 'Resource not found') { super(msg, { status: 404, code: 'NOT_FOUND' }) }
}
class ValidationError extends AppError {
  constructor (msg) { super(msg, { status: 400, code: 'VALIDATION_ERROR' }) }
}

// central error → response mapping (never leaks internals)
function handleError (err, res) {
  const status = err.status ?? 500
  res.status(status).json({ error: { code: err.code ?? 'INTERNAL_ERROR',
    message: status === 500 ? 'Internal server error' : err.message } })
}
```

---

## Register `unhandledRejection` and `uncaughtException` handlers that exit the process [error] [reliability, async]

A Node process that swallows an unhandled rejection or uncaught exception can remain alive but in an undefined state — connections may be leaked, in-flight work corrupted, and errors silently lost. The correct response is to log the error and call `process.exit(1)`, relying on Kubernetes, Ethos, or I/O Runtime to restart the container. This is the industry-standard Node reliability pattern and is required by Adobe's cloud-native service standards. Do not attempt to "keep limping" — fail fast and let orchestration recover.

### Check
```yaml
type: builtin
id: process-crash-handlers
scope: repo
```

### Bad
```js
process.on('unhandledRejection', reason => {
  console.warn('unhandled rejection:', reason)  // continues running in broken state
})
```

### Good
```js
process.on('unhandledRejection', err => {
  logger.fatal({ err }, 'Unhandled promise rejection — exiting')
  process.exit(1)  // orchestration restarts the container
})

process.on('uncaughtException', err => {
  logger.fatal({ err }, 'Uncaught exception — exiting')
  process.exit(1)
})
```
