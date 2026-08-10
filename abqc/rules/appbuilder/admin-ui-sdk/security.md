# Admin UI SDK — Security

> Part of [Admin UI SDK](./README.md).

## Set require-adobe-auth: true on every Admin UI SDK runtime action [error] [auth, security]
Admin UI SDK requires the IMS module to be configured. Every action — registration and any business action invoked from the SPA — must set `require-adobe-auth: true` so the runtime validates the IMS token before invoking your handler. Without this, the action is reachable anonymously over the public runtime URL.

### Bad
```yaml
actions:
  registration:
    function: actions/registration/index.js
    web: 'yes'
    # missing: annotations.require-adobe-auth
```

### Good
```yaml
actions:
  registration:
    function: actions/registration/index.js
    web: 'yes'
    annotations:
      require-adobe-auth: true
```

## Never log IMS tokens, Authorization headers, or OAuth secrets [error] [security, logging]
The most frequent must-fix in Admin UI SDK reviews is `console.log` of tokens or `Authorization` headers. Even logged once to stdout, those tokens land in retained activation logs and any forwarded SIEM. Strip them before any log call.

### Bad
```js
console.log('token', params.__ow_headers.authorization);
console.log('IMS access:', imsClient.token);
```

### Good
```js
const { authorization, ...safeHeaders } = params.__ow_headers;
console.log('headers', safeHeaders);
console.log('imsClientReady', !!imsClient.token);
```

## Never use dangerouslySetInnerHTML in Admin UI SDK SPAs [error] [security, xss]
Admin UI SDK apps run inside Commerce admin's iframe, often with broad sandbox capabilities. `dangerouslySetInnerHTML` (or any unescaped HTML insertion) is an automatic Commerce marketplace review failure. Use Spectrum components or sanitize via DOMPurify if absolutely required.

### Bad
```jsx
<div dangerouslySetInnerHTML={{ __html: order.customerNote }} />
```

### Good
```jsx
<Text>{order.customerNote}</Text>
// or, only if HTML is genuinely needed:
<div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(order.customerNote) }} />
```

## Store secrets in App Management secret fields or runtime secrets — never in Commerce core_config_data [error] [secrets, security]
Storing API keys, OAuth client secrets, or webhook signing keys in Commerce's `core_config_data` table exposes them via Commerce admin's config UI and admin REST API. App Management secret-typed fields mask them in the UI and store them in runtime secret storage instead.

### Bad
```php
// stored in core_config_data, visible in Stores -> Config -> Adobe Services
$config->setValue('myapp/api_key', $apiKey);
```

### Good
```yaml
# App Management config schema declares it as a secret
fields:
  - id: api_key
    type: secret    # masked in UI, stored in runtime secret storage
    label: API key
```
