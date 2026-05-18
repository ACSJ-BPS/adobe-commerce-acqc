# M2-Quality Docker Image
# Multi-stage build for optimized production image

# ================================
# BUILD STAGE
# ================================
FROM php:8.1-cli-alpine AS builder

# Build arguments
ARG VERSION=dev
ARG BUILD_DATE
ARG VCS_REF

# Install build dependencies
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    curl \
    bash \
    make \
    && docker-php-ext-install \
    json \
    mbstring \
    xml \
    curl \
    zip \
    opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --optimize-autoloader \
    --prefer-dist \
    --no-interaction

# Copy application source
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Build PHAR if build script exists
RUN if [ -f scripts/build.sh ]; then \
        chmod +x scripts/build.sh && \
        ./scripts/build.sh; \
    fi

# ================================
# RUNTIME STAGE
# ================================
FROM php:8.1-cli-alpine AS runtime

# Metadata
LABEL maintainer="M2-Quality Team <team@m2quality.dev>"
LABEL org.opencontainers.image.title="M2-Quality"
LABEL org.opencontainers.image.description="Advanced Magento 2 Code Quality Analysis Tool"
LABEL org.opencontainers.image.version="${VERSION}"
LABEL org.opencontainers.image.created="${BUILD_DATE}"
LABEL org.opencontainers.image.revision="${VCS_REF}"
LABEL org.opencontainers.image.source="https://github.com/m2quality/m2-quality"
LABEL org.opencontainers.image.url="https://m2quality.dev"
LABEL org.opencontainers.image.documentation="https://docs.m2quality.dev"
LABEL org.opencontainers.image.licenses="MIT"

# Install runtime dependencies
RUN apk add --no-cache \
    git \
    bash \
    curl \
    ca-certificates \
    && docker-php-ext-install \
    opcache \
    && docker-php-ext-enable opcache

# Configure PHP for optimal performance
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory.ini \
    && echo "opcache.enable = 1" > /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.enable_cli = 1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption = 128" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer = 8" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files = 4000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq = 2" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.fast_shutdown = 1" >> /usr/local/etc/php/conf.d/opcache.ini

# Create non-root user for security
RUN addgroup -g 1000 m2quality \
    && adduser -u 1000 -G m2quality -s /bin/bash -D m2quality

# Set working directory
WORKDIR /app

# Copy application from builder stage
COPY --from=builder --chown=m2quality:m2quality /app .

# Create cache and reports directories
RUN mkdir -p /app/var/cache /app/reports \
    && chown -R m2quality:m2quality /app/var /app/reports

# Install global tools that might be needed
RUN curl -OL https://squizlabs.github.io/PHP_CodeSniffer/phpcs.phar \
    && curl -OL https://squizlabs.github.io/PHP_CodeSniffer/phpcbf.phar \
    && curl -OL https://github.com/phpmd/phpmd/releases/latest/download/phpmd.phar \
    && curl -OL https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar \
    && chmod +x *.phar \
    && mv *.phar /usr/local/bin/

# Switch to non-root user
USER m2quality

# Set environment variables
ENV PATH="/app/bin:${PATH}"
ENV M2QUALITY_CACHE_DIR="/app/var/cache"
ENV M2QUALITY_REPORTS_DIR="/app/reports"

# Expose volume for analysis
VOLUME ["/project"]

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php bin/m2-quality --version || exit 1

# Default command
ENTRYPOINT ["php", "bin/m2-quality"]
CMD ["--help"]

# ================================
# DEVELOPMENT STAGE
# ================================
FROM runtime AS development

# Switch back to root for development tools installation
USER root

# Install development dependencies
RUN apk add --no-cache \
    vim \
    nano \
    htop \
    strace \
    tcpdump \
    && docker-php-ext-install \
    xdebug

# Install additional development tools
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Xdebug for development
RUN echo "xdebug.mode = debug,coverage" > /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.start_with_request = yes" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.client_host = host.docker.internal" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.client_port = 9003" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.log = /tmp/xdebug.log" >> /usr/local/etc/php/conf.d/xdebug.ini

# Copy development dependencies from builder
COPY --from=builder --chown=m2quality:m2quality /app/vendor /app/vendor

# Switch back to application user
USER m2quality

# Override default command for development
CMD ["bash"]

# ================================
# CI STAGE (for CI/CD pipelines)
# ================================
FROM development AS ci

# Switch to root for CI tools
USER root

# Install CI-specific tools
RUN apk add --no-cache \
    jq \
    bc \
    rsync \
    openssh-client \
    && curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" \
    -o /usr/local/bin/docker-compose \
    && chmod +x /usr/local/bin/docker-compose

# Install additional analysis tools
RUN curl -OL https://github.com/FriendsOfPHP/PHP-CS-Fixer/releases/latest/download/php-cs-fixer.phar \
    && curl -OL https://github.com/phpmetrics/PhpMetrics/releases/latest/download/phpmetrics.phar \
    && curl -OL https://github.com/pdepend/pdepend/releases/latest/download/pdepend.phar \
    && chmod +x *.phar \
    && mv *.phar /usr/local/bin/

# Create CI user with sudo access
RUN adduser ci -D -G wheel \
    && echo '%wheel ALL=(ALL) NOPASSWD: ALL' >> /etc/sudoers

# Switch to CI user
USER ci

# Set CI-specific environment variables
ENV CI=true
ENV M2QUALITY_ENV=ci
ENV M2QUALITY_LOG_LEVEL=debug

# Override command for CI
CMD ["php", "run-tests.php"]

# ================================
# MINIMAL STAGE (smallest possible image)
# ================================
FROM php:8.1-cli-alpine AS minimal

# Install only essential runtime dependencies
RUN apk add --no-cache \
    git \
    && docker-php-ext-install opcache

# Configure minimal PHP settings
RUN echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/memory.ini \
    && echo "opcache.enable_cli = 1" > /usr/local/etc/php/conf.d/opcache.ini

# Copy only the PHAR executable
COPY --from=builder /app/m2-quality.phar /usr/local/bin/m2-quality
RUN chmod +x /usr/local/bin/m2-quality

# Create minimal user
RUN adduser -D -s /bin/sh m2quality
USER m2quality

# Set working directory
WORKDIR /project

# Volume for project analysis
VOLUME ["/project"]

# Minimal entrypoint
ENTRYPOINT ["m2-quality"]
CMD ["--help"]

# Health check
HEALTHCHECK --interval=60s --timeout=5s --start-period=5s --retries=2 \
    CMD m2-quality --version || exit 1 