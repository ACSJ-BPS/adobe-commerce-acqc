# Security

36 rules covering Adobe-specific security (incl. PKCE/BFF for SPAs), eslint-plugin-security, OWASP Web Top 10, OWASP API Top 10, and supply-chain.

## Files

| File | Rules | Coverage |
|---|---|---|
| [adobe.md](./adobe.md) | 12 | require-adobe-auth (disable + omission), State vs Vault, CORS, tenant isolation, PKCE/BFF for SPAs |
| [eslint-security.md](./eslint-security.md) | 7 | object-injection, non-literal-*, timing, bidi |
| [owasp-web.md](./owasp-web.md) | 6 | XSS, SQLi, password hashing, HTTPS, CSP |
| [owasp-api.md](./owasp-api.md) | 4 | IDOR, SSRF, mass assignment, BOLA |
| [supply-chain.md](./supply-chain.md) | 7 | Version pinning, audit logging, governance |
| **Total** | **36** | |

## Sources

- [Adobe App Builder — Security Overview](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/security/)
- [Adobe App Builder — Securing Web Actions](https://developer.adobe.com/app-builder/docs/guides/runtime_guides/securing-web-actions)
- [Adobe App Builder — Credential Rotation](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/deployment/credential-rotation)
- [eslint-plugin-security — full rule list](https://github.com/eslint-community/eslint-plugin-security#rules)
- [OWASP Top 10 — Injection](https://owasp.org/Top10/A03_2021-Injection/)
- [OWASP Top 10 2021 — A07 Identification and Authentication Failures](https://owasp.org/Top10/A07_2021-Identification_and_Authentication_Failures/)
- [OWASP Top 10 2021 — A09 Security Logging and Monitoring Failures](https://owasp.org/Top10/A09_2021-Security_Logging_and_Monitoring_Failures/)
- [OWASP Top 10 2021 — A10 Server-Side Request Forgery](https://owasp.org/Top10/A10_2021-Server-Side_Request_Forgery_%28SSRF%29/)
- [OWASP API Security Top 10 — API1 Broken Object Level Authorization](https://owasp.org/API-Security/editions/2023/en/0xa1-broken-object-level-authorization/)
- [OWASP API Security Top 10 — API6 Unrestricted Access to Sensitive Business Flows](https://owasp.org/API-Security/editions/2023/en/0xa6-unrestricted-access-to-sensitive-business-flows/)
- [OWASP — Cross Site Scripting Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
- [OWASP — Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html)
- [OWASP — Content Security Policy](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- [OWASP — SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)
- [OWASP — Mass Assignment Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Mass_Assignment_Cheat_Sheet.html)
- [Trojan Source attack (CVE-2021-42574)](https://trojansource.codes/)
- [DOMPurify](https://github.com/cure53/DOMPurify)
- [npm audit docs](https://docs.npmjs.com/cli/commands/npm-audit)
