# API Mesh — Authentication

> Part of [API Mesh](./README.md).

## Use operationHeaders / schemaHeaders for upstream credentials, sourced from env [error] [auth, secrets]
Static API keys and bearer tokens for upstream sources belong in `operationHeaders` (sent per request) or `schemaHeaders` (sent at schema introspection). Both must come from env placeholders, not literals. Hard-coding a token also prevents per-environment rotation.

### Bad
```json
{ "operationHeaders": { "Authorization": "Bearer eyJhbGc...real-token" } }
```

### Good
```json
{ "operationHeaders": { "Authorization": "Bearer {{env.UPSTREAM_TOKEN}}" } }
```

## Cache short-lived OAuth/IMS tokens in a Runtime action — don't refresh per-request [warn] [performance, auth]
Mesh hooks fire on every incoming GraphQL query. Calling IMS to mint a fresh token on each request adds 100–300ms latency and can hit IMS rate limits. Cache tokens in a Runtime action (using State SDK with TTL slightly less than token lifetime), have the mesh hook call the action.

### Bad
```js
// hook
const token = await ims.getAccessToken(clientId, clientSecret); // every request
```

### Good
```js
// hook calls cached-token Runtime action
const { token } = await fetch('https://<ns>.adobeioruntime.net/api/v1/web/get-ims-token').then(r => r.json());
// action stores token in State with TTL = 0.8 * token_expiry
```

## Never expose IMS tokens to the browser — mesh or Runtime must hold them [error] [auth, security]
IMS access tokens grant access to Adobe APIs at the org level. If the mesh forwards them in responses (or front-end code holds them), a browser XSS becomes an Adobe-org compromise. Mesh-to-upstream auth is server-side only; browser sees a session cookie or short-lived app token instead.

### Bad
```js
// action response
return { statusCode: 200, body: { imsAccessToken: token, user } };
```

### Good
```js
// SPA uses PKCE for user auth; BFF cookie for session
// mesh and Runtime exchange that for IMS tokens server-side
return { statusCode: 200, body: { user } };
```
