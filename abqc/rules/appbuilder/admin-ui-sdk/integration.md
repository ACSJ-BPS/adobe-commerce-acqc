# Admin UI SDK — Integration & Communication

> Part of [Admin UI SDK](./README.md).

## Use @adobe/uix-guest register/attach — never hand-roll postMessage between iframe and admin shell [error] [integration, contracts]
The Admin UI SDK provides `@adobe/uix-guest` for all iframe-to-shell communication. Hand-rolling `window.postMessage` calls couples your app to undocumented internals, breaks across Commerce versions, and bypasses the SDK's origin-validation logic. Always use `register()` and `attach()`.

### Bad
```js
window.parent.postMessage({ type: 'getSelectedIds' }, '*');
window.addEventListener('message', (e) => { /* ... */ });
```

### Good
```js
import { attach } from '@adobe/uix-guest';
const ctx = await attach({ id: EXTENSION_ID });
const selected = await ctx.get('selectedIds');
```

## Set explicit sandbox attributes on extension points that need browser capabilities [warn] [iframe, sandbox]
The Admin UI SDK iframe is sandboxed by default. Operations like `window.print()`, opening popups, or showing modal dialogs require opt-in via the `sandbox` property on the extension point declaration. Without it, those APIs throw "document is sandboxed" errors with no obvious cause.

### Bad
```js
menuItems: [{ id: '...::dashboard', title: 'Dashboard' }]
// later: window.print() throws "document is sandboxed"
```

### Good
```js
menuItems: [{
  id: '...::dashboard',
  title: 'Dashboard',
  sandbox: 'allow-popups allow-modals allow-downloads'
}]
```

## Call Commerce REST/GraphQL from backend runtime actions — not directly from the SPA [error] [auth, security]
Direct browser calls to Commerce admin REST/GraphQL leak admin credentials to the browser, run into CORS restrictions, and bypass Adobe's IMS-based auth flow. The pattern is: SPA → IMS-authenticated runtime action → Commerce. The action holds the Commerce token (obtained from IMS); the browser never sees it.

### Bad
```jsx
// SPA hits Commerce admin GraphQL with admin token
const r = await fetch('https://store.example.com/graphql', {
  headers: { Authorization: `Bearer ${adminToken}` }
});
```

### Good
```jsx
// SPA calls runtime action; action calls Commerce
const r = await fetch('https://<ns>.adobeioruntime.net/api/v1/web/get-orders', {
  headers: { Authorization: `Bearer ${imsTokenFromUix}` }
});
// action exchanges IMS token for Commerce token via @adobe/aio-commerce-lib-auth
```
