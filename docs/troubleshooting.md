# Troubleshooting Guide

Common issues, solutions, and debugging tips for M2-Quality.

## 🔧 Installation Issues

### PHP Version Compatibility

**Problem**: "This package requires php >=8.1"

**Solution**:
```bash
# Check PHP version
php --version

# Install PHP 8.1+ (Ubuntu)
sudo apt install php8.1-cli

# Switch PHP version
sudo update-alternatives --config php
```

### Memory Limit Issues

**Problem**: "Fatal error: Allowed memory size exhausted"

**Solution**:
```bash
# Increase memory limit temporarily
php -d memory_limit=512M vendor/bin/m2-quality scan .

# Permanently increase limit
echo 'memory_limit = 512M' | sudo tee /etc/php/8.1/cli/conf.d/99-memory.ini

# Use memory optimization
m2-quality scan . --memory-optimize --batch-size=25
```

### Permission Issues

**Problem**: "Permission denied" when running commands

**Solution**:
```bash
# Fix Composer permissions
sudo chown -R $USER:$USER ~/.composer

# Fix binary permissions
chmod +x ~/.composer/vendor/bin/m2-quality

# Check file permissions
ls -la ~/.composer/vendor/bin/m2-quality
```

### Missing Extensions

**Problem**: "Extension 'json' is missing"

**Solution**:
```bash
# Install required PHP extensions (Ubuntu)
sudo apt install php8.1-json php8.1-mbstring php8.1-xml php8.1-curl php8.1-zip

# Install on CentOS/RHEL
sudo yum install php-json php-mbstring php-xml php-curl php-zip

# Verify extensions
php -m | grep -E "(json|mbstring|xml|curl|zip)"
```

## ⚙️ Configuration Issues

### Invalid Configuration

**Problem**: "Configuration validation failed"

**Solution**:
```bash
# Validate configuration
m2-quality config validate --verbose

# Show current configuration
m2-quality config show

# Reset to defaults
m2-quality config reset --backup

# Fix common issues
m2-quality config set analyzers.phpcs.standard "Magento2,PSR12"
m2-quality config set rules.severity warning
```

### Profile Not Found

**Problem**: "Profile 'custom' not found"

**Solution**:
```bash
# List available profiles
m2-quality config list-profiles

# Create custom profile
m2-quality config create-profile custom

# Use default profile
m2-quality config set-profile developer

# Check profile location
ls ~/.m2quality/profiles/
```

### Environment Variables

**Problem**: Environment variables not loaded

**Solution**:
```bash
# Check environment variables
env | grep M2QUALITY

# Set required variables
export OPENAI_API_KEY="your-api-key"
export M2QUALITY_CONFIG_FILE="/path/to/config.json"

# Use .env file
echo "OPENAI_API_KEY=your-key" > .env
m2-quality scan . --env-file=.env
```

## 🔍 Analysis Issues

### No Files Found

**Problem**: "No files found to analyze"

**Solution**:
```bash
# Check file patterns
m2-quality scan . --include="*.php,*.phtml" --verbose

# Verify path exists
ls -la /path/to/scan

# Check exclude patterns
m2-quality config get rules.exclude_patterns

# Debug file discovery
m2-quality scan . --dry-run --verbose
```

### Analyzer Failures

**Problem**: "PHPCS analyzer failed"

**Solution**:
```bash
# Check PHPCS installation
which phpcs
phpcs --version

# Install PHPCS globally
composer global require squizlabs/php_codesniffer

# Configure PHPCS standards
phpcs --config-set installed_paths ~/.composer/vendor/magento/magento-coding-standard

# Test specific analyzer
m2-quality scan . --analyzers=phpcs --verbose
```

### Slow Performance

**Problem**: Analysis takes too long

**Solution**:
```bash
# Enable parallel processing
m2-quality scan . --parallel=8

# Use incremental scanning
m2-quality scan . --incremental

# Enable caching
m2-quality scan . --cache=aggressive

# Exclude unnecessary files
m2-quality scan . --exclude="vendor/*,var/*,pub/*"

# Optimize memory usage
m2-quality scan . --memory-optimize --batch-size=50
```

## 🤖 AI Integration Issues

### API Connection Errors

**Problem**: "OpenAI API connection failed"

**Solution**:
```bash
# Verify API key
echo $OPENAI_API_KEY

# Test API connection
curl -H "Authorization: Bearer $OPENAI_API_KEY" \
     https://api.openai.com/v1/models

# Check proxy settings
export https_proxy=http://proxy:8080

# Use different provider
m2-quality config set ai.provider claude
m2-quality config set ai.provider local
```

### Rate Limiting

**Problem**: "Rate limit exceeded"

**Solution**:
```bash
# Reduce AI requests
m2-quality scan . --no-ai

# Use caching for AI responses
m2-quality config set ai.cache_responses true

# Increase delay between requests
m2-quality config set ai.request_delay 1000

# Use local AI provider
m2-quality config set ai.provider local
```

### Invalid AI Responses

**Problem**: AI responses are garbled or incomplete

**Solution**:
```bash
# Adjust AI parameters
m2-quality config set ai.temperature 0.3
m2-quality config set ai.max_tokens 2000

# Use different model
m2-quality config set ai.model gpt-4

# Enable debug mode
m2-quality ask "test question" --debug

# Clear AI cache
m2-quality cache:clear --ai-only
```

## 📊 Reporting Issues

### Report Generation Failures

**Problem**: "Failed to generate PDF report"

**Solution**:
```bash
# Install PDF dependencies
composer require tecnickcom/tcpdf

# Check write permissions
ls -la reports/
chmod 755 reports/

# Use alternative format
m2-quality report --format=html

# Generate with minimal options
m2-quality report --format=pdf --template=simple
```

### Empty Reports

**Problem**: Reports are generated but contain no data

**Solution**:
```bash
# Check if violations exist
m2-quality scan . --format=json | jq '.violations | length'

# Verify severity filter
m2-quality report --severity=info

# Check date range
m2-quality report --from=2023-01-01

# Debug report generation
m2-quality report --format=json --verbose
```

### Chart Rendering Issues

**Problem**: Charts not displaying in reports

**Solution**:
```bash
# Install chart dependencies
npm install chart.js

# Disable charts temporarily
m2-quality report --no-charts

# Use alternative chart library
m2-quality config set reporting.chart_library plotly

# Check JavaScript console for errors (browser)
```

## 🚀 Performance Issues

### High Memory Usage

**Problem**: Process runs out of memory

**Solution**:
```bash
# Monitor memory usage
m2-quality scan . --memory-monitor

# Increase PHP memory limit
php -d memory_limit=1G vendor/bin/m2-quality scan .

# Use memory optimization
m2-quality scan . --memory-optimize

# Process in smaller batches
m2-quality scan . --batch-size=10

# Enable garbage collection
m2-quality config set performance.force_gc true
```

### Slow File Processing

**Problem**: Individual files take too long to process

**Solution**:
```bash
# Enable parallel processing
m2-quality scan . --parallel=max

# Skip large files
m2-quality scan . --max-file-size=1M

# Use faster analyzers only
m2-quality scan . --analyzers=phpcs

# Profile performance
m2-quality scan . --profile-performance
```

### Cache Issues

**Problem**: Cache not working or corrupted

**Solution**:
```bash
# Clear all caches
m2-quality cache:clear

# Rebuild cache
m2-quality cache:rebuild

# Disable cache temporarily
m2-quality scan . --no-cache

# Check cache directory permissions
ls -la ~/.m2quality/cache/

# Use different cache backend
m2-quality config set performance.cache_backend redis
```

## 🔗 Integration Issues

### Git Integration

**Problem**: Git hooks not working

**Solution**:
```bash
# Check git hooks
ls -la .git/hooks/

# Reinstall hooks
m2-quality git:install-hooks --force

# Test hook manually
.git/hooks/pre-commit

# Check hook permissions
chmod +x .git/hooks/pre-commit

# Debug hook execution
#!/bin/bash
set -x
m2-quality scan --staged-files
```

### CI/CD Issues

**Problem**: CI pipeline fails

**Solution**:
```bash
# Check exit codes
echo $?

# Use non-blocking mode
m2-quality scan . --no-exit-code

# Generate CI-friendly output
m2-quality scan . --format=junit

# Set appropriate thresholds
m2-quality scan . --fail-on=critical

# Example CI configuration fix
m2-quality scan . \
  --format=github-actions \
  --baseline=ci-baseline.json \
  --severity=error
```

### Docker Issues

**Problem**: Docker container fails

**Solution**:
```bash
# Check container logs
docker logs m2quality-container

# Mount volumes correctly
docker run -v $(pwd):/app m2quality/m2-quality scan /app

# Use correct user permissions
docker run --user $(id -u):$(id -g) m2quality/m2-quality

# Debug container
docker run -it --entrypoint=/bin/bash m2quality/m2-quality

# Build custom image
FROM m2quality/m2-quality
RUN composer install --no-dev
```

## 🐛 Debugging

### Enable Debug Mode

```bash
# Global debug mode
export M2QUALITY_DEBUG=true

# Command-specific debug
m2-quality scan . --debug --verbose

# Log to file
m2-quality scan . --log-file=debug.log --log-level=debug

# Trace execution
m2-quality scan . --trace
```

### Diagnostic Commands

```bash
# System information
m2-quality system:info

# Check dependencies
m2-quality system:check

# Validate installation
m2-quality system:validate

# Environment report
m2-quality system:env > system-report.txt
```

### Log Analysis

```bash
# View recent logs
tail -f ~/.m2quality/logs/m2quality.log

# Search for errors
grep -i error ~/.m2quality/logs/m2quality.log

# Analyze performance logs
grep -i "slow\|timeout\|memory" ~/.m2quality/logs/m2quality.log

# Filter by date
grep "2023-12-15" ~/.m2quality/logs/m2quality.log
```

## 🆘 Getting Help

### Self-Diagnosis

```bash
# Run diagnostic
m2-quality diagnose

# Generate support bundle
m2-quality support:bundle

# System health check
m2-quality health:check

# Configuration audit
m2-quality config:audit
```

### Information to Provide

When reporting issues, include:

1. **System Information**:
   ```bash
   php --version
   composer --version
   m2-quality --version
   uname -a
   ```

2. **Configuration**:
   ```bash
   m2-quality config show
   ```

3. **Error Details**:
   ```bash
   m2-quality scan . --debug --verbose 2>&1 | tee error.log
   ```

4. **Environment**:
   ```bash
   env | grep M2QUALITY
   ```

### Common Error Codes

| Exit Code | Meaning | Solution |
|-----------|---------|----------|
| 0 | Success | No action needed |
| 1 | Issues found | Review violations |
| 2 | Critical issues | Fix critical problems |
| 3 | Configuration error | Check configuration |
| 4 | Runtime error | Check logs, file permissions |
| 125 | Command not found | Check installation |
| 126 | Permission denied | Fix permissions |
| 127 | Command not executable | Check PATH, permissions |

### Support Channels

1. **Documentation**: [docs.m2quality.dev](https://docs.m2quality.dev)
2. **GitHub Issues**: [Report bugs](https://github.com/m2quality/m2-quality/issues)
3. **Discussions**: [Community help](https://github.com/m2quality/m2-quality/discussions)
4. **Slack**: [Real-time chat](https://m2quality.slack.com)

### Creating Minimal Reproducible Examples

```bash
# Create test case
mkdir m2quality-debug
cd m2quality-debug

# Minimal configuration
cat > m2quality.json << 'EOF'
{
  "analyzers": ["phpcs"],
  "rules": {"severity": "warning"}
}
EOF

# Test file
cat > test.php << 'EOF'
<?php
class Test {
    public function method() {
        return true;
    }
}
EOF

# Run with debug
m2-quality scan . --debug --verbose
```

---

**For additional help**: Visit our [documentation](../README.md) or reach out through our [support channels](https://docs.m2quality.dev/support). 