# Project Governance

## Overview

M2-Quality is an open-source project committed to building a thriving, inclusive community. This document outlines how decisions are made, who has what responsibilities, and how contributors can grow within the project.

## Project Structure

### Roles

#### 1. **Maintainers** (Core Team)
- Full commit access to the repository
- Review and merge pull requests
- Manage releases and versioning
- Set project direction and roadmap
- Resolve disputes and conflicts
- Manage security disclosures

**Current Maintainers:**
- [To be determined based on your team]

#### 2. **Committers**
- Commit access to specific areas
- Review pull requests in their domain
- Help triage issues
- Mentor contributors

**Becoming a Committer:**
- Consistent high-quality contributions over 6+ months
- Domain expertise in specific areas
- Active participation in code reviews
- Nomination by existing maintainer

#### 3. **Contributors**
- Anyone who contributes code, docs, or feedback
- Submit pull requests
- Report issues
- Participate in discussions

**All contributors are valued equally!**

## Decision Making

### Consensus-Based Approach

We use a **lazy consensus** model:

1. **Proposal** - Ideas are proposed in GitHub Discussions or Issues
2. **Discussion** - Community provides feedback (minimum 3 business days)
3. **Consensus** - If no objections, the proposal is accepted
4. **Implementation** - Approved proposals can be implemented

### Major Decisions

For significant changes (breaking changes, major features, architectural shifts):

1. **RFC (Request for Comments)** - Create detailed proposal
2. **Community Review** - Minimum 2 weeks for feedback
3. **Maintainer Vote** - If needed, maintainers vote (simple majority)
4. **Documentation** - Decision is documented

### Conflict Resolution

If consensus can't be reached:

1. **Discussion** - Extended discussion period
2. **Mediation** - Neutral maintainer mediates
3. **Vote** - Maintainers vote as last resort
4. **Appeal** - Can be appealed to full maintainer team

## Contribution Ladder

### Path to Growth

```
Contributor → Active Contributor → Committer → Maintainer
```

#### **Contributor** → **Active Contributor**
- 5+ accepted pull requests
- Participation in code reviews
- Helping others in issues/discussions

**Benefits:**
- Recognition in Contributors list
- Priority in code reviews

#### **Active Contributor** → **Committer**
- 20+ accepted pull requests
- Expertise in specific area
- Regular code review participation
- 6+ months of active contribution

**Benefits:**
- Commit access to specific areas
- Invitation to committer meetings
- Listed as committer in README

#### **Committer** → **Maintainer**
- 12+ months as committer
- Demonstrated leadership
- Broad understanding of codebase
- Community building efforts
- Nomination by 2+ maintainers

**Benefits:**
- Full commit access
- Release management rights
- Voice in project direction
- Listed as maintainer in README

## Meetings & Communication

### Regular Meetings

1. **Monthly Maintainer Sync** - Core team planning
2. **Quarterly Community Call** - Open to all contributors
3. **Ad-hoc Working Groups** - For specific features/initiatives

**Meeting Notes:** Published in GitHub Discussions

### Communication Channels

- **GitHub Issues** - Bug reports, feature requests
- **GitHub Discussions** - General discussion, Q&A
- **GitHub PRs** - Code review and development
- **Slack** (optional) - Real-time communication
- **Email** - Official communications

## Release Management

### Release Cycle

- **Major releases** (x.0.0) - Annually, breaking changes allowed
- **Minor releases** (1.x.0) - Quarterly, new features
- **Patch releases** (1.0.x) - Monthly or as needed, bug fixes
- **Security releases** - Immediately when needed

### Release Process

1. **Planning** - Maintainers plan release scope
2. **Development** - Features/fixes implemented
3. **Code Freeze** - 1 week before release
4. **Testing** - Final testing and documentation
5. **Release** - Tag, publish, announce
6. **Post-Release** - Monitor for issues

### Version Support

- **Current major** - Full support
- **Previous major** - Security fixes for 12 months
- **Older versions** - No support

## Code Ownership

### CODEOWNERS

We use GitHub CODEOWNERS for automatic review requests:

```
# Core analyzers
/src/Analyzers/          @maintainer-team @analyzer-experts

# AI features
/src/Core/AI/            @maintainer-team @ai-team

# Reports
/src/Reports/            @maintainer-team @reporting-team

# Configuration
/config/                 @maintainer-team

# Documentation
/docs/                   @maintainer-team @doc-team

# CI/CD
/.github/                @maintainer-team
```

## Financial Model

### Funding

M2-Quality is:
- Open source and free to use
- Not currently seeking funding
- Open to sponsorships from companies

### Potential Revenue Streams

If funding is needed:
- **GitHub Sponsors** - Individual/corporate sponsorship
- **Commercial Support** - Paid support contracts
- **Training** - Paid training and workshops
- **Consulting** - Custom development services

**All funding goes toward:**
- Infrastructure costs
- Contributor recognition
- Community events
- Documentation improvements

## Trademark & Branding

### Project Name

"M2-Quality" and the project logo are:
- Part of the open-source project
- Can be used to refer to the official project
- Cannot be used to imply endorsement without permission

### Acceptable Use

✅ Allowed:
- "Built with M2-Quality"
- "Tested with M2-Quality"
- "M2-Quality integration for..."

❌ Not Allowed:
- Derivative names implying official status
- Use in commercial products without attribution
- Misleading use suggesting endorsement

## Code of Conduct Enforcement

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) for details.

**Enforcement Team:**
- Current maintainers
- Conduct committee (if needed)

**Confidentiality:**
All reports are handled confidentially.

## Amendments

This governance document can be amended:

1. **Proposal** - Submit PR to this document
2. **Discussion** - Minimum 2 weeks community discussion
3. **Vote** - Maintainer approval (2/3 majority)
4. **Implementation** - Changes take effect immediately

## Questions?

For governance questions:
- Open a GitHub Discussion
- Email: kumarrao@adobe.com
- Tag maintainers in relevant issues

---

**Last Updated:** January 29, 2026  
**Next Review:** July 2026
