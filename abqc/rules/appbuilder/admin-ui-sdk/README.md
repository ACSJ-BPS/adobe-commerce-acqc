# Admin UI SDK

16 rules covering the Adobe Commerce Admin UI SDK on App Builder — the SDK for declaring UI extensions inside the Commerce admin (mass actions, menus, page extensions, grid columns, secret fields). Apps register extension points via a single runtime action; the SPA loads in a Commerce-managed iframe and communicates with the admin shell via `@adobe/uix-guest`.

## Files

| File | Rules | Coverage |
|---|---|---|
| [structure.md](./structure.md) | 3 | Project layout — extensions pattern, src/commerce-backend-ui-1, ext.config.yaml |
| [registration.md](./registration.md) | 3 | Vendor-prefixed extension IDs, one menu per app, payload size and stability |
| [security.md](./security.md) | 4 | require-adobe-auth, no token logging, no dangerouslySetInnerHTML, secrets storage |
| [integration.md](./integration.md) | 3 | uix-guest over postMessage, sandbox attributes, backend-only Commerce calls |
| [deploy.md](./deploy.md) | 3 | PUBLISHED state for ACCS, pinned uix versions, productDependencies |
| **Total** | **16** | |

## Sources

- [Adobe Commerce — Admin UI SDK Overview](https://developer.adobe.com/commerce/extensibility/admin-ui-sdk/)
- [Adobe Commerce — Admin UI SDK Extension Points](https://developer.adobe.com/commerce/extensibility/admin-ui-sdk/extension-points/)
- [@adobe/uix-guest on npm](https://www.npmjs.com/package/@adobe/uix-guest)
- [@adobe/uix-core on npm](https://www.npmjs.com/package/@adobe/uix-core)
- [@adobe-commerce/aio-toolkit](https://www.npmjs.com/package/@adobe-commerce/aio-toolkit)
- [Adobe Commerce — App Management](https://experienceleague.adobe.com/en/docs/commerce/app-management/overview)
- [Adobe Commerce 2.4.7 release notes](https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/adobe-commerce/2-4-7)
- [Adobe Commerce — IMS Module Configuration](https://experienceleague.adobe.com/en/docs/commerce-admin/start/admin/ims/adobe-ims-config.html)
