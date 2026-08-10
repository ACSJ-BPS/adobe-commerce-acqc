# Process — Governance

> Part of [Process](./README.md).

## RBAC roles and access tiers signed off by security before GA [error] [auth, governance]

Who can read which data, who can mutate which records, who can mint tokens — these answers must be written down and approved by the security team before production traffic, not retrofitted after an incident. App Builder workspaces give you environment isolation; RBAC inside the application (admin vs editor vs viewer) is your responsibility. Capture the matrix in `docs/security/rbac.md` and require security sign-off as a launch gate.

### Bad
```text
Launch readiness review:
"Who can delete orders?"
"Anyone with a valid IMS token, I think?"
"Has security signed off on that?"
"... we never asked."
```

### Good
```markdown
# RBAC Matrix (signed off by Security 2026-04-25)

| Role | IMS scope required | Read | Create | Update | Delete |
|---|---|---|---|---|---|
| Viewer  | openid, read_organizations | orders, customers | — | — | — |
| Editor  | + write_organizations      | + drafts          | orders, drafts | own drafts | own drafts |
| Admin   | + admin_console_access     | all               | all            | all        | all (audit-logged) |

Security review: M. Chen, 2026-04-25. Re-review on any role change.
```

---

## GenAI usage policy: no proprietary code or customer data submitted to public AI tools [error] [governance, ai]

Pasting customer PII or proprietary code into a public LLM exposes it to that vendor's training set, support staff, and (in some breach scenarios) the open internet. Every team needs a written policy: which AI tools are approved, what categories of data may flow through them, and what review process applies to AI-generated code merged into the repo. Adobe's own employee policy is the floor.

### Bad
```text
Engineer pastes 200 lines of customer order schema + sample rows into ChatGPT free tier
to ask "how do I aggregate this?". The schema and sample data are now in OpenAI's logs
and may surface in future model updates.
```

### Good
```markdown
# GenAI Usage Policy (effective 2026-01-01)

## Approved tools
- GitHub Copilot Enterprise (Adobe-licensed; data not used for training)
- Claude in claude.ai with Adobe SSO (enterprise tier; zero retention)
- Internal Adobe Firefly Services for design assets

## Prohibited
- Public ChatGPT, Gemini, Perplexity (free or personal-paid tiers)
- Any tool not on the approved list

## Allowed data
- Public code (open-source libs, your own non-proprietary helpers)
- De-identified samples (real schemas with synthetic values)

## Prohibited data
- Customer PII, payment data, IMS tokens, API keys
- Proprietary algorithms, internal architecture diagrams, unreleased product info

## AI-generated code review
- Mark commits with `Generated-with: <tool>` trailer
- Code review must verify license compatibility and absence of memorized snippets
```

---

## Vendor and SaaS dependency list reviewed by procurement and security [warn] [supply-chain, governance]

Every third-party SDK, API, or SaaS your app integrates is a vendor relationship — with terms, a security posture, and a renewal cycle. Procurement needs the list for cost tracking; security needs it for risk review (data flow, breach notification clauses, sub-processor list). Without a list, vendors get added by individual engineers and disappear from organizational memory until the renewal email lands.

### Bad
```text
Renewal email arrives for `bestofbreed-analytics.com` — $48,000/year, integrated 18 months ago.
Nobody knows who decided to use it, what data flows through it, or what depends on it.
```

### Good
```markdown
# Third-Party Dependencies (reviewed quarterly)

| Vendor | Service | Data sent | Cost / yr | Owner | Last reviewed | Sub-processors |
|---|---|---|---|---|---|---|
| Stripe         | Payments      | Card tokens, amount, customer ID | $48k         | Eng | 2026-Q1 | AWS (us-east-1) |
| Adobe IMS      | Auth          | (no PII; IMS handles)            | included     | Eng | 2026-Q1 | — |
| Datadog        | APM + logs    | Request URLs, error stacks       | $12k         | SRE | 2026-Q1 | AWS, GCP |
| SendGrid       | Email         | Email address, message body      | $3k          | Mkt | 2026-Q1 | Twilio (parent) |

Quarterly review: J. Park (procurement) + M. Chen (security).
Removal process: ticket → 30-day deprecation notice → remove integration → close vendor account.
```
