# M2-Quality Rule Sets

This directory contains comprehensive rule sets for different aspects of code quality analysis.

## 📋 Available Rule Sets

### 🏗️ Core Rules

**File**: `magento2-core.yaml`  
**Focus**: Essential Magento 2 development practices  
**Categories**: 
- Dependency Injection
- Plugin Development
- Observer Patterns
- Module Structure
- API Usage
- Template Development

**Key Rules**:
- No direct ObjectManager usage
- Proper dependency injection
- Plugin naming conventions
- Observer implementation
- Template output escaping

### ⚡ Performance Rules

**File**: `performance.yaml`  
**Focus**: Performance optimization and efficiency  
**Categories**:
- Database Performance
- Caching Strategy
- Memory Usage
- Algorithm Efficiency
- I/O Operations
- Frontend Performance

**Key Rules**:
- Avoid N+1 query problems
- Implement result caching
- Optimize collection loading
- Minimize DOM manipulations
- Use efficient algorithms

### 🛡️ Security Rules

**File**: `security.yaml`  
**Focus**: Security vulnerability detection and prevention  
**Categories**:
- Injection Vulnerabilities
- Cross-Site Scripting (XSS)
- CSRF Protection
- Authentication & Authorization
- Cryptography
- Input Validation

**Key Rules**:
- Prevent SQL injection
- Escape template output
- Require CSRF protection
- Use strong cryptography
- Validate all inputs

### 🎨 Frontend Rules

**File**: `frontend.yaml`  
**Focus**: Frontend development quality and standards  
**Categories**:
- JavaScript Quality
- CSS/SCSS Quality
- HTML Structure
- Web Accessibility (WCAG)
- SEO Optimization
- Progressive Web App

**Key Rules**:
- Avoid global variables
- Use efficient CSS selectors
- Provide alt text for images
- Ensure keyboard navigation
- Optimize images for performance

## 🎯 Usage Examples

### Using Single Rule Set

```bash
# Apply core Magento 2 rules
m2-quality scan . --rules=config/rules/magento2-core.yaml

# Security-focused scan
m2-quality scan . --rules=config/rules/security.yaml

# Performance analysis
m2-quality scan . --rules=config/rules/performance.yaml
```

### Combining Rule Sets

```json
{
  "extends": [
    "config/rules/magento2-core.yaml",
    "config/rules/security.yaml",
    "config/rules/performance.yaml"
  ]
}
```

### Role-Based Rule Selection

```bash
# Developer profile (core + basic security)
m2-quality scan . --profile=developer

# Architect profile (all rules)
m2-quality scan . --profile=architect

# Security audit (security + core rules)
m2-quality scan . --profile=security
```

## ⚙️ Configuration

### Severity Levels

Each rule set defines severity levels:
- **Critical**: Must fix immediately
- **High/Error**: Should fix before release
- **Medium/Warning**: Should fix when possible
- **Low/Info**: Consider fixing for best practices

### Customizing Rules

Override rule severity in your configuration:

```json
{
  "rules": {
    "severity_overrides": {
      "no_object_manager_direct_usage": "critical",
      "plugin_naming_convention": "info"
    }
  }
}
```

### Excluding Rules

Disable specific rules:

```json
{
  "rules": {
    "disabled_rules": [
      "avoid_global_variables",
      "use_strict_mode"
    ]
  }
}
```

## 📊 Rule Categories

### By Technology
- **PHP**: Object-oriented practices, security, performance
- **JavaScript**: ES6+ standards, performance, best practices
- **CSS**: Efficiency, organization, responsiveness
- **HTML**: Semantics, accessibility, SEO
- **Magento**: Framework-specific patterns and practices

### By Concern
- **Security**: Vulnerability prevention and detection
- **Performance**: Speed and efficiency optimization
- **Maintainability**: Code organization and clarity
- **Accessibility**: WCAG compliance and inclusive design
- **Standards**: Coding standards and conventions

## 🔧 Extending Rules

### Adding Custom Rules

1. Create rule file in this directory
2. Define rule categories and severity levels
3. Implement rule logic with examples
4. Add to configuration extends list

### Rule Format

```yaml
name: "Custom Rule Set"
description: "Description of rule set purpose"
version: "1.0.0"

categories:
  custom_category: "Category Description"

rules:
  custom_rule:
    name: "Rule Name"
    category: custom_category
    severity: warning
    description: "What this rule checks"
    examples:
      bad: ["example of violation"]
      good: ["example of compliance"]
    fix_suggestion: "How to fix violations"
```

## 📈 Performance Budgets

Some rule sets include performance budgets:

```yaml
performance_budgets:
  total_page_size_kb: 1000
  javascript_size_kb: 200
  css_size_kb: 100
  execution_time_seconds: 2.0
```

## 🧪 Testing Rules

```bash
# Validate rule syntax
m2-quality config validate --rules=config/rules/custom.yaml

# Test rule effectiveness
m2-quality scan test-files/ --rules=config/rules/custom.yaml --verbose

# Benchmark rule performance
m2-quality benchmark --rules=config/rules/custom.yaml
```

## 📚 Best Practices

1. **Layered Approach**: Start with core rules, add specialized rules
2. **Team Consensus**: Ensure team agrees on rule severity levels
3. **Gradual Adoption**: Introduce strict rules incrementally
4. **Regular Review**: Update rules based on team feedback
5. **Documentation**: Document custom rules and their rationale

## 🔗 Integration

### CI/CD Pipeline

```yaml
# Example CI configuration
quality_check:
  script:
    - m2-quality scan . --rules=config/rules/production.yaml --fail-on=error
```

### IDE Integration

Configure your IDE to use these rule sets for real-time feedback:

```json
{
  "m2quality.rules": "config/rules/developer.yaml",
  "m2quality.severity": "warning"
}
```

## 📖 Related Documentation

- [Configuration Guide](../docs/configuration.md)
- [Usage Examples](../docs/usage.md)
- [Extending M2-Quality](../docs/extending.md)
- [Custom Rule Development](../examples/custom-rules/README.md) 