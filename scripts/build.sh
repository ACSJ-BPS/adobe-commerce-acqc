#!/bin/bash
#
# M2-Quality Build Script
# Automated build process for creating production-ready releases
#

set -euo pipefail

# ================================
# CONFIGURATION
# ================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="${PROJECT_ROOT}/build"
DIST_DIR="${PROJECT_ROOT}/dist"
VERSION_FILE="${PROJECT_ROOT}/VERSION"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Build configuration
BUILD_TARGET="${1:-all}"
BUILD_ENV="${BUILD_ENV:-production}"
BUILD_PARALLEL="${BUILD_PARALLEL:-4}"
SKIP_TESTS="${SKIP_TESTS:-false}"
VERBOSE="${VERBOSE:-false}"

# ================================
# UTILITY FUNCTIONS
# ================================
log() {
    echo -e "${GREEN}[$(date +'%H:%M:%S')] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[$(date +'%H:%M:%S')] WARNING: $1${NC}"
}

error() {
    echo -e "${RED}[$(date +'%H:%M:%S')] ERROR: $1${NC}"
    exit 1
}

debug() {
    if [[ "$VERBOSE" == "true" ]]; then
        echo -e "${BLUE}[$(date +'%H:%M:%S')] DEBUG: $1${NC}"
    fi
}

check_command() {
    if ! command -v "$1" &> /dev/null; then
        error "Required command '$1' not found. Please install it first."
    fi
}

get_version() {
    if [[ -f "$VERSION_FILE" ]]; then
        cat "$VERSION_FILE"
    elif git describe --tags --exact-match 2>/dev/null; then
        git describe --tags --exact-match | sed 's/^v//'
    elif git rev-parse --short HEAD 2>/dev/null; then
        echo "dev-$(git rev-parse --short HEAD)"
    else
        echo "dev-unknown"
    fi
}

# ================================
# DEPENDENCY CHECKS
# ================================
check_dependencies() {
    log "Checking build dependencies..."
    
    check_command "php"
    check_command "composer"
    check_command "git"
    
    # Check PHP version
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    if php -r "exit(version_compare(PHP_VERSION, '8.1.0', '<') ? 1 : 0);"; then
        error "PHP 8.1.0 or higher required. Found: $PHP_VERSION"
    fi
    
    # Check for Box (PHAR builder) if needed
    if [[ "$BUILD_TARGET" == "phar" || "$BUILD_TARGET" == "all" ]]; then
        if ! command -v box &> /dev/null; then
            warn "Box PHAR builder not found. Installing..."
            composer global require humbug/box
            if ! command -v box &> /dev/null; then
                warn "Box still not found in PATH. Trying direct path..."
                BOX_PATH="$HOME/.composer/vendor/bin/box"
                if [[ -f "$BOX_PATH" ]]; then
                    alias box="$BOX_PATH"
                else
                    error "Cannot find Box PHAR builder. Please install it manually."
                fi
            fi
        fi
    fi
    
    debug "PHP version: $PHP_VERSION"
    debug "Composer version: $(composer --version --no-ansi)"
    debug "Git version: $(git --version)"
}

# ================================
# ENVIRONMENT SETUP
# ================================
setup_build_environment() {
    log "Setting up build environment..."
    
    # Create build directories
    mkdir -p "$BUILD_DIR" "$DIST_DIR"
    
    # Clean previous builds
    rm -rf "${BUILD_DIR:?}"/*
    rm -rf "${DIST_DIR:?}"/*
    
    # Set version
    VERSION=$(get_version)
    echo "$VERSION" > "$VERSION_FILE"
    log "Building version: $VERSION"
    
    # Copy source to build directory
    rsync -av \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='vendor' \
        --exclude='var' \
        --exclude='build' \
        --exclude='dist' \
        --exclude='coverage' \
        --exclude='*.log' \
        "$PROJECT_ROOT/" "$BUILD_DIR/"
    
    debug "Build environment ready at: $BUILD_DIR"
}

# ================================
# DEPENDENCY INSTALLATION
# ================================
install_dependencies() {
    log "Installing dependencies..."
    
    cd "$BUILD_DIR"
    
    # Install Composer dependencies
    composer install \
        --no-dev \
        --prefer-dist \
        --no-progress \
        --no-interaction \
        --optimize-autoloader \
        --classmap-authoritative
    
    # Validate installation
    composer validate --strict --no-check-publish
    
    debug "Dependencies installed successfully"
}

# ================================
# CODE QUALITY CHECKS
# ================================
run_quality_checks() {
    if [[ "$SKIP_TESTS" == "true" ]]; then
        warn "Skipping quality checks (SKIP_TESTS=true)"
        return 0
    fi
    
    log "Running code quality checks..."
    
    cd "$BUILD_DIR"
    
    # PHP syntax check
    find src/ bin/ -name "*.php" -print0 | xargs -0 -n1 -P"$BUILD_PARALLEL" php -l
    
    # Composer validation
    composer validate --strict
    
    # Run self-analysis if binary exists
    if [[ -f "bin/acqc" ]]; then
        log "Running self-analysis..."
        php bin/acqc scan . \
            --config=examples/configurations/production-config.json \
            --format=json \
            --output=build-quality.json \
            --exclude="vendor/*,var/*,build/*,dist/*" || {
            warn "Self-analysis found issues. Check build-quality.json"
        }
        
        # Check quality score
        if command -v jq &> /dev/null && [[ -f build-quality.json ]]; then
            QUALITY_SCORE=$(jq -r '.summary.quality_score // 0' build-quality.json)
            if (( $(echo "$QUALITY_SCORE < 8.0" | bc -l) )); then
                error "Quality gate failed: Score $QUALITY_SCORE < 8.0"
            fi
            log "Quality score: $QUALITY_SCORE/10"
        fi
    fi
    
    debug "Quality checks completed"
}

# ================================
# PHAR BUILD
# ================================
build_phar() {
    log "Building PHAR executable..."
    
    cd "$BUILD_DIR"
    
    # Create Box configuration if it doesn't exist
    if [[ ! -f "box.json" ]]; then
        cat > box.json << 'EOF'
{
    "main": "bin/m2-quality",
    "output": "m2-quality.phar",
    "directories": ["src", "config"],
    "files": ["bin/m2-quality"],
    "finder": [
        {
            "name": "*.php",
            "exclude": ["test", "tests", "Test", "Tests"],
            "in": "vendor"
        }
    ],
    "compression": "GZ",
    "compactors": [
        "KevinGH\\Box\\Compactor\\Php"
    ],
    "metadata": {
        "version": "@git-version@",
        "build_date": "@build-date@"
    },
    "stub": "stub.php"
}
EOF
    fi
    
    # Create stub file if it doesn't exist
    if [[ ! -f "stub.php" ]]; then
        cat > stub.php << 'EOF'
#!/usr/bin/env php
<?php
/**
 * M2-Quality PHAR Stub
 */

if (class_exists('Phar')) {
    Phar::mapPhar('m2-quality.phar');
    require_once 'phar://m2-quality.phar/bin/m2-quality';
} else {
    echo "PHP Phar extension is required to run M2-Quality.\n";
    exit(1);
}

__HALT_COMPILER();
EOF
    fi
    
    # Build PHAR
    box build --verbose
    
    # Verify PHAR
    if [[ -f "m2-quality.phar" ]]; then
        php m2-quality.phar --version
        log "PHAR built successfully: $(ls -lh m2-quality.phar | awk '{print $5}')"
        
        # Move to dist directory
        cp m2-quality.phar "$DIST_DIR/"
        
        # Generate checksum
        cd "$DIST_DIR"
        sha256sum m2-quality.phar > m2-quality.phar.sha256
        
    else
        error "PHAR build failed"
    fi
    
    debug "PHAR build completed"
}

# ================================
# SOURCE ARCHIVES
# ================================
build_source_archives() {
    log "Creating source archives..."
    
    cd "$PROJECT_ROOT"
    
    VERSION=$(get_version)
    
    # Create ZIP archive
    git archive --format=zip --prefix="m2-quality-$VERSION/" HEAD > "$DIST_DIR/m2-quality-$VERSION-source.zip"
    
    # Create TAR.GZ archive
    git archive --format=tar.gz --prefix="m2-quality-$VERSION/" HEAD > "$DIST_DIR/m2-quality-$VERSION-source.tar.gz"
    
    # Generate checksums
    cd "$DIST_DIR"
    sha256sum m2-quality-*-source.* > source-checksums.sha256
    
    log "Source archives created"
    debug "ZIP: $(ls -lh m2-quality-*-source.zip | awk '{print $5}')"
    debug "TAR.GZ: $(ls -lh m2-quality-*-source.tar.gz | awk '{print $5}')"
}

# ================================
# DOCUMENTATION BUILD
# ================================
build_documentation() {
    log "Building documentation..."
    
    cd "$BUILD_DIR"
    
    # Generate API documentation if phpDocumentor is available
    if command -v phpdoc &> /dev/null; then
        mkdir -p docs/api
        phpdoc run \
            -d src/ \
            -t docs/api/ \
            --title="M2-Quality API Documentation" \
            --template=responsive-twig
    else
        debug "phpDocumentor not found, skipping API docs"
    fi
    
    # Copy documentation to dist
    if [[ -d "docs" ]]; then
        cp -r docs "$DIST_DIR/"
    fi
    
    debug "Documentation build completed"
}

# ================================
# DOCKER IMAGE BUILD
# ================================
build_docker() {
    log "Building Docker images..."
    
    cd "$PROJECT_ROOT"
    
    if ! command -v docker &> /dev/null; then
        warn "Docker not found, skipping Docker build"
        return 0
    fi
    
    VERSION=$(get_version)
    BUILD_DATE=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    VCS_REF=$(git rev-parse --short HEAD)
    
    # Build production image
    docker build \
        --target runtime \
        --build-arg VERSION="$VERSION" \
        --build-arg BUILD_DATE="$BUILD_DATE" \
        --build-arg VCS_REF="$VCS_REF" \
        -t "m2quality/m2-quality:$VERSION" \
        -t "m2quality/m2-quality:latest" \
        -f docker/Dockerfile .
    
    # Build minimal image
    docker build \
        --target minimal \
        --build-arg VERSION="$VERSION" \
        --build-arg BUILD_DATE="$BUILD_DATE" \
        --build-arg VCS_REF="$VCS_REF" \
        -t "m2quality/m2-quality:$VERSION-minimal" \
        -f docker/Dockerfile .
    
    # Test images
    docker run --rm "m2quality/m2-quality:$VERSION" --version
    docker run --rm "m2quality/m2-quality:$VERSION-minimal" --version
    
    log "Docker images built successfully"
    debug "Images: m2quality/m2-quality:$VERSION, m2quality/m2-quality:$VERSION-minimal"
}

# ================================
# PERFORMANCE BENCHMARKS
# ================================
run_benchmarks() {
    if [[ "$SKIP_TESTS" == "true" ]]; then
        warn "Skipping benchmarks (SKIP_TESTS=true)"
        return 0
    fi
    
    log "Running performance benchmarks..."
    
    cd "$BUILD_DIR"
    
    if [[ -f "performance-demo.php" ]]; then
        php performance-demo.php --benchmark --output="$DIST_DIR/performance-baseline.json"
        log "Performance baseline saved"
    else
        debug "performance-demo.php not found, skipping benchmarks"
    fi
}

# ================================
# CLEANUP
# ================================
cleanup() {
    log "Cleaning up temporary files..."
    
    # Remove build directory
    if [[ -d "$BUILD_DIR" ]]; then
        rm -rf "$BUILD_DIR"
    fi
    
    # Clean composer cache
    composer clear-cache 2>/dev/null || true
    
    debug "Cleanup completed"
}

# ================================
# BUILD SUMMARY
# ================================
generate_build_summary() {
    log "Generating build summary..."
    
    VERSION=$(get_version)
    BUILD_DATE=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    
    cat > "$DIST_DIR/BUILD_INFO.txt" << EOF
M2-Quality Build Information
============================

Version: $VERSION
Build Date: $BUILD_DATE
Build Target: $BUILD_TARGET
Build Environment: $BUILD_ENV
Git Commit: $(git rev-parse HEAD 2>/dev/null || echo "unknown")
Git Branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")

Build System:
- OS: $(uname -s)
- PHP Version: $(php -r "echo PHP_VERSION;")
- Composer Version: $(composer --version --no-ansi)

Artifacts:
$(ls -la "$DIST_DIR")

Checksums:
$(cd "$DIST_DIR" && find . -name "*.sha256" -exec cat {} \; 2>/dev/null || echo "No checksums found")
EOF
    
    log "Build summary saved to BUILD_INFO.txt"
}

# ================================
# MAIN BUILD FUNCTION
# ================================
build_all() {
    setup_build_environment
    install_dependencies
    run_quality_checks
    build_phar
    build_source_archives
    build_documentation
    run_benchmarks
}

# ================================
# MAIN EXECUTION
# ================================
main() {
    log "Starting M2-Quality build process..."
    log "Target: $BUILD_TARGET | Environment: $BUILD_ENV"
    
    check_dependencies
    
    case "$BUILD_TARGET" in
        "all")
            build_all
            build_docker
            ;;
        "phar")
            setup_build_environment
            install_dependencies
            run_quality_checks
            build_phar
            ;;
        "docker")
            build_docker
            ;;
        "source")
            build_source_archives
            ;;
        "docs")
            setup_build_environment
            install_dependencies
            build_documentation
            ;;
        "clean")
            cleanup
            exit 0
            ;;
        *)
            error "Unknown build target: $BUILD_TARGET. Use: all, phar, docker, source, docs, clean"
            ;;
    esac
    
    generate_build_summary
    
    # Cleanup if not in debug mode
    if [[ "$VERBOSE" != "true" ]]; then
        cleanup
    fi
    
    log "Build completed successfully!"
    log "Artifacts available in: $DIST_DIR"
    
    # Show final artifacts
    echo ""
    echo "📦 Build Artifacts:"
    ls -la "$DIST_DIR"
}

# ================================
# SCRIPT ENTRY POINT
# ================================
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    # Handle script arguments
    case "${1:-}" in
        "-h"|"--help")
            cat << EOF
M2-Quality Build Script

Usage: $0 [target] [options]

Targets:
  all      Build everything (default)
  phar     Build PHAR executable only
  docker   Build Docker images only
  source   Create source archives only
  docs     Build documentation only
  clean    Clean build artifacts

Environment Variables:
  BUILD_ENV           Build environment (production, development) [default: production]
  BUILD_PARALLEL      Parallel jobs for testing [default: 4]
  SKIP_TESTS          Skip quality checks and tests [default: false]
  VERBOSE             Enable verbose output [default: false]

Examples:
  $0                    # Build everything
  $0 phar               # Build PHAR only
  BUILD_ENV=dev $0      # Build in development mode
  VERBOSE=true $0       # Build with verbose output
  SKIP_TESTS=true $0    # Build without running tests

EOF
            exit 0
            ;;
    esac
    
    # Run main function with error handling
    trap cleanup EXIT
    main "$@"
fi 