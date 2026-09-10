# Process — Compliance

> Part of [Process](./README.md).

## Data residency confirmed for all PII and regulated data [error] [compliance, governance]

Where data physically lives matters when GDPR, CCPA, HIPAA, PCI-DSS, or industry-specific frameworks apply. Adobe's storage inherits Adobe's compliance posture for default cases; non-default cases (EU-only customers, healthcare data, financial transactions) need explicit decisions documented and signed off by legal. The security.md rule covers the code pattern; this rule covers the *paper trail* compliance auditors require.

### Check
```yaml
type: builtin
id: data-residency-doc
scope: repo
```

### Bad
```text
EU customer data stored in Files SDK with no documented region pinning.
Auditor asks: "Where does this data physically live?"
Team: "Wherever Adobe's CDN puts it?"
Audit fails.
```

### Good
```markdown
# Data Residency Decisions (signed off by Legal 2026-04-15)

| Data class | Storage | Region | Encryption | Retention | Compliance basis |
|---|---|---|---|---|---|
| EU customer profiles    | External S3 | eu-central-1 | SSE-S3 + KMS | 7y          | GDPR Art. 6 |
| US customer profiles    | Files SDK   | (Adobe default) | at-rest | 7y       | CCPA |
| Payment metadata        | External S3 | us-east-1, eu-central-1 (regional sharding) | KMS | 10y | PCI-DSS Level 2 |
| App analytics (anon)    | Files SDK   | (Adobe default) | at-rest | 90d      | GDPR Art. 6 (legitimate interest) |
| Audit logs              | External S3 | us-east-1   | KMS + Object Lock | 7y immutable | SOC 2 |

Legal sign-off: D. Park, 2026-04-15. DPIA filed for EU profiles (link in `docs/compliance/dpia-2026-04.md`).
```

---

## DPIA filed when handling EU PII or regulated data [error] [compliance, governance]

A Data Protection Impact Assessment (DPIA) is a GDPR Article 35 requirement when processing is likely to result in high risk to data subjects — almost always true for a new B2B/B2C app handling EU profiles, locations, or behavioral data. The DPIA captures the data flow, the lawful basis, the safeguards, and the residual risk. Without a filed DPIA, you cannot demonstrate compliance to a supervisory authority. File before the data starts flowing, not after.

### Check
```yaml
type: builtin
id: dpia-doc
scope: repo
```

### Bad
```text
Launched EU-customer profile feature without DPIA. Six months later, a Subject Access
Request arrives with a complaint. Supervisory authority opens an inquiry. There is no
documented analysis to point to. Fines and remediation order follow.
```

### Good
```markdown
# DPIA — EU Customer Profile Feature (filed 2026-04-10)

## Processing description
- Categories of data: name, email, country, IP-derived city, last-login timestamp
- Data subjects: ~120,000 EU customers
- Lawful basis (Art. 6): contract performance + legitimate interest

## Risk assessment
| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Unauthorized access | Low | High | RBAC + audit logging + KMS encryption at rest |
| Data exfiltration via SSRF | Low | High | URL allowlist (security.md SSRF rule) |
| Cross-border transfer | Medium | Medium | EU-only S3 region (data-residency rule) + SCCs in Adobe DPA |
| Excessive retention | Low | Medium | 7-year cap + automated purge job |

## Sign-offs
- DPO: A. Müller, 2026-04-10
- Legal: D. Park, 2026-04-12
- Security: M. Chen, 2026-04-12

Re-review on: any new data category added, any new sub-processor, every 12 months.
```
