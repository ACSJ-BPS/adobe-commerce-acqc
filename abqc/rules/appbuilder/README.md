# App Builder & I/O Runtime

**110 rules across the App Builder ecosystem** — 61 rules in 12 sub-files covering core App Builder & I/O Runtime topics (action signatures, runtime quotas, IMS auth, I/O Events, manifest, deployment, tooling, Commerce GraphQL patterns, bulk operations, action sequences, cost optimization), plus 49 rules across 2 nested sub-product subfolders for first-class App Builder products: [api-mesh/](./api-mesh/) (33 rules) and [admin-ui-sdk/](./admin-ui-sdk/) (16 rules).

## Files

| File | Rules | Coverage |
|---|---|---|
| [runtime.md](./runtime.md) | 12 | Action handler shape, timeouts, memory, concurrency, Node runtime version, error response, sequences |
| [validation.md](./validation.md) | 4 | Input validation, params hardening |
| [auth.md](./auth.md) | 4 | IMS, OAuth S2S, token actions |
| [events.md](./events.md) | 6 | I/O Events challenge/response, HMAC, polling vs Events, idempotency, journaling |
| [sdk.md](./sdk.md) | 5 | Files vs State SDK, Logger, default params |
| [config.md](./config.md) | 2 | app.config.yaml, manifest |
| [secrets.md](./secrets.md) | 2 | .env, .aio, AIO_ vars |
| [deploy.md](./deploy.md) | 5 | API Gateway, disable-download, quotas, vanity URLs |
| [tooling.md](./tooling.md) | 6 | aio CLI, TypeScript build, template hygiene |
| [observability.md](./observability.md) | 1 | Structured logging |
| [performance.md](./performance.md) | 8 | Cold-start, fan-out, concurrency caps, bulk ops/pagination, cost optimization |
| [integration.md](./integration.md) | 6 | AEM/Commerce, language defaults, Commerce GraphQL patterns |
| **Total** | **61** | |

## Sub-products

First-class App Builder products with their own SDK, CLI plugin, and deployment surface. Each is a nested subfolder with its own README and Sources.

| Sub-product | Rules | Coverage |
|---|---|---|
| [api-mesh/](./api-mesh/) | 33 | Adobe API Mesh — meshConfig, upstream auth, SSRF/introspection, N+1 prevention, transforms, caching, observability, ops |
| [admin-ui-sdk/](./admin-ui-sdk/) | 16 | Adobe Commerce Admin UI SDK — extension layout, registration, security (require-adobe-auth, no token logging), uix-guest integration, deploy (PUBLISHED state, version pinning) |
| **Sub-products total** | **49** | |

## Sources

- [Adobe App Builder — Security Overview](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/security/)
- [Adobe App Builder — Creating Actions](https://developer.adobe.com/app-builder/docs/guides/runtime_guides/creating-actions)
- [Adobe App Builder — Configuration Files](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/configuration/configuration)
- [Adobe I/O Runtime — System Settings](https://developer.adobe.com/runtime/docs/guides/using/system_settings/)
- [Adobe App Builder — Securing Web Actions](https://developer.adobe.com/app-builder/docs/guides/runtime_guides/securing-web-actions)
- [Adobe App Builder — I/O Events Handler Snippet](https://developer.adobe.com/app-builder/docs/resources/sample_apps/code_snippets/events)
- [Adobe App Builder — Using Client SDKs](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/architecture_overview/using-sdks)
- [Adobe — JWT to OAuth S2S migration guide](https://developer.adobe.com/developer-console/docs/guides/authentication/ServerToServerAuthentication/migration/)
- [@adobe/aio-lib-ims](https://github.com/adobe/aio-lib-ims)
- [@adobe/aio-lib-state](https://github.com/adobe/aio-lib-state)
- [@adobe/aio-lib-files](https://github.com/adobe/aio-lib-files)
- [Adobe App Builder — Application logging & forwarding](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/application_logging/logging)
- [Adobe App Builder — Storage quotas and limits](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/storage/)
