# Contributing to M2-Quality

Thank you for your interest in contributing to M2-Quality! This guide will help you get started with contributing to the project.

## 🤝 How to Contribute

### Ways to Contribute

- **Bug Reports** - Report issues and bugs
- **Feature Requests** - Suggest new features
- **Code Contributions** - Submit pull requests
- **Documentation** - Improve documentation
- **Testing** - Help with testing and QA
- **Community Support** - Help other users

## 🚀 Getting Started

### Development Environment Setup

1. **Fork and Clone**
   ```bash
   git clone https://github.com/yourusername/m2-quality.git
   cd m2-quality
   ```

2. **Install Dependencies**
   ```bash
   composer install
   npm install
   ```

3. **Set Up Environment**
   ```bash
   cp .env.example .env
   # Edit .env with your settings
   ```

4. **Run Tests**
   ```bash
   php run-tests.php
   npm test
   ```

### Development Tools

```bash
# Code quality checks
composer check-cs
composer fix-cs

# Static analysis
composer phpstan

# Run test suite
php run-tests.php unit
php run-tests.php integration

# Performance testing
php performance-demo.php
```

## 📋 Contribution Guidelines

### Code Standards

- **PHP**: Follow PSR-12 coding standards
- **JavaScript**: Use ESLint configuration
- **TypeScript**: Strict type checking enabled
- **Documentation**: Use PHPDoc for PHP, JSDoc for JavaScript

### Git Workflow

1. **Create Feature Branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make Changes**
   - Follow coding standards
   - Add tests for new functionality
   - Update documentation

3. **Commit Changes**
   ```bash
   git add .
   git commit -m "feat: add new analyzer for custom rules"
   ```

4. **Push and Create PR**
   ```bash
   git push origin feature/your-feature-name
   ```

### Commit Message Format

We use [Conventional Commits](https://conventionalcommits.org/):

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes
- `refactor`: Code refactoring
- `test`: Adding tests
- `chore`: Build process or auxiliary tool changes

**Examples:**
```
feat(analyzer): add security analyzer for SQL injection detection
fix(cache): resolve cache invalidation issue
docs(api): update API documentation with new endpoints
test(scanner): add integration tests for parallel processing
```

## 🐛 Bug Reports

### Before Submitting

1. Check existing issues
2. Search documentation
3. Try latest version
4. Create minimal reproduction

### Bug Report Template

```markdown
**Bug Description**
A clear and concise description of the bug.

**To Reproduce**
Steps to reproduce the behavior:
1. Run command '...'
2. With configuration '...'
3. See error

**Expected Behavior**
What you expected to happen.

**Actual Behavior**
What actually happened.

**Environment**
- OS: [e.g. Ubuntu 20.04]
- PHP Version: [e.g. 8.1.0]
- M2-Quality Version: [e.g. 2.0.0]
- Magento Version: [e.g. 2.4.6]

**Configuration**
```json
{
  "analyzers": ["phpcs", "security"],
  "rules": {"severity": "warning"}
}
```

**Additional Context**
Any other context about the problem.
```

## ✨ Feature Requests

### Feature Request Template

```markdown
**Is your feature request related to a problem?**
A clear description of what the problem is.

**Describe the solution you'd like**
A clear description of what you want to happen.

**Describe alternatives you've considered**
Any alternative solutions or features you've considered.

**Use Cases**
Specific use cases where this feature would be helpful.

**Additional context**
Any other context or screenshots about the feature request.
```

## 🔧 Code Contributions

### Pull Request Process

1. **Preparation**
   - Ensure your fork is up to date
   - Create feature branch
   - Follow coding standards

2. **Development**
   - Write clean, documented code
   - Add comprehensive tests
   - Update documentation

3. **Testing**
   ```bash
   # Run full test suite
   php run-tests.php
   
   # Check code style
   composer check-cs
   
   # Run static analysis
   composer phpstan
   ```

4. **Documentation**
   - Update relevant documentation
   - Add examples if needed
   - Update CHANGELOG.md

5. **Submit PR**
   - Use descriptive title
   - Fill out PR template
   - Link related issues

### Pull Request Template

```markdown
## Description
Brief description of the changes.

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Related Issues
Closes #[issue-number]

## Testing
- [ ] Tests pass locally
- [ ] New tests added
- [ ] Documentation updated

## Checklist
- [ ] Code follows style guidelines
- [ ] Self-review completed
- [ ] Comments added to complex code
- [ ] Documentation updated
- [ ] Tests added/updated
- [ ] No breaking changes (or documented)
```

## 🧪 Testing Guidelines

### Test Categories

1. **Unit Tests** - Test individual components
2. **Integration Tests** - Test component interactions
3. **Functional Tests** - Test end-to-end functionality
4. **Performance Tests** - Test performance characteristics

### Writing Tests

```php
<?php

namespace M2Quality\Tests\Unit\Analyzers;

use PHPUnit\Framework\TestCase;
use M2Quality\Analyzers\SecurityAnalyzer;

class SecurityAnalyzerTest extends TestCase
{
    /**
     * @test
     * @group unit
     * @group analyzers
     */
    public function testDetectsSQLInjection(): void
    {
        $analyzer = new SecurityAnalyzer();
        $code = '<?php $query = "SELECT * FROM users WHERE id = " . $_GET["id"];';
        
        $violations = $analyzer->analyzeCode($code);
        
        $this->assertCount(1, $violations);
        $this->assertEquals('sql-injection', $violations[0]->getRule());
    }
}
```

### Test Naming

- Test class: `{ClassBeingTested}Test`
- Test method: `test{WhatIsBeingTested}`
- Use descriptive names
- Group related tests

## 📚 Documentation

### Documentation Types

1. **API Documentation** - Code-level documentation
2. **User Documentation** - How-to guides and tutorials
3. **Developer Documentation** - Contributing and extending
4. **Examples** - Practical usage examples

### Documentation Standards

- Use clear, concise language
- Include practical examples
- Keep documentation up to date
- Add screenshots for UI features

### Documentation Structure

```
docs/
├── installation.md      # Installation guide
├── configuration.md     # Configuration reference
├── usage.md            # Usage examples
├── api.md              # API documentation
├── extending.md        # Extension development
└── troubleshooting.md  # Common issues
```

## 🏷️ Release Process

### Versioning

We use [Semantic Versioning](https://semver.org/):

- `MAJOR.MINOR.PATCH`
- `MAJOR`: Breaking changes
- `MINOR`: New features (backward compatible)
- `PATCH`: Bug fixes (backward compatible)

### Release Checklist

1. Update version numbers
2. Update CHANGELOG.md
3. Run full test suite
4. Update documentation
5. Create release notes
6. Tag release
7. Publish packages

## 🎯 Development Focus Areas

### High Priority

- **Performance Optimization** - Improve analysis speed
- **AI Integration** - Enhance AI capabilities
- **Magento Compatibility** - Support latest Magento versions
- **Test Coverage** - Increase test coverage

### Medium Priority

- **New Analyzers** - Add specialized analyzers
- **Report Formats** - Support additional report formats
- **IDE Integration** - Better IDE support
- **Documentation** - Improve documentation

### Community Requests

Check our [Issues](https://github.com/m2quality/m2-quality/issues) and [Discussions](https://github.com/m2quality/m2-quality/discussions) for community-requested features.

## 📞 Communication

### Channels

- **GitHub Issues** - Bug reports, feature requests
- **GitHub Discussions** - Questions, ideas, general discussion
- **Slack** - Real-time chat with maintainers and community
- **Email** - Security issues: security@m2quality.dev

### Code of Conduct

Please read our [Code of Conduct](CODE_OF_CONDUCT.md) before participating.

## 🏆 Recognition

Contributors are recognized in:

- Release notes
- CONTRIBUTORS.md file
- GitHub contributors page
- Special thanks in documentation

## 🔒 Security

### Reporting Security Issues

**Do not** report security vulnerabilities through public GitHub issues.

Instead, email us at: security@m2quality.dev

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### Security Response

1. Acknowledge receipt within 24 hours
2. Investigate and assess impact
3. Develop and test fix
4. Coordinate disclosure
5. Release security update

## 📝 License

By contributing to M2-Quality, you agree that your contributions will be licensed under the MIT License.

---

**Thank you for contributing to M2-Quality!** 🚀

Your contributions help make code quality analysis better for the entire Magento community. 