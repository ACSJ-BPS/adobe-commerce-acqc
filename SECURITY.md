# Security Policy

## Supported Versions

We actively support the following versions of M2-Quality with security updates:

| Version | Supported          | Status              |
| ------- | ------------------ | ------------------- |
| 1.x     | :white_check_mark: | Active Development  |
| < 1.0   | :x:                | Not Supported       |

## Security Updates

- **Critical vulnerabilities**: Patched within 24-48 hours
- **High severity**: Patched within 1 week
- **Medium severity**: Patched in next minor release
- **Low severity**: Patched in next patch release

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please report them via email to:

**kumarrao@adobe.com**

### What to Include

To help us better understand and resolve the issue, please include:

1. **Description** - Clear description of the vulnerability
2. **Impact** - Potential impact and severity assessment
3. **Reproduction Steps** - Detailed steps to reproduce
4. **Proof of Concept** - Code or commands demonstrating the issue
5. **Affected Versions** - Which versions are affected
6. **Suggested Fix** - Your recommendations (if any)
7. **Contact Information** - How we can reach you for follow-up

### Example Report

```
Subject: [SECURITY] SQL Injection in ReportGenerator

Description:
SQL injection vulnerability exists in the ReportGenerator class when
processing user-supplied module names without proper sanitization.

Impact:
High - Allows arbitrary SQL execution with database user privileges.
Could lead to data exfiltration or corruption.

Affected Versions:
1.0.0 - 1.2.3

Reproduction Steps:
1. Run: php bin/m2-quality report --module="test'); DROP TABLE users;--"
2. SQL injection occurs in src/Reports/ReportGenerator.php line 245
3. No input validation or parameterized queries used

Proof of Concept:
[Include code snippet or commands]

Suggested Fix:
Use parameterized queries or prepared statements. See attached patch.

Contact:
researcher@example.com
```

## Response Timeline

1. **Acknowledgment** - We will acknowledge receipt within 24 hours
2. **Initial Assessment** - We will provide an initial assessment within 48 hours
3. **Investigation** - We will investigate and develop a fix
4. **Verification** - We will test the fix thoroughly
5. **Disclosure** - We will coordinate disclosure with you

## Disclosure Policy

### Coordinated Disclosure

We follow a coordinated disclosure process:

1. **Report received** - Vulnerability reported to security@m2quality.dev
2. **Confirmation** - We confirm the vulnerability and severity
3. **Fix development** - We develop and test a fix
4. **Security release** - We prepare a security release
5. **Public disclosure** - After fix is released (typically 90 days after report)

### Public Disclosure

After a fix is released:

- Security advisory published on GitHub
- CVE requested (if applicable)
- Credits given to reporter (unless they prefer anonymity)
- CHANGELOG updated with security notes

## Security Best Practices

### For Users

When using M2-Quality:

1. **Keep Updated** - Always use the latest version
2. **Secure Configuration** - Follow security configuration guidelines
3. **Access Control** - Limit access to configuration files
4. **API Keys** - Never commit API keys or credentials
5. **Scan Trusted Code** - Only scan code from trusted sources
6. **Review Reports** - Check reports for sensitive information before sharing

### For Contributors

When contributing:

1. **Input Validation** - Always validate and sanitize user input
2. **SQL Safety** - Use parameterized queries, never string concatenation
3. **Path Traversal** - Validate file paths, prevent directory traversal
4. **Command Injection** - Avoid shell execution, use safe APIs
5. **Secrets Management** - Never hardcode credentials or API keys
6. **Dependencies** - Keep dependencies updated, monitor for vulnerabilities

## Known Security Considerations

### AI Provider API Keys

M2-Quality supports AI providers (OpenAI, Local LLM):

- **Never commit** API keys to version control
- Store keys in `config/ai.yaml` (gitignored by default)
- Use environment variables for CI/CD
- Rotate keys regularly
- Use read-only keys when possible

### File System Access

M2-Quality analyzes local code:

- Only scan code you trust
- Be cautious with auto-fix features
- Review changes before committing
- Use `--dry-run` flags for testing

### Database Connections

For upgrade readiness checks:

- Use read-only database credentials
- Limit database user permissions
- Never expose connection strings in reports
- Use SSH tunnels for remote databases

### Report Generation

Generated reports may contain:

- Code snippets
- File paths
- Configuration details
- Security vulnerabilities

**Always review reports before sharing publicly.**

## Security Scanning

We use the following tools to ensure M2-Quality's security:

- **Static Analysis** - PHPStan, Psalm
- **Dependency Scanning** - Composer audit, npm audit
- **SAST** - GitHub CodeQL
- **Secret Scanning** - GitHub Secret Scanning
- **Vulnerability Database** - CVE monitoring

## Bug Bounty Program

We currently **do not** have a bug bounty program, but we:

- Recognize security researchers in our Hall of Fame
- Provide public acknowledgment (unless you prefer anonymity)
- Credit contributions in release notes and security advisories

## Security Hall of Fame

We thank the following researchers for responsibly disclosing security issues:

*(No reports received yet - you could be the first!)*

## Compliance

### OWASP Top 10

M2-Quality follows OWASP guidelines to prevent:

- Injection attacks
- Broken authentication
- Sensitive data exposure
- XML external entities (XXE)
- Security misconfigurations
- Cross-site scripting (XSS)
- Insecure deserialization
- Using components with known vulnerabilities
- Insufficient logging and monitoring

### Data Privacy

M2-Quality:

- Does not collect or transmit user data (unless you use cloud AI providers)
- Stores all analysis locally
- Does not require telemetry
- Respects your code privacy

When using AI providers:

- Code snippets may be sent to OpenAI API (if enabled)
- Local LLM option available for privacy-sensitive environments
- Review AI provider terms before use

## Questions?

For security-related questions (non-vulnerability):

- Email: kumarrao@adobe.com
- GitHub Discussions: Security category
- Documentation: docs/security.md

---

**Thank you for helping keep M2-Quality and its users safe!**
