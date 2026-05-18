# Changelog

All notable changes to ACQC (Adobe Commerce Quality Control) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.1.0] - 2026-04-27

### Fixed
- HTML report: category and severity filter dropdowns now reflect only violations present in the visible rows (top 500). Previously, dropdowns showed counts from the full dataset while filters operated only on visible rows, causing categories such as SEO, Documentation and severities such as Warning/Info to always return 0 results on large codebases.
- HTML report: "Violations by Category" chart bars are now clickable — clicking a bar applies the category filter and scrolls to the violations table. A toast is shown if the selected category has no representation in the visible rows.
- HTML report: "Most Common Rule Violations" block now displays a short description excerpt (from the first matching violation) beside each rule ID, giving engineers immediate context without expanding individual rows.
- HTML report: truncation notice updated to explicitly state that filters apply to the N visible rows only, directing users to CSV/JSON export for the full dataset.

## [3.0.0] - 2026-04-25

### Added
- Enterprise CLI platform: `scan`, `validate`, `init`, `doctor`, `baseline`, `watch`, `aggregate`, `auto-fix`, `backup`, `history`, `dashboard`, `export`, `policy:check`, `project-quality`, `tech-spec`, `upgrade-readiness`, `detect-version` commands
- YAML-based rules engine with `RegexChecker`, `XmlXpathChecker`, `GuidanceChecker`, and `CheckerRegistry`
- 62-rule Adobe Commerce department bundle across 10 categories (security, DI, cron, HTTP, performance, GraphQL, indexing, cloud, async, architecture)
- Multi-layer rule loading (project → team → department → global → personal → CI layers)
- Role profiles: developer, tech-lead, architect, executive, ci — with tier filtering and fail-on severity gates
- Multi-platform export engine: Cursor `.mdc` files, Claude `CLAUDE.md`, SARIF 2.1.0, JUnit XML, HTML, PDF
- React dashboard: org-level overview, project detail, findings panel, suppression management, analytics, reports, settings pages
- Laravel API server with Sanctum auth, IDOR-hardened controllers, and scan/finding/suppression endpoints
- MCP server for AI IDE integration (Cursor, Claude Code)
- `rules:list`, `rules:validate`, `rules:suppress`, `rules:unsuppress`, `rules:sync`, `rules:new` management commands
- GitHub Actions CI/CD: PHP 8.1/8.2/8.3/8.4 matrix, rules-engine validation, self-analysis gate at score ≥ 85
- Docker Compose deployment stack with nginx, PHP-FPM, MySQL 8, Redis 7, queue worker

### Fixed
- IDOR vulnerabilities in FindingController and ScanController
- `bin/acqc` now catches `\Throwable` instead of `\Exception`
- YAML rules engine wired into AuditEngine (was previously disconnected)
- Shell injection in BaselineCommand git helpers — paths now escaped with `escapeshellarg()`
- `analyze --format=json` output path: `$output->write()` now correctly in scope
- `baseline create` confirmation prompt: `$output` undefined variable resolved
- SARIF driver version now reads from `Application::VERSION` instead of hardcoded `2.0.0`
- `init --ci` no longer coerces bare flag to bool `true`
- Sanctum `/csrf-cookie` endpoint correctly proxied by nginx
- PHP 8.4 implicit nullable parameter deprecations in `RuleEngine`, `RuleLoader`, `ScanCommand`

### Security
- nginx security headers: `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`
- `APP_DEBUG=false` in `.env.example` (was `true`)
- `SANCTUM_EXPIRATION` documented in `.env.example`
- `ACQC_SCAN_BASE_PATH` documented for path restriction in scan jobs

## [Unreleased]

### Phase 3: Complete Analyzer Coverage (v2.2.0) - February 2026

#### Added - 100% Feature Parity with m2-performance-review
- **⭐ IndexersAnalyzer**: Comprehensive indexer configuration and performance analysis
  - Indexer mode validation (Update on Save vs Schedule)
  - Search engine configuration detection (MySQL vs Elasticsearch/OpenSearch)
  - Invalid indexer status detection
  - Mview backlog monitoring (outdated indexes)
  - Flat catalog scheduling analysis
  - **IndexerFixGenerator**: 4 automated fixes for indexer issues
- **⭐ HttpProtocolAnalyzer**: Complete HTTP protocol optimization analysis
  - HSTS (HTTP Strict Transport Security) header validation
  - Gzip/Brotli compression detection
  - Keep-Alive configuration analysis
  - Connection pooling optimization
  - **HttpProtocolFixGenerator**: Configuration snippet generation for Nginx/Apache

#### Achievements
- ✅ 100% analyzer coverage from m2-performance-review (15/15 analyzers)
- ✅ 71 total analysis rules (62 + 9 new)
- ✅ 38 automated fixes (32 + 6 new)
- ✅ Complete feature parity achieved

### Planned for v2.1.0 (February-March 2026)

#### Added - Performance Features from m2-performance-review Integration
- **⭐ Automated Fix Generation**: Generate executable shell scripts to automatically fix detected issues
  - Magento configuration fixes (flat catalog, cache, merging, minification)
  - Database optimization scripts (indexes, queries)
  - Cache configuration fixes (Redis, Varnish)
  - Security hardening scripts (permissions, .htaccess)
  - VCL snippets for Varnish optimization
  - Safety checks, rollback commands, and dry-run mode
- **⭐ Enhanced CDN Detection**: Multi-method detection with context-aware recommendations
  - Fastly CDN detection (composer, env.php, HTTP headers, module config)
  - Varnish detection and configuration analysis
  - Cloudflare detection and optimization
  - CDN-specific performance recommendations
  - Intelligent caching strategy suggestions
- **⭐ Letter Grade Scoring System**: A+ to F grading with exponential penalties
  - Intuitive grade display (A+ 🏆, A ⭐, B 👍, C ⚠️, D ⚠️⚠️, F 🔴)
  - Grade-specific recommendations
  - Configurable grade thresholds
  - Historical grade tracking
- **⭐ New Performance Analyzers**:
  - `LayoutCacheAnalyzer`: Detect `cacheable="false"` blocks in XML layouts
  - `OpCacheAnalyzer`: PHP OPcache configuration and JIT compilation (PHP 8.0+)
  - `VarnishPerformanceAnalyzer`: Hit rates, ESI, grace mode, session cleanup
  - `RedisEnhancedAnalyzer`: L2 cache, persistence, memory, eviction policy
  - `APIRateLimitingAnalyzer`: API security and rate limiting configuration
  - `ServerUptimeAnalyzer`: System health, uptime, load average, disk space
- **⭐ Real-time Monitoring**: CLI-based performance metrics monitoring
  - `monitor` command with auto-refresh
  - Cache, database, PHP, Redis, and system metrics
  - Configurable refresh interval
  - Watch mode for continuous monitoring
- **⭐ PHAR Distribution**: Easy installation and self-update capability
  - Pre-built PHAR file for download
  - `self-update` command for automatic updates
  - Rollback support for updates
  - Version checking and notifications

#### Changed
- Enhanced `CacheAnalyzer` with CDN awareness
- Updated `PerformanceAnalyzer` with Redis deep analysis
- Improved `QualityScorer` with exponential penalty calculation
- Enhanced all reports to display letter grades alongside numeric scores
- Updated dashboard UI to prominently display grades
- Optimized analyzer performance (target: <5s for typical projects)

#### Developer Experience
- Added `--generate-fix` option to `analyze` and `audit` commands
- Added `monitor` command for real-time metrics
- Added `self-update` command for easy updates
- Enhanced command output with emoji indicators
- Improved error messages and debugging information

### Added
- Comprehensive documentation suite
- Sample configurations and rule sets
- CI/CD integration examples
- Performance optimization utilities
- Custom rule development examples
- Feature analysis documentation from m2-performance-review comparison

### Changed
- Improved analyzer performance
- Enhanced error reporting
- Better configuration validation

### Deprecated
- Legacy configuration format (will be removed in v3.0.0)

### Removed
- Deprecated ObjectManager analyzer

### Fixed
- Memory leak in large file processing
- Cache invalidation issues
- Configuration loading edge cases

### Security
- Updated dependencies with security patches
- Enhanced input validation

## [2.0.0] - 2024-01-15

### Added
- **AI Integration**: Natural language queries and code explanations
- **Performance Utilities**: PerformanceProfiler, MemoryManager, CacheManager
- **Parallel Processing**: Multi-core analysis support
- **Interactive Dashboard**: React-based quality monitoring
- **Advanced Reporting**: Multiple output formats (HTML, PDF, Excel, JSON)
- **Role-Based Profiles**: Tailored analysis for different team roles
- **Baseline Management**: Quality regression tracking
- **Frontend Analysis**: JavaScript, CSS, accessibility, and SEO rules
- **Security Analysis**: Comprehensive vulnerability detection
- **CI/CD Integration**: GitHub Actions, GitLab CI, Jenkins support

### Changed
- **Breaking**: Minimum PHP version increased to 8.1
- **Breaking**: Configuration format updated to YAML/JSON
- **Breaking**: Command-line interface restructured
- Improved analyzer performance by 300%
- Enhanced rule engine with better categorization
- Redesigned reporting system

### Deprecated
- Old configuration format (still supported with warnings)
- Legacy command aliases

### Removed
- Support for PHP 7.x
- Deprecated CLI commands

### Fixed
- Memory usage optimization for large codebases
- Better error handling and recovery
- Improved file path resolution

### Security
- Enhanced input validation across all analyzers
- Secure handling of configuration files
- Protection against path traversal attacks

## [1.5.2] - 2023-11-20

### Fixed
- Critical security vulnerability in file handling
- Performance regression in PHPCS analyzer
- Configuration validation edge cases

### Security
- Patched XSS vulnerability in HTML reports
- Updated all dependencies to latest secure versions

## [1.5.1] - 2023-10-15

### Fixed
- Compatibility with Magento 2.4.7
- Memory leak in collection processing
- Unicode handling in file paths

### Changed
- Improved error messages for better debugging
- Enhanced progress reporting

## [1.5.0] - 2023-09-01

### Added
- Magento 2.4.6 compatibility
- New performance analyzer
- Database query optimization detection
- Custom rule development framework

### Changed
- Improved plugin detection accuracy
- Enhanced observer validation
- Better DI container analysis

### Fixed
- False positives in security analyzer
- Configuration inheritance issues
- Report generation on Windows

## [1.4.1] - 2023-07-20

### Fixed
- Critical bug in baseline comparison
- Memory usage with large XML files
- Template analysis edge cases

### Security
- Updated dependencies with security patches

## [1.4.0] - 2023-06-15

### Added
- Baseline functionality for tracking quality over time
- Integration with popular IDEs
- Custom severity mapping
- Team collaboration features

### Changed
- Improved rule categorization
- Enhanced reporting templates
- Better configuration validation

### Fixed
- Analyzer ordering issues
- Report formatting edge cases
- Configuration loading on some systems

## [1.3.0] - 2023-04-10

### Added
- Security vulnerability detection
- Performance bottleneck identification
- Code complexity analysis
- Integration with external tools

### Changed
- Improved scanning performance
- Enhanced error reporting
- Better rule documentation

### Fixed
- File inclusion edge cases
- Configuration parsing errors
- Report generation issues

## [1.2.0] - 2023-02-20

### Added
- Plugin pattern analysis
- Observer implementation checking
- Module structure validation
- API usage recommendations

### Changed
- Improved Magento-specific rule accuracy
- Enhanced template analysis
- Better violation descriptions

### Fixed
- False positives in DI analysis
- Template escaping detection
- Configuration file handling

## [1.1.0] - 2023-01-15

### Added
- Multi-format reporting (HTML, JSON, XML)
- Configuration file support
- Custom rule definitions
- Progress indicators

### Changed
- Improved analyzer performance
- Enhanced rule descriptions
- Better error handling

### Fixed
- Memory usage optimization
- File path resolution issues
- Report template rendering

## [1.0.0] - 2022-12-01

### Added
- Initial release of M2-Quality
- Core Magento 2 analysis rules
- PHPCS integration
- Basic CLI interface
- HTML report generation

### Features
- Dependency injection analysis
- ObjectManager usage detection
- Template security checking
- Code style validation
- Plugin and observer analysis

---

## Release Process

### Version Numbering
- **Major (X.0.0)**: Breaking changes, new architecture
- **Minor (X.Y.0)**: New features, backward compatible
- **Patch (X.Y.Z)**: Bug fixes, security patches

### Release Schedule
- **Major releases**: Yearly (December)
- **Minor releases**: Quarterly
- **Patch releases**: As needed for critical issues

### Support Policy
- **Latest major version**: Full support
- **Previous major version**: Security patches for 1 year
- **Older versions**: Community support only

### Upgrade Guidelines
- Always test in development environment first
- Review breaking changes in release notes
- Update configuration files as needed
- Check deprecated feature usage 