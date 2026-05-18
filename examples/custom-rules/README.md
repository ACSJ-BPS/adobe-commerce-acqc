# Custom Rules Examples

This directory contains examples of custom analyzers, rules, and plugins for M2-Quality.

## 📁 Directory Structure

```
examples/custom-rules/
├── analyzers/              # Custom analyzer examples
│   └── CustomSecurityAnalyzer.php
├── rules/                  # Custom rule examples
│   └── CompanyNamingRule.php
├── plugins/                # Custom plugin examples
│   └── SlackNotificationPlugin.php
└── README.md              # This file
```

## 🔍 Custom Analyzers

### CustomSecurityAnalyzer.php

Example security analyzer that demonstrates:
- Hardcoded API key detection
- eval() usage detection
- Dynamic file inclusion vulnerabilities
- Unescaped template output detection

**Usage:**
```json
{
  "analyzers": ["custom-security"],
  "analyzers_config": {
    "custom-security": {
      "class": "Example\\CustomRules\\Analyzers\\CustomSecurityAnalyzer"
    }
  }
}
```

## 📋 Custom Rules

### CompanyNamingRule.php

Example naming convention rule that enforces:
- Class prefix requirements
- Interface suffix requirements
- Method naming conventions (camelCase)
- Constant naming (UPPER_SNAKE_CASE)

**Configuration:**
```json
{
  "custom_rules": [
    {
      "class": "Example\\CustomRules\\Rules\\CompanyNamingRule",
      "config": {
        "class_prefix_required": true,
        "company_prefix": "MyCompany",
        "interface_suffix": "Interface",
        "method_naming": "camelCase"
      }
    }
  ]
}
```

## 🔌 Custom Plugins

### SlackNotificationPlugin.php

Example plugin that sends notifications to Slack:
- Scan start notifications
- Scan completion with results
- Critical issue alerts

**Configuration:**
```json
{
  "plugins": [
    {
      "class": "Example\\CustomRules\\Plugins\\SlackNotificationPlugin",
      "config": {
        "webhook_url": "https://hooks.slack.com/...",
        "notify_on_start": false,
        "always_notify": false
      }
    }
  ]
}
```

## 🚀 Getting Started

1. **Copy Examples**: Copy the example files to your project
2. **Modify Namespace**: Update namespaces to match your project
3. **Customize Logic**: Modify the detection logic for your needs
4. **Register Components**: Add to your M2-Quality configuration
5. **Test**: Run analysis to verify functionality

## 💡 Development Tips

### Creating Custom Analyzers

```php
class MyAnalyzer extends AbstractAnalyzer
{
    public function getName(): string
    {
        return 'my-analyzer';
    }
    
    public function analyze(ScanContext $context): ScanResult
    {
        // Your analysis logic here
        return new ScanResult($violations, true, $metrics);
    }
}
```

### Creating Custom Rules

```php
class MyRule extends AbstractRule
{
    public function check(string $filePath, string $content): array
    {
        // Your rule logic here
        return $violations;
    }
}
```

### Creating Custom Plugins

```php
class MyPlugin implements PluginInterface
{
    public function beforeScan(ScanContext $context): void
    {
        // Pre-scan actions
    }
    
    public function afterScan(ScanResult $result): void
    {
        // Post-scan actions
    }
}
```

## 📚 Best Practices

1. **Performance**: Keep analysis logic efficient
2. **Error Handling**: Handle edge cases gracefully
3. **Configuration**: Make components configurable
4. **Testing**: Write unit tests for custom components
5. **Documentation**: Document your custom rules clearly

## 🧪 Testing Custom Components

```bash
# Test custom analyzer
m2-quality scan . --analyzers=my-analyzer --debug

# Test custom rules
m2-quality scan . --rules=my-rule --verbose

# Validate configuration
m2-quality config validate
```

## 📖 Additional Resources

- [M2-Quality Extending Guide](../../docs/extending.md)
- [API Documentation](../../docs/api.md)
- [Main Documentation](../../README.md) 