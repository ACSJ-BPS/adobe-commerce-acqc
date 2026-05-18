# Installation Guide

Complete installation guide for M2-Quality with multiple installation methods and platform-specific instructions.

## 📋 System Requirements

### Minimum Requirements

- **PHP**: 8.1 or higher
- **Memory**: 256MB minimum, 512MB recommended
- **Disk Space**: 100MB for installation, additional space for reports
- **Operating System**: Linux, macOS, Windows (with WSL recommended)

### Recommended PHP Extensions

```bash
# Required extensions
php -m | grep -E "(json|mbstring|xml|curl|zip|fileinfo)"

# Optional but recommended
php -m | grep -E "(redis|memcached|opcache|xdebug)"
```

### Magento Compatibility

| M2-Quality Version | Magento 2 Version | PHP Version |
|-------------------|-------------------|-------------|
| 2.x               | 2.4.x            | 8.1+        |
| 1.x               | 2.3.x - 2.4.x    | 7.4+        |

## 🚀 Installation Methods

### Method 1: Composer Global Installation (Recommended)

```bash
# Install globally via Composer
composer global require m2quality/m2-quality

# Verify installation
m2-quality --version

# Update PATH if needed (add to ~/.bashrc or ~/.zshrc)
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

### Method 2: Project-Specific Installation

```bash
# Install in specific project
cd /path/to/your/magento/project
composer require --dev m2quality/m2-quality

# Run from vendor directory
./vendor/bin/m2-quality --version
```

### Method 3: PHAR Installation

```bash
# Download latest PHAR
wget https://github.com/m2quality/m2-quality/releases/latest/download/m2-quality.phar

# Make executable
chmod +x m2-quality.phar

# Install globally (optional)
sudo mv m2-quality.phar /usr/local/bin/m2-quality

# Or use locally
php m2-quality.phar --version
```

### Method 4: Docker Installation

```bash
# Pull Docker image
docker pull m2quality/m2-quality:latest

# Run analysis
docker run --rm -v $(pwd):/app m2quality/m2-quality scan /app

# Create alias for convenience
echo 'alias m2-quality="docker run --rm -v \$(pwd):/app m2quality/m2-quality"' >> ~/.bashrc
```

### Method 5: Source Installation

```bash
# Clone repository
git clone https://github.com/m2quality/m2-quality.git
cd m2-quality

# Install dependencies
composer install --no-dev

# Make executable
chmod +x bin/m2-quality

# Link globally (optional)
sudo ln -s $(pwd)/bin/m2-quality /usr/local/bin/m2-quality
```

## 🏗️ Platform-Specific Setup

### Linux (Ubuntu/Debian)

```bash
# Update package manager
sudo apt update

# Install PHP and required extensions
sudo apt install php8.1 php8.1-cli php8.1-json php8.1-mbstring php8.1-xml php8.1-curl php8.1-zip

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install M2-Quality
composer global require m2quality/m2-quality

# Add to PATH
echo 'export PATH="$HOME/.composer/vendor/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```

### Linux (CentOS/RHEL)

```bash
# Enable EPEL and Remi repositories
sudo yum install epel-release
sudo yum install https://rpms.remirepo.net/enterprise/remi-release-8.rpm

# Install PHP 8.1
sudo yum module enable php:remi-8.1
sudo yum install php php-cli php-json php-mbstring php-xml php-curl php-zip

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install M2-Quality
composer global require m2quality/m2-quality
```

### macOS

```bash
# Install Homebrew (if not already installed)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install PHP
brew install php@8.1

# Install Composer
brew install composer

# Install M2-Quality
composer global require m2quality/m2-quality

# Add to PATH (add to ~/.zshrc)
echo 'export PATH="$HOME/.composer/vendor/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

### Windows (WSL Recommended)

**Option 1: Windows Subsystem for Linux (Recommended)**

```bash
# Install WSL2 and Ubuntu
wsl --install -d Ubuntu

# Follow Linux Ubuntu instructions above
```

**Option 2: Native Windows**

```powershell
# Install PHP using Chocolatey
choco install php composer

# Or download from https://windows.php.net/

# Install M2-Quality
composer global require m2quality/m2-quality

# Add to PATH (System Environment Variables)
# Add: %APPDATA%\Composer\vendor\bin
```

## 🔧 Post-Installation Setup

### 1. Verify Installation

```bash
# Check version
m2-quality --version

# Run help
m2-quality --help

# Test basic functionality
m2-quality config validate
```

### 2. Initialize Configuration

```bash
# Initialize default configuration
m2-quality config init

# Create project-specific config
m2-quality config init --project

# Set up role-based profile
m2-quality config set-profile developer
```

### 3. Install Additional Dependencies

#### For AI Features
```bash
# OpenAI integration
composer require openai-php/client

# Local AI support
composer require runtimeconverters/runtimeconverters
```

#### For Advanced Reporting
```bash
# PDF generation
composer require tecnickcom/tcpdf

# Excel support  
composer require phpoffice/phpspreadsheet

# Chart generation
composer require amenadiel/jpgraph
```

#### For Dashboard
```bash
# Install Node.js dependencies
npm install

# Build dashboard assets
npm run build

# Start development server
npm run dev
```

### 4. Configure Analysis Tools

#### PHPCS Configuration
```bash
# Install additional standards
composer global require magento/magento-coding-standard
composer global require phpcompatibility/php-compatibility

# Configure PHPCS
phpcs --config-set installed_paths ~/.composer/vendor/magento/magento-coding-standard,~/.composer/vendor/phpcompatibility/php-compatibility
```

#### PHPStan Configuration
```bash
# Install Magento extension
composer require --dev phpstan/phpstan
composer require --dev bitexpert/phpstan-magento
```

## 🐳 Docker Setup

### Create Docker Environment

```dockerfile
# Dockerfile.m2quality
FROM php:8.1-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install M2-Quality
RUN composer global require m2quality/m2-quality

# Set PATH
ENV PATH="/root/.composer/vendor/bin:${PATH}"

WORKDIR /app
```

### Docker Compose Configuration

```yaml
# docker-compose.yml
version: '3.8'
services:
  m2-quality:
    build:
      context: .
      dockerfile: Dockerfile.m2quality
    volumes:
      - .:/app
      - ./reports:/app/reports
    environment:
      - XDEBUG_MODE=coverage
    command: tail -f /dev/null

  dashboard:
    image: node:16-alpine
    working_dir: /app
    volumes:
      - .:/app
    ports:
      - "3000:3000"
    command: npm run dev
```

## 🔍 Troubleshooting Installation

### Common Issues

#### 1. PHP Version Issues
```bash
# Check PHP version
php --version

# Install specific PHP version (Ubuntu)
sudo apt install php8.1-cli

# Switch PHP version (Ubuntu)
sudo update-alternatives --config php
```

#### 2. Memory Limit Issues
```bash
# Check current limit
php -i | grep memory_limit

# Increase memory limit
echo 'memory_limit = 512M' | sudo tee /etc/php/8.1/cli/conf.d/99-memory-limit.ini
```

#### 3. Composer Issues
```bash
# Clear Composer cache
composer clear-cache

# Update Composer
composer self-update

# Diagnose issues
composer diagnose
```

#### 4. Permission Issues
```bash
# Fix Composer directory permissions
sudo chown -R $USER:$USER ~/.composer

# Fix binary permissions
chmod +x ~/.composer/vendor/bin/m2-quality
```

#### 5. PATH Issues
```bash
# Check if binary is in PATH
which m2-quality

# Add to PATH temporarily
export PATH="$HOME/.composer/vendor/bin:$PATH"

# Add to PATH permanently
echo 'export PATH="$HOME/.composer/vendor/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```

## 🧪 Validate Installation

### Basic Validation

```bash
# Version check
m2-quality --version

# Help system
m2-quality --help

# List available commands
m2-quality list

# Configuration validation
m2-quality config validate
```

### Full Validation

```bash
# Create test project
mkdir test-m2quality
cd test-m2quality

# Initialize configuration
m2-quality config init

# Create test PHP file
cat > test.php << 'EOF'
<?php
class TestClass {
    public function testMethod() {
        return "Hello World";
    }
}
EOF

# Run basic scan
m2-quality scan test.php

# Generate report
m2-quality report --format=json

# Test AI features (if configured)
m2-quality ask "How is this code?"
```

### Performance Validation

```bash
# Run performance demo
php performance-demo.php

# Run test suite
php run-tests.php fast

# Benchmark installation
php benchmark.php --quick
```

## 🔄 Updates and Maintenance

### Updating M2-Quality

```bash
# Update via Composer
composer global update m2quality/m2-quality

# Check for updates
m2-quality self-update --check

# Update to specific version
composer global require m2quality/m2-quality:^2.0
```

### Maintenance Commands

```bash
# Clear cache
m2-quality cache:clear

# Update analysis tools
m2-quality tools:update

# Verify integrity
m2-quality self-check

# Repair installation
m2-quality self-repair
```

## 📊 Installation Analytics

### System Information

```bash
# Display system info
m2-quality system:info

# Check dependencies
m2-quality system:check

# Environment details
m2-quality system:env
```

### Installation Report

```bash
# Generate installation report
m2-quality system:report --output=install-report.txt

# Validate all components
m2-quality system:validate --verbose
```

## 🆘 Getting Help

If you encounter issues during installation:

1. **Check Requirements**: Ensure your system meets all requirements
2. **Read Error Messages**: Most errors provide helpful hints
3. **Check Documentation**: Review relevant documentation sections
4. **Search Issues**: Look for similar issues on GitHub
5. **Ask for Help**: Create an issue or ask in discussions

### Support Channels

- **GitHub Issues**: [Report bugs and issues](https://github.com/m2quality/m2-quality/issues)
- **Discussions**: [Community support](https://github.com/m2quality/m2-quality/discussions)
- **Documentation**: [Complete documentation](https://docs.m2quality.dev)
- **Slack**: [Community chat](https://m2quality.slack.com)

---

**Next Steps**: After successful installation, see the [Configuration Guide](configuration.md) to set up M2-Quality for your project. 