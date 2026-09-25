# Deployment & CI/CD

17 rules covering CI/CD pipeline, performance SLOs, environments, releases, rollback, and secret hygiene.

## Files

| File | Rules | Coverage |
|---|---|---|
| [pipeline.md](./pipeline.md) | 5 | Pre-deploy gate, post-app-deploy hooks, --no-build, branch→workspace mapping |
| [slos.md](./slos.md) | 3 | Action/event p95 SLOs, warm-container reuse |
| [environments.md](./environments.md) | 2 | Stage-before-Prod, workspace separation |
| [releases.md](./releases.md) | 2 | Versioned actions, tagged releases |
| [rollback.md](./rollback.md) | 2 | One-command rollback, post-deploy monitoring |
| [secrets.md](./secrets.md) | 3 | gitleaks pre-commit, OAuth S2S in CI, no console.json in git |
| **Total** | **17** | |

## Sources

- [Adobe App Builder — Configuration Files (hooks)](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/configuration/configuration)
- [Adobe App Builder — Deployment](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/deployment/)
- [Adobe App Builder — Credential Rotation](https://developer.adobe.com/app-builder/docs/guides/app_builder_guides/deployment/credential-rotation)
- [Adobe — aio-apps-action GitHub Action](https://github.com/adobe/aio-apps-action)
- [Adobe Developer Console — Activation logs](https://developer.adobe.com/runtime/docs/guides/operations/logging/)
- [RFC 8594 — Sunset HTTP Header](https://datatracker.ietf.org/doc/html/rfc8594)
- [Google SRE Book — Service Level Objectives](https://sre.google/sre-book/service-level-objectives/)
- [gitleaks](https://github.com/gitleaks/gitleaks)
- [pre-commit](https://pre-commit.com/)
- [OWASP — Secrets Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html)
