# Usage Guide

Comprehensive usage guide for M2-Quality with practical examples, tutorials, and best practices for all features.

## 🚀 Getting Started

### First Scan

```bash
# Quick start - scan current directory
m2-quality scan .

# Scan specific directory
m2-quality scan /path/to/magento/project

# Scan with specific analyzers
m2-quality scan . --analyzers=phpcs,security,magento

# Scan with detailed output
m2-quality scan . --verbose --progress
```

### Understanding Output

```
🔍 M2-Quality Analysis Results
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📈 Quality Score: 8.5/10 (Good)
📁 Files Scanned: 247
⚠️ Issues Found: 23
🛡️ Security Issues: 2 (High Priority)
⚡ Performance Issues: 5
🎨 Code Style Issues: 16

🔥 Critical Issues:
  app/code/Vendor/Module/Model/Data.php:45 - SQL Injection Risk
  app/code/Vendor/Module/Controller/Save.php:67 - XSS Vulnerability

⚠️ High Priority Issues:
  app/code/Vendor/Module/Helper/Data.php:23 - ObjectManager Direct Usage
  app/code/Vendor/Module/Block/View.php:89 - Missing Type Hint

💡 Recommendations:
  • Implement dependency injection instead of ObjectManager
  • Add type hints for better code clarity
  • Use parameterized queries for database operations
```

## 📊 Core Commands

### 1. Scan Command

The main analysis command with comprehensive options:

```bash
# Basic scanning
m2-quality scan [path] [options]

# Examples:
m2-quality scan .                           # Current directory
m2-quality scan app/code/                   # Specific path
m2-quality scan . --format=json            # JSON output
m2-quality scan . --role=architect         # Role-based analysis
m2-quality scan . --severity=error         # Only errors
m2-quality scan . --parallel=8             # Parallel processing
m2-quality scan . --cache=false            # Disable caching
```

#### Advanced Scan Options

```bash
# Output formats
m2-quality scan . --format=html --output=report.html
m2-quality scan . --format=json --output=results.json
m2-quality scan . --format=xml --output=report.xml

# Role-based scanning
m2-quality scan . --role=developer         # Developer perspective
m2-quality scan . --role=lead             # Team lead perspective
m2-quality scan . --role=architect        # Architect perspective
m2-quality scan . --role=security         # Security focus

# Analyzer selection
m2-quality scan . --analyzers=phpcs,phpmd,security
m2-quality scan . --exclude-analyzers=frontend
m2-quality scan . --enable-all-analyzers

# Filtering options
m2-quality scan . --severity=error,critical
m2-quality scan . --include="*.php,*.phtml"
m2-quality scan . --exclude="vendor/*,var/*"

# Performance options
m2-quality scan . --parallel=4             # Use 4 processes
m2-quality scan . --memory-limit=512M      # Set memory limit
m2-quality scan . --timeout=300            # 5 minute timeout
m2-quality scan . --no-cache              # Disable caching
```

### 2. Report Generation

Generate comprehensive quality reports:

```bash
# Basic report generation
m2-quality report [options]

# Different formats
m2-quality report --format=html            # Interactive HTML report
m2-quality report --format=pdf             # PDF report
m2-quality report --format=excel           # Excel spreadsheet
m2-quality report --format=json            # Machine-readable JSON

# Report types
m2-quality report --type=summary           # Executive summary
m2-quality report --type=detailed          # Detailed analysis
m2-quality report --type=security          # Security-focused
m2-quality report --type=performance       # Performance analysis
m2-quality report --type=trends            # Historical trends
```

#### Advanced Reporting

```bash
# Custom reports
m2-quality report \
    --format=html \
    --template=corporate \
    --include-charts \
    --include-trends \
    --include-recommendations \
    --output=reports/quality-report-$(date +%Y%m%d).html

# Team reports
m2-quality report \
    --type=team \
    --team-members="dev1,dev2,dev3" \
    --include-individual-stats \
    --format=excel

# Baseline comparison
m2-quality report \
    --baseline=baseline.json \
    --show-improvements \
    --show-regressions \
    --format=html
```

### 3. Baseline Management

Track quality changes over time:

```bash
# Create baseline
m2-quality baseline create production-v1.0

# Update baseline
m2-quality baseline update production-v1.0

# Compare with baseline
m2-quality scan . --baseline=production-v1.0

# List baselines
m2-quality baseline list

# Show baseline details
m2-quality baseline show production-v1.0

# Delete baseline
m2-quality baseline delete old-baseline
```

#### Baseline Workflows

```bash
# Pre-release baseline
m2-quality baseline create pre-release-$(date +%Y%m%d)

# Compare current state
m2-quality scan . --baseline=pre-release-20231215 --show-diff

# Export baseline for CI
m2-quality baseline export production --format=json > ci-baseline.json
```

### 4. Configuration Management

Manage tool configuration:

```bash
# Initialize configuration
m2-quality config init                     # Interactive setup
m2-quality config init --defaults          # Use defaults
m2-quality config init --profile=developer # Use profile

# View configuration
m2-quality config show                     # Show all config
m2-quality config get analyzers            # Get specific value
m2-quality config get rules.severity       # Nested value

# Modify configuration
m2-quality config set rules.severity error
m2-quality config set analyzers.phpcs.standard "Magento2,PSR12"
m2-quality config unset ai.api_key

# Profile management
m2-quality config set-profile architect
m2-quality config list-profiles
m2-quality config create-profile custom-profile

# Validation
m2-quality config validate
m2-quality config check
```

## 🤖 AI-Powered Features

### 1. Ask Command - Natural Language Queries

Ask questions about your code in plain English:

```bash
# Basic questions
m2-quality ask "How is my code quality?"
m2-quality ask "What security issues should I fix first?"
m2-quality ask "How can I improve performance?"

# Specific questions
m2-quality ask "Explain the violation in UserController.php line 45"
m2-quality ask "What design patterns should I use for this module?"
m2-quality ask "How can I make this code more testable?"

# Role-specific perspectives
m2-quality ask "What should I focus on as a team lead?" --role=lead
m2-quality ask "Architecture review recommendations" --role=architect
m2-quality ask "Security audit priorities" --role=security

# Interactive mode
m2-quality ask --interactive
```

#### Advanced Ask Features

```bash
# Include context
m2-quality ask "Optimize this checkout process" \
    --include-code \
    --include-metrics \
    --depth=deep

# Save conversations
m2-quality ask "Code review suggestions" --save=review-$(date +%Y%m%d).json

# Different output formats
m2-quality ask "Performance analysis" --format=markdown > analysis.md
m2-quality ask "Security review" --format=html > security-report.html
```

### 2. Explain Command - Code Explanations

Get detailed explanations of code, violations, and patterns:

```bash
# Explain files
m2-quality explain app/code/Vendor/Module/Model/User.php

# Explain specific functions
m2-quality explain app/code/Vendor/Module/Helper/Data.php --function=processData

# Explain violations
m2-quality explain --violation-id=PHPCS.M2.ObjectManager.DirectUsage

# Explain patterns
m2-quality explain observer-pattern --type=pattern

# Different explanation depths
m2-quality explain UserModel.php --depth=basic        # High-level overview
m2-quality explain UserModel.php --depth=intermediate # Detailed explanation
m2-quality explain UserModel.php --depth=advanced     # Comprehensive analysis
```

#### Explain Examples

```bash
# Explain with examples
m2-quality explain dependency-injection \
    --include-examples \
    --include-best-practices \
    --level=intermediate

# Explain security issue
m2-quality explain app/code/Vendor/Module/Controller/Save.php \
    --line=67 \
    --type=security \
    --include-fix-suggestions

# Interactive explanations
m2-quality explain Observer.php --interactive
```

### 3. Suggest Command - Improvement Suggestions

Get intelligent suggestions for code improvements:

```bash
# File-level suggestions
m2-quality suggest app/code/Vendor/Module/Model/User.php

# Project-level suggestions
m2-quality suggest . --scope=project

# Performance suggestions
m2-quality suggest . --focus=performance

# Security suggestions
m2-quality suggest . --focus=security

# Architecture suggestions
m2-quality suggest . --focus=architecture --role=architect
```

#### Suggestion Examples

```bash
# Detailed suggestions with examples
m2-quality suggest app/code/Vendor/Module/ \
    --include-examples \
    --include-impact-analysis \
    --priority=high

# Team-specific suggestions
m2-quality suggest . \
    --team-level=intermediate \
    --focus=maintainability \
    --format=markdown
```

### 4. Predict Command - Quality Predictions

Predictive analysis for quality trends:

```bash
# Quality predictions
m2-quality predict quality --timeframe=1month

# Technical debt predictions
m2-quality predict debt --scope=project

# Performance predictions
m2-quality predict performance --focus=checkout

# Risk assessments
m2-quality predict risk --severity=critical
```

### 5. Learn Command - Learning Assistant

Personalized learning recommendations:

```bash
# Learning paths
m2-quality learn magento2-basics
m2-quality learn design-patterns
m2-quality learn security-best-practices

# Skill assessments
m2-quality learn --assess-skills

# Interactive tutorials
m2-quality learn dependency-injection --interactive

# Progress tracking
m2-quality learn --show-progress
```

### 6. Mentor Command - Code Mentoring

Get mentoring and guidance:

```bash
# Code mentoring
m2-quality mentor --level=junior
m2-quality mentor --level=senior --focus=architecture

# Code review assistance
m2-quality mentor code-review app/code/Vendor/Module/

# Career guidance
m2-quality mentor career-path --current-level=mid --target=senior
```

## 📊 Dashboard and Monitoring

### Interactive Dashboard

Launch the React-based dashboard:

```bash
# Start dashboard
m2-quality dashboard

# Custom port and host
m2-quality dashboard --port=8080 --host=0.0.0.0

# Development mode
m2-quality dashboard --dev --auto-reload

# Secure mode
m2-quality dashboard --auth --ssl
```

### Real-time Monitoring

```bash
# Monitor file changes
m2-quality monitor . --watch

# Continuous integration monitoring
m2-quality monitor . --ci-mode --webhook=http://ci-server/webhook

# Performance monitoring
m2-quality monitor . --performance --alert-threshold=5s
```

## 🔧 Advanced Usage

### 1. Custom Analyzers

Create and use custom analyzers:

```bash
# Generate analyzer template
m2-quality generate:analyzer CustomAnalyzer

# Register custom analyzer
m2-quality config set analyzers.custom.class "App\\Analyzers\\CustomAnalyzer"

# Use custom analyzer
m2-quality scan . --analyzers=custom
```

### 2. Performance Optimization

Optimize analysis performance:

```bash
# Enable all optimizations
m2-quality scan . \
    --parallel=8 \
    --cache=aggressive \
    --incremental \
    --memory-optimize

# Cache warm-up
m2-quality cache:warmup app/code/

# Performance profiling
m2-quality scan . --profile-performance

# Benchmark analysis
m2-quality benchmark --iterations=100
```

### 3. Integration Workflows

#### Git Hooks Integration

```bash
# Install git hooks
m2-quality git:install-hooks

# Pre-commit hook
#!/bin/bash
m2-quality scan --staged-files --severity=error --format=console --exit-code

# Pre-push hook
#!/bin/bash
m2-quality scan . --baseline=main --fail-on-regression
```

#### CI/CD Integration

```yaml
# .github/workflows/quality.yml
name: Code Quality
on: [push, pull_request]
jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
      - name: Install dependencies
        run: composer install
      - name: Run M2-Quality
        run: |
          composer global require m2quality/m2-quality
          m2-quality scan . \
            --format=github-actions \
            --baseline=ci-baseline.json \
            --fail-on=error
      - name: Upload reports
        uses: actions/upload-artifact@v2
        with:
          name: quality-reports
          path: reports/
```

#### Docker Integration

```bash
# Docker scan
docker run --rm -v $(pwd):/app m2quality/m2-quality scan /app

# Docker compose
version: '3.8'
services:
  m2-quality:
    image: m2quality/m2-quality
    volumes:
      - .:/app
      - ./reports:/reports
    command: scan /app --output-dir=/reports
```

## 📋 Workflow Examples

### 1. Daily Development Workflow

```bash
#!/bin/bash
# daily-quality-check.sh

echo "🌅 Starting daily quality check..."

# Quick scan of changed files
CHANGED_FILES=$(git diff --name-only HEAD~1)
if [ ! -z "$CHANGED_FILES" ]; then
    echo "📁 Scanning changed files..."
    m2-quality scan $CHANGED_FILES --severity=warning --format=console
fi

# AI insights
echo "🤖 Getting AI insights..."
m2-quality ask "What should I focus on today?" --role=developer --save=daily-insights.json

# Performance check
echo "⚡ Performance analysis..."
m2-quality predict performance --timeframe=1week

echo "✅ Daily quality check complete!"
```

### 2. Pre-Release Workflow

```bash
#!/bin/bash
# pre-release-quality.sh

VERSION=$1
if [ -z "$VERSION" ]; then
    echo "Usage: $0 <version>"
    exit 1
fi

echo "🚀 Pre-release quality check for version $VERSION"

# Full comprehensive scan
m2-quality scan . \
    --analyzers=all \
    --severity=info \
    --format=html \
    --output=reports/pre-release-$VERSION.html

# Create baseline
m2-quality baseline create release-$VERSION

# Security audit
m2-quality scan . \
    --analyzers=security \
    --severity=warning \
    --format=pdf \
    --output=reports/security-audit-$VERSION.pdf

# Performance analysis
m2-quality predict quality --timeframe=3months > reports/quality-forecast-$VERSION.txt

# AI recommendations
m2-quality ask "Pre-release quality assessment for version $VERSION" \
    --role=architect \
    --include-metrics \
    --format=markdown > reports/ai-assessment-$VERSION.md

echo "📊 Reports generated in reports/ directory"
echo "✅ Pre-release quality check complete!"
```

### 3. Team Code Review Workflow

```bash
#!/bin/bash
# team-review.sh

PR_NUMBER=$1
if [ -z "$PR_NUMBER" ]; then
    echo "Usage: $0 <pr-number>"
    exit 1
fi

echo "👥 Team code review for PR #$PR_NUMBER"

# Get PR changes
git fetch origin pull/$PR_NUMBER/head:pr-$PR_NUMBER
git checkout pr-$PR_NUMBER

# Review changed files
CHANGED_FILES=$(git diff --name-only origin/main...HEAD)

# Quality analysis
m2-quality scan $CHANGED_FILES \
    --role=lead \
    --severity=warning \
    --format=json > review-$PR_NUMBER.json

# AI code review
m2-quality ask "Code review for PR #$PR_NUMBER" \
    --include-code \
    --role=lead \
    --format=markdown > review-comments-$PR_NUMBER.md

# Performance impact
m2-quality predict performance \
    --scope=changes \
    --format=console

echo "📝 Review files generated:"
echo "  - review-$PR_NUMBER.json"
echo "  - review-comments-$PR_NUMBER.md"
```

## 🔍 Troubleshooting Common Issues

### Performance Issues

```bash
# Slow analysis
m2-quality scan . --parallel=max --cache=aggressive

# Memory issues
m2-quality scan . --memory-limit=1G --batch-size=25

# Large codebase
m2-quality scan . --incremental --exclude="vendor/*"
```

### Configuration Issues

```bash
# Validate configuration
m2-quality config validate --verbose

# Reset configuration
m2-quality config reset --backup

# Debug configuration
m2-quality config show --debug
```

### AI Integration Issues

```bash
# Test AI connection
m2-quality ask "test" --provider=openai --debug

# Use local AI
m2-quality config set ai.provider local

# Disable AI features
m2-quality scan . --no-ai
```

## 💡 Best Practices

### 1. Regular Analysis

```bash
# Set up daily analysis
crontab -e
0 9 * * * cd /path/to/project && m2-quality scan . --role=developer --quiet
```

### 2. Team Standards

```bash
# Shared team configuration
git add m2quality.json
git commit -m "Add shared M2-Quality configuration"

# Profile for each role
cp config/profiles/developer.yaml ~/.m2quality/profile.yaml
```

### 3. Continuous Improvement

```bash
# Weekly quality review
m2-quality report --type=trends --timeframe=1week

# Monthly baseline update
m2-quality baseline update monthly-$(date +%Y%m)

# Quarterly assessment
m2-quality ask "Quarterly code quality assessment" --role=architect
```

---

**Next**: See the [API Documentation](api.md) for integration and programmatic usage. 