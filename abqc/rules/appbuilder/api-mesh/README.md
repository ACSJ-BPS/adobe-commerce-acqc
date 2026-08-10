# API Mesh

33 rules covering Adobe API Mesh — the managed GraphQL gateway on App Builder. Mesh fronts REST, GraphQL, and JSON-Schema sources; aggregates them into a single GraphQL schema; runs at the Adobe edge with its own caching, auth, and observability surface. These rules cover the production-grade hygiene and Adobe-specific gotchas that have no ESLint equivalent.

## Files

| File | Rules | Coverage |
|---|---|---|
| [config.md](./config.md) | 5 | meshConfig.json — env vars, size limits, source naming, mesh scoping, IaC |
| [auth.md](./auth.md) | 3 | Upstream credentials, token caching in Runtime, no IMS in browser |
| [security.md](./security.md) | 4 | Introspection, SSRF, HTTPS-only sources, error normalization |
| [performance.md](./performance.md) | 6 | N+1 prevention, arg validation, edge timeout, missing depth/rate limits, hooks |
| [transforms.md](./transforms.md) | 4 | filterSchema, prefix/rename, thin resolvers, federation discipline |
| [caching.md](./caching.md) | 4 | Static-only caching, Cache-Control, TTL strategy, three caching layers |
| [observability.md](./observability.md) | 3 | Structured logging, log forwarding, httpDetails privacy |
| [operations.md](./operations.md) | 4 | Workspace-to-env mapping, dev never points at prod, drift detection, local dev |
| **Total** | **33** | |

## Sources

- [Adobe API Mesh — Developer Documentation](https://developer.adobe.com/graphql-mesh-gateway/)
- [Adobe API Mesh CLI plugin](https://github.com/adobe/aio-cli-plugin-api-mesh)
- [Cache-control headers](https://developer.adobe.com/graphql-mesh-gateway/gateway/cache-control-headers/)
- [Headers documentation](https://developer.adobe.com/graphql-mesh-gateway/gateway/headers/)
- [Hooks in API Mesh](https://developer.adobe.com/graphql-mesh-gateway/mesh/advanced/hooks/)
- [Logging for API Mesh](https://developer.adobe.com/graphql-mesh-gateway/mesh/advanced/logging/)
- [Developer tools for API Mesh](https://developer.adobe.com/graphql-mesh-gateway/mesh/advanced/developer-tools/)
- [API Mesh CI/CD Best Practices](https://developer.adobe.com/graphql-mesh-gateway/mesh/best-practices/cicd/)
- [Adobe Commerce — Create a mesh with a single source](https://experienceleague.adobe.com/en/docs/commerce-learn/tutorials/extensibility/api-mesh/graphql-single-source)
- [API Mesh starter kit using GitHub Codespaces](https://experienceleague.adobe.com/en/docs/commerce-learn/tutorials/extensibility/api-mesh/starter-kit-github-codespaces)
