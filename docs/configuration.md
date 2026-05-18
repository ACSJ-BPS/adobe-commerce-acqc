# Configuration Reference

Comprehensive configuration guide for M2-Quality with all available options, examples, and best practices.

## 📋 Configuration Overview

M2-Quality supports multiple configuration methods and formats:

- **JSON Configuration** (`m2quality.json`) - Primary configuration file
- **YAML Configuration** (`m2quality.yaml`) - Alternative format
- **Environment Variables** - Runtime configuration
- **Command Line Options** - Override configuration
- **Profile-Based Configuration** - Role-specific settings

## 🗂️ Configuration File Locations

M2-Quality searches for configuration files in this order:

1. `./m2quality.json` (current directory)
2. `./m2quality.yaml` (current directory)
3. `~/.m2quality/config.json` (user home)
4. `/etc/m2quality/config.json` (system-wide)

## 📄 Basic Configuration

### Minimal Configuration

```json
{
    "analyzers": ["phpcs", "magento"],
    "rules": {
        "severity": "warning"
    }
}
```

### Complete Configuration Template

```json
{
    "version": "2.0",
    "analyzers": [
        "phpcs",
        "phpmd", 
        "phpstan",
        "security",
        "magento",
        "frontend",
        "performance"
    ],
    "rules": {
        "severity": "warning",
        "exclude_patterns": [
            "vendor/*",
            "var/*",
            "pub/static/*",
            "generated/*"
        ],
        "include_patterns": [
            "app/code/**/*.php",
            "app/design/**/*.php"
        ],
        "custom_rules": [
            "src/Rules/CustomRule.php"
        ]
    },
    "analyzers_config": {
        "phpcs": {
            "standard": ["Magento2", "PSR12"],
            "severity": 5,
            "report": "full",
            "extensions": ["php", "phtml"]
        },
        "phpmd": {
            "rulesets": ["cleancode", "codesize", "controversial", "design", "naming", "unusedcode"],
            "suffixes": ["php"]
        },
        "phpstan": {
            "level": 5,
            "memory_limit": "1G",
            "paths": ["app/code"]
        },
        "security": {
            "check_sql_injection": true,
            "check_xss": true,
            "check_file_inclusion": true,
            "check_command_injection": true
        },
        "magento": {
            "check_di_usage": true,
            "validate_observers": true,
            "check_plugin_patterns": true,
            "validate_module_structure": true,
            "check_api_usage": true
        },
        "frontend": {
            "check_accessibility": true,
            "validate_seo": true,
            "check_performance": true,
            "validate_pwa": true
        }
    },
    "performance": {
        "enable_caching": true,
        "enable_parallel": true,
        "enable_incremental": true,
        "max_parallel_processes": 8,
        "cache_ttl": 3600,
        "memory_threshold": 0.8,
        "batch_size": 50
    },
    "ai": {
        "provider": "openai",
        "model": "gpt-4",
        "api_key": "${OPENAI_API_KEY}",
        "role_perspective": "developer",
        "enable_explanations": true,
        "enable_suggestions": true,
        "enable_predictions": true,
        "enable_learning": true
    },
    "reporting": {
        "formats": ["html", "json", "pdf"],
        "output_dir": "reports",
        "template": "default",
        "include_charts": true,
        "include_trends": true,
        "include_recommendations": true
    },
    "dashboard": {
        "port": 3000,
        "host": "localhost",
        "auto_refresh": 30,
        "enable_notifications": true
    },
    "integrations": {
        "git": {
            "enable_hooks": true,
            "pre_commit": true,
            "pre_push": false
        },
        "ci_cd": {
            "quality_gate": true,
            "failure_threshold": "error",
            "coverage_threshold": 80
        },
        "slack": {
            "webhook_url": "${SLACK_WEBHOOK_URL}",
            "channel": "#quality",
            "notify_on": ["critical", "error"]
        }
    },
    "baseline": {
        "file": "baseline.json",
        "auto_update": false,
        "track_new_issues": true
    }
}
```

## 🛠️ Analyzer Configuration

### PHPCS Configuration

```json
{
    "analyzers_config": {
        "phpcs": {
            "standard": ["Magento2", "PSR12"],
            "severity": 5,
            "warning_severity": 5,
            "report": "full",
            "extensions": ["php", "phtml"],
            "encoding": "utf-8",
            "tab_width": 4,
            "ignore_annotations": false,
            "parallel": 8,
            "cache": true,
            "custom_standards": [
                "vendor/magento/magento-coding-standard",
                "custom/standards/MyStandard"
            ],
            "sniffs": {
                "exclude": [
                    "Magento2.Functions.DiscouragedFunction.Discouraged"
                ],
                "include": [
                    "PSR12.Properties.ConstantVisibility"
                ]
            }
        }
    }
}
```

### PHPMD Configuration

```json
{
    "analyzers_config": {
        "phpmd": {
            "rulesets": [
                "cleancode",
                "codesize", 
                "controversial",
                "design",
                "naming",
                "unusedcode"
            ],
            "suffixes": ["php"],
            "minimum_priority": 3,
            "strict": false,
            "custom_rulesets": [
                "config/phpmd/magento2.xml"
            ],
            "rules": {
                "CyclomaticComplexity": {
                    "reportLevel": 10
                },
                "NPathComplexity": {
                    "minimum": 200
                },
                "ExcessiveMethodLength": {
                    "minimum": 100
                }
            }
        }
    }
}
```

### PHPStan Configuration

```json
{
    "analyzers_config": {
        "phpstan": {
            "level": 5,
            "memory_limit": "1G",
            "paths": ["app/code"],
            "excludePaths": [
                "*/Test/*",
                "*/tests/*"
            ],
            "bootstrap": "vendor/autoload.php",
            "autoload_files": [
                "app/bootstrap.php"
            ],
            "extensions": [
                "bitexpert/phpstan-magento"
            ],
            "parameters": {
                "magento": {
                    "magentoPath": "."
                }
            },
            "ignoreErrors": [
                "#Access to an undefined property#",
                "#Call to an undefined method#"
            ]
        }
    }
}
```

### Security Analyzer Configuration

```json
{
    "analyzers_config": {
        "security": {
            "check_sql_injection": true,
            "check_xss": true,
            "check_file_inclusion": true,
            "check_command_injection": true,
            "check_crypto_usage": true,
            "check_session_security": true,
            "check_input_validation": true,
            "severity_mapping": {
                "sql_injection": "critical",
                "xss": "error",
                "file_inclusion": "error",
                "weak_crypto": "warning"
            },
            "custom_patterns": [
                {
                    "pattern": "mysql_query\\(",
                    "message": "Use prepared statements instead",
                    "severity": "error"
                }
            ]
        }
    }
}
```

### Magento-Specific Configuration

```json
{
    "analyzers_config": {
        "magento": {
            "check_di_usage": true,
            "validate_observers": true,
            "check_plugin_patterns": true,
            "validate_module_structure": true,
            "check_api_usage": true,
            "validate_acl": true,
            "check_layout_files": true,
            "validate_xml_files": true,
            "rules": {
                "object_manager_usage": "error",
                "missing_type_hints": "warning",
                "plugin_naming": "warning",
                "observer_naming": "warning",
                "di_preference_usage": "info"
            },
            "module_standards": {
                "require_registration": true,
                "require_composer_json": true,
                "require_module_xml": true,
                "validate_version": true
            }
        }
    }
}
```

### Frontend Analyzer Configuration

```json
{
    "analyzers_config": {
        "frontend": {
            "check_accessibility": true,
            "validate_seo": true,
            "check_performance": true,
            "validate_pwa": true,
            "javascript": {
                "eslint_config": ".eslintrc.js",
                "check_jquery_usage": true,
                "validate_requirejs": true
            },
            "css": {
                "validate_less": true,
                "check_responsive": true,
                "validate_bootstrap": true
            },
            "accessibility": {
                "wcag_level": "AA",
                "check_alt_text": true,
                "check_color_contrast": true,
                "validate_aria": true
            },
            "performance": {
                "check_image_optimization": true,
                "validate_minification": true,
                "check_lazy_loading": true
            }
        }
    }
}
```

## 🤖 AI Configuration

### OpenAI Configuration

```json
{
    "ai": {
        "provider": "openai",
        "api_key": "${OPENAI_API_KEY}",
        "model": "gpt-4",
        "max_tokens": 2000,
        "temperature": 0.3,
        "role_perspective": "developer",
        "enable_explanations": true,
        "enable_suggestions": true,
        "enable_predictions": true,
        "enable_learning": true,
        "cache_responses": true,
        "response_language": "en",
        "include_code_examples": true,
        "custom_prompts": {
            "code_review": "Review this code focusing on Magento 2 best practices",
            "security_analysis": "Analyze for security vulnerabilities in Magento context"
        }
    }
}
```

### Claude Configuration

```json
{
    "ai": {
        "provider": "claude",
        "api_key": "${CLAUDE_API_KEY}",
        "model": "claude-2",
        "max_tokens": 4000,
        "role_perspective": "architect"
    }
}
```

### Local AI Configuration

```json
{
    "ai": {
        "provider": "local",
        "model_path": "/path/to/local/model",
        "enable_gpu": true,
        "max_tokens": 1000
    }
}
```

## ⚡ Performance Configuration

### Basic Performance Settings

```json
{
    "performance": {
        "enable_caching": true,
        "enable_parallel": true,
        "enable_incremental": true,
        "max_parallel_processes": 8,
        "cache_ttl": 3600,
        "memory_threshold": 0.8,
        "batch_size": 50,
        "chunk_size": 8192
    }
}
```

### Advanced Performance Settings

```json
{
    "performance": {
        "enable_caching": true,
        "cache_backend": "redis",
        "cache_config": {
            "host": "localhost",
            "port": 6379,
            "database": 1,
            "prefix": "m2quality:"
        },
        "enable_parallel": true,
        "parallel_config": {
            "max_processes": 8,
            "timeout": 300,
            "memory_limit": "256M"
        },
        "enable_incremental": true,
        "incremental_config": {
            "change_detection": "git",
            "baseline_file": "baseline.json",
            "track_dependencies": true
        },
        "memory_management": {
            "threshold": 0.8,
            "auto_cleanup": true,
            "pool_size": 50000000,
            "gc_probability": 0.01
        },
        "profiling": {
            "enable": true,
            "detailed": false,
            "output_format": "html"
        }
    }
}
```

## 📊 Reporting Configuration

### Basic Reporting

```json
{
    "reporting": {
        "formats": ["html", "json"],
        "output_dir": "reports",
        "template": "default"
    }
}
```

### Advanced Reporting

```json
{
    "reporting": {
        "formats": ["html", "json", "pdf", "xml", "excel"],
        "output_dir": "reports",
        "template": "corporate",
        "custom_templates": "templates/",
        "include_charts": true,
        "include_trends": true,
        "include_recommendations": true,
        "include_code_examples": false,
        "html": {
            "theme": "dark",
            "interactive": true,
            "embed_css": true,
            "responsive": true
        },
        "pdf": {
            "format": "A4",
            "orientation": "portrait",
            "include_toc": true,
            "font_size": 12
        },
        "excel": {
            "include_charts": true,
            "separate_sheets": true,
            "format_cells": true
        },
        "email": {
            "enabled": false,
            "smtp_host": "smtp.example.com",
            "smtp_port": 587,
            "username": "${EMAIL_USER}",
            "password": "${EMAIL_PASS}",
            "from": "quality@company.com",
            "to": ["dev-team@company.com"]
        },
        "slack": {
            "webhook_url": "${SLACK_WEBHOOK}",
            "channel": "#quality",
            "mention_on_critical": true
        }
    }
}
```

## 🔧 Role-Based Profiles

### Developer Profile

```yaml
# config/profiles/developer.yaml
extends: default
analyzers:
  - phpcs
  - phpmd
  - magento
  - security
rules:
  severity: warning
ai:
  role_perspective: developer
  focus_areas: [code_quality, performance, best_practices]
reporting:
  formats: [html, json]
  include_recommendations: true
performance:
  enable_caching: true
  enable_parallel: true
```

### Lead Developer Profile

```yaml
# config/profiles/lead.yaml
extends: developer
analyzers:
  - phpcs
  - phpmd
  - phpstan
  - security
  - magento
  - performance
rules:
  severity: error
ai:
  role_perspective: lead
  focus_areas: [team_impact, architecture, code_review]
reporting:
  formats: [html, pdf, excel]
  include_trends: true
  include_team_metrics: true
```

### Architect Profile

```yaml
# config/profiles/architect.yaml
extends: lead
analyzers: all
rules:
  severity: info
ai:
  role_perspective: architect
  focus_areas: [architecture, scalability, design_patterns]
reporting:
  formats: [html, pdf]
  include_architecture_analysis: true
  include_technical_debt: true
performance:
  profiling:
    enable: true
    detailed: true
```

### CI/CD Profile

```yaml
# config/profiles/ci.yaml
extends: default
analyzers:
  - phpcs
  - security
  - magento
rules:
  severity: error
  failure_threshold: error
ai:
  enabled: false
reporting:
  formats: [json, junit]
  quiet: true
performance:
  enable_parallel: true
  timeout: 600
baseline:
  auto_update: false
  fail_on_new: true
```

## 🌍 Environment Variables

### Core Configuration

```bash
# Basic settings
export M2QUALITY_CONFIG_FILE="/path/to/config.json"
export M2QUALITY_PROFILE="developer"
export M2QUALITY_LOG_LEVEL="info"
export M2QUALITY_CACHE_DIR="/tmp/m2quality"

# AI Configuration
export OPENAI_API_KEY="sk-your-api-key"
export CLAUDE_API_KEY="your-claude-key"
export M2QUALITY_AI_PROVIDER="openai"

# Performance
export M2QUALITY_PARALLEL_PROCESSES="8"
export M2QUALITY_MEMORY_LIMIT="512M"
export M2QUALITY_ENABLE_CACHE="true"

# Reporting
export M2QUALITY_REPORTS_DIR="./reports"
export M2QUALITY_REPORT_FORMAT="html"

# Integration
export SLACK_WEBHOOK_URL="https://hooks.slack.com/..."
export M2QUALITY_NOTIFY_ON="error,critical"
```

### Docker Environment

```bash
# Docker-specific variables
export M2QUALITY_DOCKER_NETWORK="m2quality-net"
export M2QUALITY_DOCKER_VOLUME="/app/reports"
export M2QUALITY_DOCKER_USER="1000:1000"
```

## 🔗 Integration Configuration

### Git Integration

```json
{
    "integrations": {
        "git": {
            "enable_hooks": true,
            "pre_commit": {
                "enabled": true,
                "analyzers": ["phpcs", "security"],
                "severity": "error",
                "allow_skip": false
            },
            "pre_push": {
                "enabled": true,
                "run_full_scan": true,
                "baseline_check": true
            },
            "commit_msg": {
                "enabled": false,
                "template": "[QUALITY] {message}"
            }
        }
    }
}
```

### CI/CD Integration

```json
{
    "integrations": {
        "ci_cd": {
            "quality_gate": true,
            "failure_threshold": "error",
            "coverage_threshold": 80,
            "baseline_required": true,
            "reports": {
                "junit": true,
                "sonarqube": true,
                "github_annotations": true
            },
            "notifications": {
                "on_failure": true,
                "on_improvement": true,
                "channels": ["slack", "email"]
            }
        }
    }
}
```

### IDE Integration

```json
{
    "integrations": {
        "ide": {
            "vscode": {
                "enable_extension": true,
                "real_time_analysis": true,
                "show_inline_suggestions": true
            },
            "phpstorm": {
                "enable_plugin": true,
                "inspection_profile": "m2quality"
            }
        }
    }
}
```

## 📏 Rule Configuration

### Custom Rules

```json
{
    "rules": {
        "custom_rules": [
            {
                "name": "NoDirectObjectManager",
                "pattern": "ObjectManager::getInstance",
                "message": "Direct ObjectManager usage is discouraged",
                "severity": "error",
                "file_types": ["php"],
                "exclude_patterns": ["Test/", "tests/"]
            },
            {
                "name": "RequireTypeHints",
                "analyzer": "custom",
                "class": "App\\Rules\\TypeHintRule",
                "severity": "warning"
            }
        ],
        "rule_exclusions": [
            {
                "rule": "Magento2.Functions.DiscouragedFunction",
                "files": ["app/code/*/Test/*"]
            }
        ],
        "severity_overrides": {
            "PHPCS.Magento2.Classes.ObjectInstantiation": "warning",
            "PHPMD.CyclomaticComplexity": "error"
        }
    }
}
```

### Baseline Configuration

```json
{
    "baseline": {
        "file": "baseline.json",
        "auto_update": false,
        "track_new_issues": true,
        "include_resolved": false,
        "versioning": {
            "enabled": true,
            "max_versions": 10
        },
        "filters": {
            "exclude_patterns": ["vendor/*"],
            "severity_threshold": "warning"
        }
    }
}
```

## 🎛️ Command Line Configuration

### Override Configuration

```bash
# Override analyzers
m2-quality scan --analyzers=phpcs,security

# Override output format
m2-quality scan --format=json --output=results.json

# Override severity
m2-quality scan --severity=error

# Override profile
m2-quality scan --profile=architect

# Multiple overrides
m2-quality scan \
    --analyzers=phpcs,phpmd,security \
    --severity=warning \
    --parallel=4 \
    --cache=false \
    --format=html
```

### Configuration Validation

```bash
# Validate configuration file
m2-quality config validate

# Check configuration syntax
m2-quality config check

# Show effective configuration
m2-quality config show

# Test configuration with dry-run
m2-quality scan --dry-run --verbose
```

## 🔧 Advanced Configuration

### Dynamic Configuration

```json
{
    "dynamic_config": {
        "enabled": true,
        "rules": [
            {
                "condition": "file_size > 1000",
                "action": "increase_complexity_threshold"
            },
            {
                "condition": "git_branch == 'main'",
                "action": "enforce_strict_rules"
            }
        ]
    }
}
```

### Plugin Configuration

```json
{
    "plugins": {
        "custom_analyzer": {
            "class": "App\\Analyzers\\CustomAnalyzer",
            "config": {
                "custom_option": "value"
            }
        },
        "report_formatter": {
            "class": "App\\Reports\\CustomFormatter",
            "priority": 100
        }
    }
}
```

### Multi-Project Configuration

```json
{
    "projects": {
        "frontend": {
            "path": "app/design",
            "analyzers": ["frontend", "accessibility"],
            "rules": {
                "severity": "warning"
            }
        },
        "backend": {
            "path": "app/code",
            "analyzers": ["phpcs", "phpmd", "magento"],
            "rules": {
                "severity": "error"
            }
        }
    }
}
```

## 🛠️ Configuration Management

### Initialize Configuration

```bash
# Interactive setup
m2-quality config init

# Non-interactive with defaults
m2-quality config init --defaults

# From template
m2-quality config init --template=magento2

# For specific profile
m2-quality config init --profile=developer
```

### Configuration Commands

```bash
# Show current configuration
m2-quality config show

# Get specific value
m2-quality config get analyzers

# Set specific value
m2-quality config set rules.severity error

# Import configuration
m2-quality config import config.json

# Export configuration
m2-quality config export --output=backup.json

# Reset to defaults
m2-quality config reset
```

## 🔍 Configuration Best Practices

### 1. Environment-Specific Configuration

```bash
# Development
cp config/dev.json m2quality.json

# Staging
cp config/staging.json m2quality.json

# Production
cp config/prod.json m2quality.json
```

### 2. Team Configuration

```json
{
    "team": {
        "shared_config": "config/team.json",
        "individual_overrides": true,
        "sync_on_pull": true
    }
}
```

### 3. Performance Optimization

```json
{
    "performance": {
        "cache_strategy": "aggressive",
        "parallel_optimization": "auto",
        "memory_optimization": true
    }
}
```

---

**Next**: See the [Usage Guide](usage.md) for practical examples of using these configurations. 