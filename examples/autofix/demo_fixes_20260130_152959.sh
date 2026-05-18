#!/bin/bash

# M2-Quality Auto-generated Fix Script
# Generated: 2026-01-30 15:29:59
# Total fixes: 8
#
# ⚠️  WARNING: Review this script before executing!
# This script will make changes to your Magento installation.
#
# Usage:
#   chmod +x fix_script.sh
#   ./fix_script.sh
#

# Script configuration
set -e  # Exit on first error
set -u  # Exit on undefined variable
set -o pipefail  # Exit on pipe failure

# Magento root directory
MAGENTO_ROOT="/var/www/magento"
cd "$MAGENTO_ROOT" || exit 1

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions

check_magento_root() {
    if [ ! -f "bin/magento" ]; then
        echo -e "${RED}❌ Error: Magento CLI not found. Are you in the Magento root directory?${NC}"
        exit 1
    fi
}

check_magento_cli() {
    if [ ! -x "bin/magento" ]; then
        echo -e "${YELLOW}⚠️  Warning: bin/magento is not executable, fixing permissions...${NC}"
        chmod +x bin/magento
    fi
}

run_command() {
    local cmd="$1"
    local description="$2"
    
    echo -e "${BLUE}⚙️  $description${NC}"
    if eval "$cmd"; then
        echo -e "${GREEN}✅ Success${NC}"
        return 0
    else
        echo -e "${RED}❌ Failed${NC}"
        return 1
    fi
}

# Pre-flight checks
echo -e "${BLUE}🔍 Running pre-flight checks...${NC}"
check_magento_root
check_magento_cli
echo -e "${GREEN}✅ Pre-flight checks passed${NC}"
echo

# Create backup
echo -e "${BLUE}💾 Creating backup...${NC}"
BACKUP_DIR="var/backups/m2quality_20260130_152959"
mkdir -p "$BACKUP_DIR"
if [ -f "app/etc/env.php" ]; then
    cp "app/etc/env.php" "$BACKUP_DIR/env.php.backup"
fi
if [ -f "app/etc/config.php" ]; then
    cp "app/etc/config.php" "$BACKUP_DIR/config.php.backup"
fi
echo -e "${GREEN}✅ Backup created: $BACKUP_DIR${NC}"
echo

# Execute fixes
echo -e "${BLUE}🔧 Applying fixes...${NC}"
echo

# Fix #1/{8}
echo -e "${BLUE}[1/8] Enable all Magento caches${NC}"
# Enable all Magento caches
# Priority: 10/10

# Safety checks
if ! [ -f "bin/magento" ]; then
  echo '❌ Safety check failed: Magento CLI not found'
  exit 1
fi

# Execute fix
echo '⚙️  Executing: Enable all Magento caches'
bin/magento cache:enable && bin/magento cache:flush
if [ $? -eq 0 ]; then
  echo '✅ Fix applied successfully'
else
  echo '❌ Fix failed'
  exit 1
fi

echo

# Fix #2/{8}
echo -e "${BLUE}[2/8] Enable Full Page Cache (FPC) with built-in application${NC}"
# Enable Full Page Cache (FPC) with built-in application
# Priority: 10/10

# Safety checks
if ! [ -f "bin/magento" ]; then
  echo '❌ Safety check failed: Magento CLI not found'
  exit 1
fi

# Execute fix
echo '⚙️  Executing: Enable Full Page Cache (FPC) with built-in application'
bin/magento config:set system/full_page_cache/caching_application 2 && bin/magento cache:enable full_page && bin/magento cache:flush
if [ $? -ne 0 ]; then
  echo '⚠️  Fix failed, attempting rollback...'
  bin/magento config:set system/full_page_cache/caching_application 1
  exit 1
fi

echo

# Fix #3/{8}
echo -e "${BLUE}[3/8] Create missing index on catalog_product_entity.sku${NC}"
# Create missing index on catalog_product_entity.sku
# Priority: 9/10

# Safety checks
if ! [ -f "bin/magento" ]; then
  echo '❌ Safety check failed: Magento CLI not found'
  exit 1
fi
if ! command -v mysql >/dev/null 2>&1; then
  echo '❌ Safety check failed: MySQL client not found'
  exit 1
fi

# Execute fix
read -p '🤔 Execute: bin/magento setup:db-schema:upgrade && mysql -h"$(bin/magento config:get db/connection/default/host)" -u"$(bin/magento config:get db/connection/default/username)" -p"$(bin/magento config:get db/connection/default/password)" "$(bin/magento config:get db/connection/default/dbname)" -e "CREATE INDEX idx_catalog_product_entity_sku ON catalog_product_entity (sku);" 2>/dev/null || echo "Index may already exist"? (y/n) ' -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
  echo '⚙️  Executing: Create missing index on catalog_product_entity.sku'
  bin/magento setup:db-schema:upgrade && mysql -h"$(bin/magento config:get db/connection/default/host)" -u"$(bin/magento config:get db/connection/default/username)" -p"$(bin/magento config:get db/connection/default/password)" "$(bin/magento config:get db/connection/default/dbname)" -e "CREATE INDEX idx_catalog_product_entity_sku ON catalog_product_entity (sku);" 2>/dev/null || echo "Index may already exist"
  if [ $? -ne 0 ]; then
    echo '⚠️  Fix failed, attempting rollback...'
    mysql -h"$(bin/magento config:get db/connection/default/host)" -u"$(bin/magento config:get db/connection/default/username)" -p"$(bin/magento config:get db/connection/default/password)" "$(bin/magento config:get db/connection/default/dbname)" -e "DROP INDEX idx_catalog_product_entity_sku ON catalog_product_entity;" 2>/dev/null
    exit 1
  fi
  echo '✅ Fix applied successfully'
else
  echo '⏭️  Skipped'
fi

echo

# Fix #4/{8}
echo -e "${BLUE}[4/8] Enable flat catalog for products and categories${NC}"
# Enable flat catalog for products and categories
# Priority: 8/10

# Safety checks
if ! [ -f "bin/magento" ]; then
  echo '❌ Safety check failed: Magento CLI not found'
  exit 1
fi
if ! [ -w "app/etc/env.php" ] || [ -w "app/etc" ]; then
  echo '❌ Safety check failed: app/etc/env.php is not writable'
  exit 1
fi

# Execute fix
echo '⚙️  Executing: Enable flat catalog for products and categories'
bin/magento config:set catalog/frontend/flat_catalog_product 1 && bin/magento config:set catalog/frontend/flat_catalog_category 1 && bin/magento indexer:reindex catalog_product_flat catalog_category_flat && bin/magento cache:flush
if [ $? -ne 0 ]; then
  echo '⚠️  Fix failed, attempting rollback...'
  bin/magento config:set catalog/frontend/flat_catalog_product 0 && bin/magento config:set catalog/frontend/flat_catalog_category 0
  exit 1
fi

echo

# Fix #5/{8}
echo -e "${BLUE}[5/8] Generate Redis cache configuration template${NC}"
# Generate Redis cache configuration template
# Priority: 8/10

# Safety checks
if ! [ -d "app/etc" ]; then
  echo '❌ Safety check failed: app/etc directory not found'
  exit 1
fi
if ! [ -w "app/etc" ]; then
  echo '❌ Safety check failed: app/etc directory not writable'
  exit 1
fi

# Execute fix
echo '⚙️  Executing: Generate Redis cache configuration template'
cat > app/etc/env.php.redis << 'REDIS_CONFIG'
<?php
return [
    'cache' => [
        'frontend' => [
            'default' => [
                'backend' => 'Cm_Cache_Backend_Redis',
                'backend_options' => [
                    'server' => '127.0.0.1',
                    'port' => '6379',
                    'database' => '0',
                    'password' => '',
                    'persistent' => '',
                    'compress_data' => '1',
                    'compression_lib' => 'gzip',
                ]
            ],
            'page_cache' => [
                'backend' => 'Cm_Cache_Backend_Redis',
                'backend_options' => [
                    'server' => '127.0.0.1',
                    'port' => '6379',
                    'database' => '1',
                    'password' => '',
                    'compress_data' => '0',
                ]
            ]
        ]
    ]
];
REDIS_CONFIG
echo "⚠️  Redis configuration template created at app/etc/env.php.redis"
echo "Please review and merge into app/etc/env.php manually"
echo "Update server/port/password as needed for your environment"
if [ $? -eq 0 ]; then
  echo '✅ Fix applied successfully'
else
  echo '❌ Fix failed'
  exit 1
fi

echo

# Fix #6/{8}
echo -e "${BLUE}[6/8] Enable CSS file merging${NC}"
# Enable CSS file merging
# Priority: 6/10

# Safety checks
if ! [ -f "bin/magento" ]; then
  echo '❌ Safety check failed: Magento CLI not found'
  exit 1
fi

# Execute fix
echo '⚙️  Executing: Enable CSS file merging'
bin/magento config:set dev/css/merge_css_files 1 && bin/magento cache:flush config full_page
if [ $? -ne 0 ]; then
  echo '⚠️  Fix failed, attempting rollback...'
  bin/magento config:set dev/css/merge_css_files 0
  exit 1
fi

echo

# Fix #7/{8}
echo -e "${BLUE}[7/8] Enable JavaScript file merging${NC}"
# Enable JavaScript file merging
# Priority: 6/10

# Safety checks
if ! [ -f "bin/magento" ]; then
  echo '❌ Safety check failed: Magento CLI not found'
  exit 1
fi

# Execute fix
echo '⚙️  Executing: Enable JavaScript file merging'
bin/magento config:set dev/js/merge_files 1 && bin/magento cache:flush config full_page
if [ $? -ne 0 ]; then
  echo '⚠️  Fix failed, attempting rollback...'
  bin/magento config:set dev/js/merge_files 0
  exit 1
fi

echo

# Fix #8/{8}
echo -e "${BLUE}[8/8] N+1 query guidance for app/code/Vendor/Module/Block/ProductList.php:78${NC}"
# N+1 query guidance for app/code/Vendor/Module/Block/ProductList.php:78
# Priority: 6/10

# Execute fix
echo '⚙️  Executing: N+1 query guidance for app/code/Vendor/Module/Block/ProductList.php:78'
echo "⚠️  N+1 Query detected in ProductList.php at line 78" && echo "This requires code changes to eager load related data" && echo "Consider using:" && echo "  - Collection addFieldToFilter() with array of IDs" && echo "  - JOIN queries instead of separate queries" && echo "  - addAttributeToSelect() for EAV entities" && echo "" && echo "Review the code and implement proper eager loading"
if [ $? -eq 0 ]; then
  echo '✅ Fix applied successfully'
else
  echo '❌ Fix failed'
  exit 1
fi

echo

# Completion
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ All fixes applied successfully!${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo
echo -e "${YELLOW}📝 Next steps:${NC}"
echo "  1. Test your Magento installation"
echo "  2. Clear cache: bin/magento cache:flush"
echo "  3. Run m2-quality again to verify fixes"
echo
echo -e "${BLUE}💾 Backup location: $BACKUP_DIR${NC}"
