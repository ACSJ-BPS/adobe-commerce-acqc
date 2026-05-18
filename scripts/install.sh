#!/bin/bash
#
# M2-Quality Installation Script
# Automated installation for all platforms
#

set -euo pipefail

# Configuration
INSTALL_METHOD="${1:-auto}"
INSTALL_DIR="${INSTALL_DIR:-/usr/local/bin}"
TEMP_DIR="/tmp/m2quality-install"
GITHUB_REPO="m2quality/m2-quality"
VERSION="${VERSION:-latest}"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo -e "${GREEN}[INFO] $1${NC}"; }
warn() { echo -e "${YELLOW}[WARN] $1${NC}"; }
error() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }

# Detect system
detect_system() {
    OS=$(uname -s | tr '[:upper:]' '[:lower:]')
    ARCH=$(uname -m)
    
    case $ARCH in
        x86_64) ARCH="amd64" ;;
        aarch64|arm64) ARCH="arm64" ;;
        *) error "Unsupported architecture: $ARCH" ;;
    esac
    
    log "Detected system: $OS-$ARCH"
}

# Check dependencies
check_dependencies() {
    if ! command -v php &> /dev/null; then
        error "PHP 8.1+ is required"
    fi
    
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    if php -r "exit(version_compare(PHP_VERSION, '8.1.0', '<') ? 1 : 0);"; then
        error "PHP 8.1.0+ required. Found: $PHP_VERSION"
    fi
    
    log "PHP version check passed: $PHP_VERSION"
}

# Install via Composer
install_composer() {
    log "Installing via Composer..."
    
    if ! command -v composer &> /dev/null; then
        error "Composer not found. Please install Composer first."
    fi
    
    composer global require m2quality/m2-quality
    
    # Add to PATH if needed
    COMPOSER_BIN_DIR=$(composer global config bin-dir --absolute)
    if [[ ":$PATH:" != *":$COMPOSER_BIN_DIR:"* ]]; then
        warn "Add $COMPOSER_BIN_DIR to your PATH"
        echo "export PATH=\"\$PATH:$COMPOSER_BIN_DIR\"" >> ~/.bashrc
    fi
    
    log "Installation completed via Composer"
}

# Install PHAR
install_phar() {
    log "Installing PHAR executable..."
    
    mkdir -p "$TEMP_DIR"
    cd "$TEMP_DIR"
    
    # Download latest PHAR
    if [[ "$VERSION" == "latest" ]]; then
        URL="https://github.com/$GITHUB_REPO/releases/latest/download/m2-quality.phar"
    else
        URL="https://github.com/$GITHUB_REPO/releases/download/v$VERSION/m2-quality.phar"
    fi
    
    log "Downloading from: $URL"
    curl -fsSL "$URL" -o m2-quality.phar
    
    # Verify download
    if [[ ! -f "m2-quality.phar" ]] || [[ ! -s "m2-quality.phar" ]]; then
        error "Failed to download PHAR"
    fi
    
    # Make executable
    chmod +x m2-quality.phar
    
    # Test PHAR
    php m2-quality.phar --version || error "PHAR verification failed"
    
    # Install to system
    if [[ -w "$INSTALL_DIR" ]]; then
        cp m2-quality.phar "$INSTALL_DIR/m2-quality"
    else
        sudo cp m2-quality.phar "$INSTALL_DIR/m2-quality"
    fi
    
    log "PHAR installed to $INSTALL_DIR/m2-quality"
    
    # Cleanup
    rm -rf "$TEMP_DIR"
}

# Install via Docker
install_docker() {
    log "Setting up Docker installation..."
    
    if ! command -v docker &> /dev/null; then
        error "Docker not found. Please install Docker first."
    fi
    
    # Pull latest image
    docker pull ghcr.io/m2quality/m2-quality:latest
    
    # Create wrapper script
    cat > "$INSTALL_DIR/m2-quality" << 'EOF'
#!/bin/bash
docker run --rm -v $(pwd):/project ghcr.io/m2quality/m2-quality:latest "$@"
EOF
    
    chmod +x "$INSTALL_DIR/m2-quality"
    
    log "Docker installation completed"
}

# Auto-detect best method
install_auto() {
    if command -v composer &> /dev/null; then
        install_composer
    elif command -v docker &> /dev/null; then
        install_docker
    else
        install_phar
    fi
}

# Main installation
main() {
    log "Starting M2-Quality installation..."
    
    detect_system
    check_dependencies
    
    case "$INSTALL_METHOD" in
        "composer") install_composer ;;
        "phar") install_phar ;;
        "docker") install_docker ;;
        "auto") install_auto ;;
        *) error "Unknown method: $INSTALL_METHOD" ;;
    esac
    
    # Verify installation
    if command -v m2-quality &> /dev/null; then
        log "Installation successful!"
        m2-quality --version
    else
        error "Installation verification failed"
    fi
}

# Help
if [[ "${1:-}" == "--help" ]] || [[ "${1:-}" == "-h" ]]; then
    cat << EOF
M2-Quality Installation Script

Usage: $0 [method]

Methods:
  auto      Auto-detect best method (default)
  composer  Install via Composer
  phar      Install PHAR executable
  docker    Install via Docker

Environment Variables:
  VERSION       Version to install (default: latest)
  INSTALL_DIR   Installation directory (default: /usr/local/bin)

Examples:
  curl -fsSL https://install.m2quality.dev | bash
  curl -fsSL https://install.m2quality.dev | bash -s composer
  VERSION=2.1.0 ./install.sh phar

EOF
    exit 0
fi

main "$@" 