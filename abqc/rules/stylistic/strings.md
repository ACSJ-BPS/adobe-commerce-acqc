# Stylistic — Strings

> Part of [Stylistic](./README.md).

## Prefer template literals over string concatenation [warn] [style, readability]

`prefer-template`. Concatenating with `+` is brittle (a missing space, a forgotten coercion) and harder to read once more than two parts are involved. Template literals are the idiomatic ES6+ form.

### Bad
```js
const url = baseUrl + '/v1/orders/' + orderId + '?region=' + encodeURIComponent(region)
const greeting = 'Hello, ' + (user ? user.name : 'guest') + '!'
```

### Good
```js
const url = `${baseUrl}/v1/orders/${orderId}?region=${encodeURIComponent(region)}`
const greeting = `Hello, ${user?.name ?? 'guest'}!`
```

---

## No useless concatenation of string literals [info] [readability]

`no-useless-concat`. `'foo' + 'bar'` simplifies to `'foobar'` (or a template literal). Almost always a leftover from a refactor.

### Bad
```js
const url = 'https://' + 'api.example.com' + '/v1'
```

### Good
```js
const url = 'https://api.example.com/v1'
```
