<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix\Generators;

use ACQC\Core\AutoFix\FixCommand;
use ACQC\Core\AutoFix\FixGeneratorInterface;
use ACQC\Core\Rules\Violation;

/**
 * Fix generator for cache configuration and optimization
 * 
 * Generates fix commands for:
 * - Redis configuration
 * - Cache backend optimization
 * - Full page cache setup
 * - Session storage
 */
class CacheFixGenerator implements FixGeneratorInterface
{
    private const FIXABLE_RULES = [
        'cache-redis-not-configured',
        'cache-file-backend-in-production',
        'cache-session-not-redis',
        'cache-fpc-not-enabled',
        'cache-varnish-not-configured',
        'performance-cache-disabled',
        'performance-redis-suboptimal',
    ];
    
    public function getName(): string
    {
        return 'cache';
    }
    
    public function getSupportedRules(): array
    {
        return self::FIXABLE_RULES;
    }
    
    public function supports(Violation $violation): bool
    {
        return in_array($violation->getRuleId(), self::FIXABLE_RULES, true);
    }
    
    public function generate(Violation $violation): ?FixCommand
    {
        return match($violation->getRuleId()) {
            'cache-redis-not-configured',
            'performance-redis-suboptimal' => $this->fixRedisConfiguration(),
            
            'cache-file-backend-in-production' => $this->fixFileBackend(),
            
            'cache-session-not-redis' => $this->fixSessionStorage(),
            
            'cache-fpc-not-enabled' => $this->fixFullPageCache(),
            
            'cache-varnish-not-configured' => $this->fixVarnishConfiguration(),
            
            'performance-cache-disabled' => $this->fixCacheDisabled(),
            
            default => null
        };
    }
    
    private function fixRedisConfiguration(): FixCommand
    {
        return new FixCommand(
            command: 'cat > app/etc/env.php.redis << \'REDIS_CONFIG\'
<?php
return [
    \'cache\' => [
        \'frontend\' => [
            \'default\' => [
                \'backend\' => \'Cm_Cache_Backend_Redis\',
                \'backend_options\' => [
                    \'server\' => \'127.0.0.1\',
                    \'port\' => \'6379\',
                    \'database\' => \'0\',
                    \'password\' => \'\',
                    \'persistent\' => \'\',
                    \'compress_data\' => \'1\',
                    \'compression_lib\' => \'gzip\',
                ]
            ],
            \'page_cache\' => [
                \'backend\' => \'Cm_Cache_Backend_Redis\',
                \'backend_options\' => [
                    \'server\' => \'127.0.0.1\',
                    \'port\' => \'6379\',
                    \'database\' => \'1\',
                    \'password\' => \'\',
                    \'compress_data\' => \'0\',
                ]
            ]
        ]
    ]
];
REDIS_CONFIG
echo "⚠️  Redis configuration template created at app/etc/env.php.redis"
echo "Please review and merge into app/etc/env.php manually"
echo "Update server/port/password as needed for your environment"',
            description: 'Generate Redis cache configuration template',
            safetyChecks: [
                [
                    'condition' => '[ -d "app/etc" ]',
                    'message' => 'app/etc directory not found'
                ],
                [
                    'condition' => '[ -w "app/etc" ]',
                    'message' => 'app/etc directory not writable'
                ],
            ],
            priority: 8
        );
    }
    
    private function fixFileBackend(): FixCommand
    {
        return new FixCommand(
            command: 'echo "⚠️  Production environment should use Redis for caching" && ' .
                    'echo "Current backend: File-based cache" && ' .
                    'echo "" && ' .
                    'echo "Recommended actions:" && ' .
                    'echo "  1. Install Redis: apt-get install redis-server (Ubuntu) or yum install redis (CentOS)" && ' .
                    'echo "  2. Install Redis PHP extension: pecl install redis" && ' .
                    'echo "  3. Configure Magento to use Redis (see cache-redis-not-configured fix)" && ' .
                    'echo "" && ' .
                    'echo "For now, optimizing file cache cleanup..." && ' .
                    'bin/magento cache:clean && ' .
                    'echo "Consider scheduling: bin/magento cache:clean in cron every hour"',
            description: 'Guidance for migrating from file-based cache to Redis',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            priority: 7
        );
    }
    
    private function fixSessionStorage(): FixCommand
    {
        return new FixCommand(
            command: 'cat > app/etc/env.php.session << \'SESSION_CONFIG\'
<?php
return [
    \'session\' => [
        \'save\' => \'redis\',
        \'redis\' => [
            \'host\' => \'127.0.0.1\',
            \'port\' => \'6379\',
            \'password\' => \'\',
            \'timeout\' => \'2.5\',
            \'persistent_identifier\' => \'\',
            \'database\' => \'2\',
            \'compression_threshold\' => \'2048\',
            \'compression_library\' => \'gzip\',
            \'log_level\' => \'1\',
            \'max_concurrency\' => \'6\',
            \'break_after_frontend\' => \'5\',
            \'break_after_adminhtml\' => \'30\',
            \'first_lifetime\' => \'600\',
            \'bot_first_lifetime\' => \'60\',
            \'bot_lifetime\' => \'7200\',
            \'disable_locking\' => \'0\',
            \'min_lifetime\' => \'60\',
            \'max_lifetime\' => \'2592000\'
        ]
    ]
];
SESSION_CONFIG
echo "⚠️  Redis session configuration template created at app/etc/env.php.session"
echo "Please review and merge into app/etc/env.php manually"
echo "Update server/port/password as needed for your environment"',
            description: 'Generate Redis session storage configuration template',
            safetyChecks: [
                [
                    'condition' => '[ -d "app/etc" ]',
                    'message' => 'app/etc directory not found'
                ],
                [
                    'condition' => '[ -w "app/etc" ]',
                    'message' => 'app/etc directory not writable'
                ],
            ],
            priority: 7
        );
    }
    
    private function fixFullPageCache(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set system/full_page_cache/caching_application 2 && ' .
                    'bin/magento cache:enable full_page && ' .
                    'bin/magento cache:flush',
            description: 'Enable Full Page Cache (FPC) with built-in application',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set system/full_page_cache/caching_application 1',
            priority: 10  // Highest priority - FPC is critical
        );
    }
    
    private function fixVarnishConfiguration(): FixCommand
    {
        return new FixCommand(
            command: 'echo "⚠️  Varnish configuration requires manual setup" && ' .
                    'echo "" && ' .
                    'echo "Steps to configure Varnish:" && ' .
                    'echo "  1. Generate VCL file: bin/magento varnish:vcl:generate > /etc/varnish/default.vcl" && ' .
                    'echo "  2. Update Varnish backend port in VCL (usually 8080)" && ' .
                    'echo "  3. Restart Varnish: systemctl restart varnish" && ' .
                    'echo "  4. Configure Magento: bin/magento config:set system/full_page_cache/caching_application 2" && ' .
                    'echo "  5. Set Varnish host: bin/magento config:set system/full_page_cache/varnish/backend_host 127.0.0.1" && ' .
                    'echo "  6. Set Varnish port: bin/magento config:set system/full_page_cache/varnish/backend_port 80" && ' .
                    'echo "" && ' .
                    'echo "Generating VCL template..." && ' .
                    'bin/magento varnish:vcl:generate --export-version=6 > var/varnish6.vcl 2>/dev/null && ' .
                    'echo "✅ VCL template saved to var/varnish6.vcl" || ' .
                    'echo "⚠️  VCL generation failed. Ensure Varnish module is installed."',
            description: 'Generate Varnish VCL configuration template',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
                [
                    'condition' => '[ -d "var" ]',
                    'message' => 'var directory not found'
                ],
            ],
            priority: 6
        );
    }
    
    private function fixCacheDisabled(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento cache:status && ' .
                    'echo "" && ' .
                    'echo "Enabling all caches..." && ' .
                    'bin/magento cache:enable && ' .
                    'bin/magento cache:clean && ' .
                    'echo "" && ' .
                    'bin/magento cache:status',
            description: 'Enable all disabled caches',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento cache:disable',
            priority: 10  // Critical for performance
        );
    }
}
