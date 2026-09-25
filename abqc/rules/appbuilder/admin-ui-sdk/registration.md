# Admin UI SDK — Registration

> Part of [Admin UI SDK](./README.md).

## Use a vendor-prefixed extension ID consistently across register(), attach(), and the registration payload [error] [contracts, registration]
The extension ID is the join key between three places: the `register({ id })` call in the SPA entry, the `attach({ id })` call from any sub-page that needs shared context, and the registration action's response payload (used in `actionId`, menu item IDs, etc.). A mismatch silently breaks `attach()` and shared-context retrieval. Always vendor-prefix to avoid collisions (`acme_tax`, not `tax`).

### Check
```yaml
type: builtin
id: extension-id-consistency
scope: repo
```

### Bad
```js
// SPA entry
await register({ id: 'newsletter_management' });
// later, in a mass-action page
await attach({ id: 'PromoteProducts' }); // mismatch — attach hangs
```

### Good
```js
const EXTENSION_ID = 'acme_promote_products';
await register({ id: EXTENSION_ID });
await attach({ id: EXTENSION_ID });
// registration action returns menu items with id: `${EXTENSION_ID}::dashboard`
```

## One menu entry per app — use SPA internal routing for multiple screens [warn] [ux, registration]
Admin UI SDK currently has limited support for multi-menu per app. The recommended pattern is one top-level menu item that opens an SPA with internal routes/tabs. Trying to declare two menus that route to different HTML pages produces inconsistent behavior across Commerce versions.

### Check
```yaml
type: builtin
id: one-menu-entry
files: ["**/app.config.yaml", "**/ext.config.yaml"]
```

### Bad
```js
menuItems: [
  { id: '...::dashboard', title: 'Dashboard' },
  { id: '...::settings', title: 'Settings' },  // second top-level menu
  { id: '...::reports',  title: 'Reports' }    // third top-level menu
]
```

### Good
```js
menuItems: [
  { id: '...::app', title: 'My App' }
]
// SPA uses react-router internally for /dashboard, /settings, /reports
```

## Keep the registration payload small and stable — Commerce caches it as configuration [warn] [performance, registration]
Commerce caches the registration response server-side and in `sessionStorage`. The action is not called per page load — treat it like configuration, not a data feed. Computing heavy data inside `registration` (DB queries, third-party API calls) wastes invocations and risks stale results due to caching.

### Check
```yaml
type: builtin
id: registration-payload-pure
files: ["**/actions/**registration**"]
```

### Bad
```js
exports.main = async () => {
  const settings = await fetchAllUserSettings();   // 200ms
  const flags    = await launchDarkly.getFlags();   // 150ms
  return { body: buildRegistrationFromSettings(settings, flags) };
};
```

### Good
```js
exports.main = async () => ({
  body: AdminUiSdk.build({
    extensionId: 'acme_app',
    menuItems: [{ id: 'acme_app::dashboard', title: 'Dashboard' }],
    page: { title: 'Dashboard' }
  })
}); // pure function of static config
```
