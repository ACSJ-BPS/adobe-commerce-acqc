# Open Source Contribution Guide for M2-Quality

## 🎯 Summary of Key Rules & Best Practices

This guide provides comprehensive recommendations for successfully managing M2-Quality as an open-source project.

---

## 📋 Essential Files Created

### ✅ Core Documentation
1. **CODE_OF_CONDUCT.md** ✓ - Community behavior standards
2. **SECURITY.md** ✓ - Security reporting and policies
3. **CONTRIBUTING.md** ✓ - Already exists, comprehensive
4. **GOVERNANCE.md** ✓ - Project governance and decision-making
5. **README.md** ✓ - Already exists, well-documented

### ✅ GitHub Templates
1. **Bug Report Template** ✓ - Structured bug reporting
2. **Feature Request Template** ✓ - Standardized feature proposals
3. **Security Issue Template** ✓ - Low-severity security reporting
4. **Pull Request Template** ✓ - Comprehensive PR guidelines
5. **Issue Template Config** ✓ - Template configuration

---

## 🔑 Key Contribution Rules

### 1. **Code Quality Standards**

#### Required Checks
- ✅ PSR-12 coding standards
- ✅ PHPStan level 8 (no errors)
- ✅ All tests passing
- ✅ Code coverage maintained
- ✅ No linter violations

#### Before Every PR
```bash
# Run these commands
composer check-cs         # Code style
composer phpstan          # Static analysis
php run-tests.php         # All tests
```

### 2. **Git Workflow**

#### Branch Naming
```
feature/description      # New features
fix/bug-description      # Bug fixes
docs/what-changed        # Documentation
refactor/component-name  # Refactoring
test/what-tested         # Test additions
```

#### Commit Messages (Conventional Commits)
```
feat(analyzer): add SQL injection detector
fix(cache): resolve cache invalidation issue
docs(api): update API documentation
test(scanner): add integration tests
refactor(core): simplify analyzer interface
chore(deps): update dependencies
```

### 3. **Pull Request Requirements**

#### Must Have:
- ✅ Clear description of changes
- ✅ Link to related issue
- ✅ Tests for new functionality
- ✅ Documentation updates
- ✅ No breaking changes (or clearly documented)
- ✅ Self-review completed
- ✅ All CI checks passing

#### Review Process:
1. **Automated Checks** - Must pass (CI/CD)
2. **Code Review** - At least 1 maintainer approval
3. **Testing** - Manual testing if needed
4. **Documentation** - Docs reviewed
5. **Merge** - Squash or merge strategy

### 4. **Testing Requirements**

#### Test Coverage
- **Unit Tests** - Required for all new code
- **Integration Tests** - Required for interactions
- **Functional Tests** - Required for commands
- **Minimum Coverage** - 80% for new code

#### Test Structure
```php
tests/
├── Unit/          # Fast, isolated tests
├── Integration/   # Component interaction tests
├── Functional/    # End-to-end command tests
└── Performance/   # Performance benchmarks
```

### 5. **Documentation Standards**

#### Required Documentation:
- **Code Comments** - PHPDoc for all public methods
- **README Updates** - For new features
- **CHANGELOG** - All changes noted
- **Examples** - For new commands/features
- **API Docs** - For public APIs

#### Documentation Locations:
```
docs/
├── installation.md      # Setup guide
├── configuration.md     # Config reference
├── usage.md            # Usage examples
├── api.md              # API documentation
├── extending.md        # Extension guide
└── troubleshooting.md  # Common issues
```

---

## 🚫 Contribution No-Nos (What NOT to Do)

### ❌ Code
- Don't break backward compatibility without discussion
- Don't add dependencies without approval
- Don't submit without tests
- Don't ignore code style standards
- Don't hardcode sensitive data
- Don't commit commented-out code
- Don't use `var_dump()` or `die()` for debugging

### ❌ Pull Requests
- Don't create PRs from `main` branch
- Don't mix multiple features in one PR
- Don't submit broken code
- Don't skip the PR template
- Don't force push after review starts
- Don't merge your own PRs (maintainers only)

### ❌ Issues
- Don't create duplicate issues
- Don't report security issues publicly
- Don't hijack other people's issues
- Don't demand immediate responses
- Don't close issues without resolution

### ❌ Community
- Don't be disrespectful or rude
- Don't spam or promote
- Don't share private information
- Don't impersonate maintainers
- Don't violate Code of Conduct

---

## ✅ Best Practices for Contributors

### 1. **Before Starting**
```bash
# Always work on latest code
git checkout main
git pull origin main
git checkout -b feature/your-feature

# Install dependencies
composer install
npm install

# Run tests to ensure clean state
php run-tests.php
```

### 2. **During Development**
```bash
# Run tests frequently
php run-tests.php unit

# Check code style
composer check-cs
composer fix-cs

# Run static analysis
composer phpstan

# Manual testing
php bin/m2-quality analyze ./test-code
```

### 3. **Before Submitting PR**
```bash
# Final checks
composer check-cs     # Must pass
composer phpstan      # Must pass
php run-tests.php     # Must pass

# Update documentation
# Update CHANGELOG.md
# Review your own code

# Push and create PR
git push -u origin feature/your-feature
```

---

## 🎖️ Contributor Recognition

### How We Recognize Contributors

1. **Contributors List** - Added to README
2. **Release Notes** - Credited in releases
3. **Hall of Fame** - Top contributors highlighted
4. **Swag** - Stickers, t-shirts for significant contributions
5. **Committer Status** - Path to commit access
6. **Maintainer Status** - Path to core team

### Contribution Levels

| Level | Criteria | Recognition |
|-------|----------|-------------|
| **First-Time** | 1st merged PR | Welcome message, mentioned in release notes |
| **Active** | 5+ PRs | Added to Contributors section |
| **Regular** | 20+ PRs | Committer consideration |
| **Core** | Long-term commitment | Maintainer consideration |

---

## 🔒 Security Guidelines

### For Contributors

#### What to Check:
- ✅ Input validation everywhere
- ✅ No SQL string concatenation
- ✅ No command injection risks
- ✅ No path traversal vulnerabilities
- ✅ No exposed secrets or API keys
- ✅ No unsafe deserialization

#### Security Checklist:
```php
// ✅ Good - Parameterized query
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);

// ❌ Bad - SQL injection risk
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];

// ✅ Good - Path validation
$file = basename($userInput);

// ❌ Bad - Path traversal risk
$file = $userInput;

// ✅ Good - Environment variable
$apiKey = getenv('API_KEY');

// ❌ Bad - Hardcoded secret
$apiKey = 'sk-123456';
```

### Reporting Security Issues

**HIGH/CRITICAL:** Email security@m2quality.dev  
**LOW:** Create GitHub issue with security template

---

## 📊 Project Metrics & Goals

### Current Status
- ✅ 27 core commands working (100%)
- ✅ AI integration (Local + Cloud)
- ✅ Comprehensive documentation
- ✅ Production-ready

### Community Goals

**Short Term (3 months):**
- 10+ external contributors
- 50+ stars on GitHub
- 20+ issues resolved
- First external PR merged

**Medium Term (6 months):**
- 50+ contributors
- 200+ stars
- 5+ committers
- Quarterly releases

**Long Term (12 months):**
- 100+ contributors
- 500+ stars
- Active community
- Enterprise adoption

---

## 🤝 Community Building

### How to Get Involved

1. **Start Small**
   - Fix typos in documentation
   - Improve error messages
   - Add code comments
   - Write tests

2. **Grow**
   - Fix bugs
   - Add small features
   - Review PRs
   - Help in discussions

3. **Lead**
   - Propose major features
   - Mentor newcomers
   - Maintain areas
   - Shape project direction

### Communication Channels

- 💬 **GitHub Discussions** - General questions, ideas
- 🐛 **GitHub Issues** - Bug reports, feature requests
- 🔧 **Pull Requests** - Code contributions
- 📧 **Email** - security@m2quality.dev (security only)
- 💼 **LinkedIn/Twitter** - Project updates

---

## 📅 Release Schedule

### Regular Releases

- **Patch** (1.0.x) - Monthly (bug fixes)
- **Minor** (1.x.0) - Quarterly (new features)
- **Major** (x.0.0) - Annually (breaking changes)

### Release Checklist

- [ ] All tests passing
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
- [ ] Version bumped
- [ ] Tag created
- [ ] GitHub release created
- [ ] Announcement posted

---

## 🎓 Learning Resources

### For New Contributors

1. **M2-Quality Specific:**
   - Read README.md
   - Browse existing code
   - Check closed PRs
   - Try all commands

2. **General Skills:**
   - PHP 8.1+ features
   - Magento 2 architecture
   - Static analysis tools
   - Testing best practices

3. **Recommended Reading:**
   - PSR-12 Coding Standard
   - Conventional Commits Spec
   - Open Source Guide
   - GitHub Flow

---

## 📞 Getting Help

### For Contributors

**Code Questions:**
- GitHub Discussions (Q&A category)
- Comment on related issue/PR
- Tag maintainers if urgent

**Process Questions:**
- Read CONTRIBUTING.md
- Check GitHub Discussions
- Ask in PR comments

**Security Questions:**
- Read SECURITY.md
- Email security@m2quality.dev

---

## ✅ Pre-Launch Checklist

Before opening to public contributions:

- [x] CODE_OF_CONDUCT.md created
- [x] SECURITY.md created
- [x] CONTRIBUTING.md exists
- [x] GOVERNANCE.md created
- [x] GitHub issue templates created
- [x] GitHub PR template created
- [x] README.md is comprehensive
- [ ] CI/CD pipeline configured
- [ ] Update repository settings
- [ ] Add LICENSE file (MIT suggested)
- [ ] Add CODEOWNERS file
- [ ] Enable GitHub Discussions
- [ ] Configure branch protection
- [ ] Set up automated checks
- [ ] Create initial GitHub release
- [ ] Announce on social media

---

## 🚀 Next Steps

1. **Review all created files** - Customize for your needs
2. **Update repository settings** - Enable features
3. **Configure CI/CD** - Set up automated checks
4. **Announce publicly** - Share with community
5. **Monitor early contributors** - Provide excellent support
6. **Iterate and improve** - Refine based on feedback

---

**Questions? Open a GitHub Discussion or email governance@m2quality.dev**

**Ready to accept contributions? Review the Pre-Launch Checklist above!**
