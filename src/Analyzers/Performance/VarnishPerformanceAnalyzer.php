<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;

/**
 * Analyzes Varnish cache performance and configuration
 * 
 * Monitors Varnish hit rates, ESI usage, grace mode, and VCL configuration
 * to ensure optimal Full Page Cache performance.
 */
class VarnishPerformanceAnalyzer extends AbstractAnalyzer
{
    private const RULE_LOW_HIT_RATE = 'varnish-low-hit-rate';
    private const RULE_NO_ESI = 'varnish-no-esi-usage';
    private const RULE_NO_GRACE_MODE = 'varnish-no-grace-mode';
    private const RULE_CACHE_BANS_HIGH = 'varnish-high-ban-rate';
    private const RULE_BACKEND_UNHEALTHY = 'varnish-backend-unhealthy';
    
    private const MIN_HIT_RATE = 80.0; // 80% hit rate is minimum acceptable
    private const RECOMMENDED_GRACE_SECONDS = 300; // 5 minutes
    
    public function getName(): string
    {
        return 'varnish-performance';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function isAvailable(): bool
    {
        // Check if varnishstat command is available
        return $this->commandExists('varnishstat');
    }
    
    protected function getDefaultConfiguration(): array
    {
        return [
            'check_hit_rate' => true,
            'check_esi_usage' => true,
            'check_grace_mode' => true,
            'check_ban_rate' => true,
            'check_backend_health' => true,
            'min_hit_rate' => self::MIN_HIT_RATE,
            'varnishstat_path' => 'varnishstat',
            'varnishadm_path' => 'varnishadm',
        ];
    }
    
    protected function executeAnalysis(array $files, array $options = []): array
    {
        // Varnish analysis is runtime-based, not file-based
        return [];
    }
    
    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }
    
    /**
     * Main analysis method
     */
    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        
        if (!$this->isAvailable()) {
            $this->logger->info('Varnish not available, skipping analysis');
            return $violations;
        }
        
        // Get Varnish statistics
        $stats = $this->getVarnishStats();
        
        if (empty($stats)) {
            $this->logger->warning('Could not retrieve Varnish statistics');
            return $violations;
        }
        
        // Check hit rate
        if ($this->configuration['check_hit_rate']) {
            $hitRateViolation = $this->checkHitRate($stats);
            if ($hitRateViolation) {
                $violations[] = $hitRateViolation;
            }
        }
        
        // Check ESI usage
        if ($this->configuration['check_esi_usage']) {
            $esiViolation = $this->checkESIUsage($stats);
            if ($esiViolation) {
                $violations[] = $esiViolation;
            }
        }
        
        // Check grace mode
        if ($this->configuration['check_grace_mode']) {
            $graceViolation = $this->checkGraceMode($stats);
            if ($graceViolation) {
                $violations[] = $graceViolation;
            }
        }
        
        // Check ban rate
        if ($this->configuration['check_ban_rate']) {
            $banViolation = $this->checkBanRate($stats);
            if ($banViolation) {
                $violations[] = $banViolation;
            }
        }
        
        // Check backend health
        if ($this->configuration['check_backend_health']) {
            $backendViolations = $this->checkBackendHealth();
            $violations = array_merge($violations, $backendViolations);
        }
        
        return $violations;
    }
    
    private function getVarnishStats(): array
    {
        $command = $this->configuration['varnishstat_path'] . ' -1 -j 2>&1';
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            return [];
        }
        
        $json = implode("\n", $output);
        $stats = json_decode($json, true);
        
        return $stats ?? [];
    }
    
    private function checkHitRate(array $stats): ?Violation
    {
        // Calculate hit rate from statistics
        $hits = $stats['MAIN.cache_hit']['value'] ?? 0;
        $misses = $stats['MAIN.cache_miss']['value'] ?? 0;
        $total = $hits + $misses;
        
        if ($total === 0) {
            return null; // Not enough data yet
        }
        
        $hitRate = ($hits / $total) * 100;
        $minRate = $this->configuration['min_hit_rate'];
        
        if ($hitRate < $minRate) {
            $violation = new Violation(
                self::RULE_LOW_HIT_RATE,
                sprintf(
                    'Varnish hit rate is %.1f%% but should be at least %.1f%%. ' .
                    'Low hit rates indicate cache inefficiency, causing more backend requests and slower performance. ' .
                    'Common causes: (1) Non-cacheable blocks in layout, (2) Cookies preventing caching, ' .
                    '(3) Inadequate cache TTL, (4) Too many cache purges.',
                    $hitRate,
                    $minRate
                ),
                'varnish',
                0,
                0,
                $hitRate < 60 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'hit_rate' => round($hitRate, 2),
                'hits' => $hits,
                'misses' => $misses,
                'total_requests' => $total,
                'impact' => $hitRate < 60 ? 'high' : 'medium',
            ]);
            
            $violation->setSuggestion(
                "To improve Varnish hit rate:\n\n" .
                "1. Find non-cacheable blocks:\n" .
                "   Run LayoutCacheAnalyzer to identify blocks with cacheable=\"false\"\n\n" .
                "2. Review VCL for cookie handling:\n" .
                "   Ensure proper cookie stripping for static content\n" .
                "   Example VCL:\n" .
                "   if (req.url ~ \"^/(pub|static)/\") {\n" .
                "       unset req.http.Cookie;\n" .
                "   }\n\n" .
                "3. Check cache TTL:\n" .
                "   Increase Cache-Control max-age for cacheable content\n" .
                "   bin/magento config:set system/full_page_cache/ttl 86400\n\n" .
                "4. Monitor cache purges:\n" .
                "   varnishlog -g request -q 'ReqMethod eq \"PURGE\"'\n\n" .
                "5. Review Vary headers:\n" .
                "   Too many Vary headers fragment the cache\n" .
                "   Check: varnishlog -g request -q 'VCL_call eq \"HASH\"'"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkESIUsage(array $stats): ?Violation
    {
        $esiErrors = $stats['MAIN.esi_errors']['value'] ?? 0;
        $esiWarnings = $stats['MAIN.esi_warnings']['value'] ?? 0;
        
        // Check if ESI is being used (has processing stats)
        $hasESIActivity = isset($stats['MAIN.esi_req']) && $stats['MAIN.esi_req']['value'] > 0;
        
        if (!$hasESIActivity) {
            $violation = new Violation(
                self::RULE_NO_ESI,
                'Varnish ESI (Edge Side Includes) is not being used. ' .
                'ESI allows caching pages with dynamic blocks, significantly improving cache hit rates for personalized content. ' .
                'Without ESI, entire pages with any dynamic content cannot be cached.',
                'varnish',
                0,
                0,
                Violation::SEVERITY_INFO,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Enable Varnish ESI for better cache granularity:\n\n" .
                "1. Enable ESI in Magento:\n" .
                "   Store > Configuration > Advanced > System > Full Page Cache\n" .
                "   Set Caching Application = Varnish Cache\n" .
                "   Enable ESI for dynamic blocks\n\n" .
                "2. Update VCL to enable ESI:\n" .
                "   sub vcl_backend_response {\n" .
                "       if (beresp.http.content-type ~ \"text/html\") {\n" .
                "           set beresp.do_esi = true;\n" .
                "       }\n" .
                "   }\n\n" .
                "3. Mark blocks as ESI in layout XML:\n" .
                "   <block name=\"customer.name\" esi=\"true\" />\n\n" .
                "4. Restart Varnish after VCL changes:\n" .
                "   sudo systemctl restart varnish\n\n" .
                "Benefits:\n" .
                "- Cache pages with customer-specific blocks\n" .
                "- Much higher hit rates (60% → 95%)\n" .
                "- Personalized content without sacrificing performance"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkGraceMode(array $stats): ?Violation
    {
        // Grace mode is configured in VCL, we can check if it's being used
        $graceHits = $stats['MAIN.cache_hit_grace']['value'] ?? 0;
        
        // If no grace hits but we have traffic, grace might not be configured
        $totalHits = $stats['MAIN.cache_hit']['value'] ?? 0;
        
        if ($totalHits > 1000 && $graceHits === 0) {
            $violation = new Violation(
                self::RULE_NO_GRACE_MODE,
                'Varnish grace mode appears to be unused. Grace mode serves stale content when backend is down, ' .
                'preventing site outages and improving user experience during backend issues or deployments.',
                'varnish',
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Configure Varnish grace mode in VCL:\n\n" .
                "sub vcl_backend_response {\n" .
                "    # Set grace period\n" .
                "    set beresp.grace = " . self::RECOMMENDED_GRACE_SECONDS . "s;\n" .
                "}\n\n" .
                "sub vcl_recv {\n" .
                "    # Allow serving stale content\n" .
                "    if (!std.healthy(req.backend_hint)) {\n" .
                "        # Backend unhealthy, increase grace\n" .
                "        set req.grace = 1h;\n" .
                "    }\n" .
                "}\n\n" .
                "Benefits:\n" .
                "- Site stays up during backend maintenance\n" .
                "- Smoother deployments\n" .
                "- Better user experience during issues\n" .
                "- Reduced backend load spikes\n\n" .
                "Restart Varnish after VCL changes:\n" .
                "  sudo systemctl restart varnish"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkBanRate(array $stats): ?Violation
    {
        $bans = $stats['MAIN.bans']['value'] ?? 0;
        $banAdded = $stats['MAIN.bans_added']['value'] ?? 0;
        
        // High ban rate can indicate too frequent cache purging
        if ($banAdded > 10000) {
            $violation = new Violation(
                self::RULE_CACHE_BANS_HIGH,
                sprintf(
                    'High Varnish ban/purge rate detected (%d bans). ' .
                    'Excessive cache purging reduces hit rates and increases backend load. ' .
                    'Review cache invalidation logic.',
                    $banAdded
                ),
                'varnish',
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'total_bans' => $bans,
                'bans_added' => $banAdded,
            ]);
            
            $violation->setSuggestion(
                "Reduce cache purge frequency:\n\n" .
                "1. Review Magento cache invalidation:\n" .
                "   Check which operations trigger cache purges\n" .
                "   Consider less aggressive invalidation\n\n" .
                "2. Use selective purging:\n" .
                "   Purge specific URLs instead of entire cache\n" .
                "   Use cache tags for granular invalidation\n\n" .
                "3. Monitor ban operations:\n" .
                "   varnishlog -g request -q 'VCL_call eq \"PURGE\"'\n\n" .
                "4. Consider longer TTL:\n" .
                "   If content changes infrequently, increase TTL"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkBackendHealth(): array
    {
        $violations = [];
        
        // Use varnishadm to check backend health
        $command = $this->configuration['varnishadm_path'] . ' backend.list 2>&1';
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            return $violations; // Can't check, skip
        }
        
        // Parse backend.list output
        foreach ($output as $line) {
            if (preg_match('/^(\S+)\s+.*\s+(Sick|sick)/', $line, $matches)) {
                $backendName = $matches[1];
                
                $violation = new Violation(
                    self::RULE_BACKEND_UNHEALTHY,
                    sprintf(
                        'Varnish backend "%s" is unhealthy. Unhealthy backends cause cache misses and direct traffic to other backends or result in errors.',
                        $backendName
                    ),
                    'varnish',
                    0,
                    0,
                    Violation::SEVERITY_ERROR,
                    Violation::TYPE_PERFORMANCE
                );
                
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'backend_name' => $backendName,
                    'status' => 'sick',
                ]);
                
                $violation->setSuggestion(
                    "Fix unhealthy backend:\n\n" .
                    "1. Check backend service status:\n" .
                    "   systemctl status php-fpm\n" .
                    "   systemctl status nginx\n\n" .
                    "2. Review backend logs:\n" .
                    "   tail -f /var/log/php-fpm/error.log\n" .
                    "   tail -f /var/log/nginx/error.log\n\n" .
                    "3. Check VCL probe configuration:\n" .
                    "   Ensure probe URL is accessible\n" .
                    "   Adjust probe timing if needed\n\n" .
                    "4. Monitor backend with:\n" .
                    "   varnishadm backend.list -p"
                );
                
                $violations[] = $violation;
            }
        }
        
        return $violations;
    }
    
    protected function commandExists(string $command): bool
    {
        $result = shell_exec("which {$command} 2>/dev/null");
        return !empty($result);
    }
    
    /**
     * Get Varnish statistics for reporting
     * 
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false];
        }
        
        $stats = $this->getVarnishStats();
        
        if (empty($stats)) {
            return ['available' => false];
        }
        
        $hits = $stats['MAIN.cache_hit']['value'] ?? 0;
        $misses = $stats['MAIN.cache_miss']['value'] ?? 0;
        $total = $hits + $misses;
        $hitRate = $total > 0 ? ($hits / $total) * 100 : 0;
        
        return [
            'available' => true,
            'hit_rate' => round($hitRate, 2),
            'cache_hits' => $hits,
            'cache_misses' => $misses,
            'esi_requests' => $stats['MAIN.esi_req']['value'] ?? 0,
            'esi_errors' => $stats['MAIN.esi_errors']['value'] ?? 0,
            'grace_hits' => $stats['MAIN.cache_hit_grace']['value'] ?? 0,
            'bans' => $stats['MAIN.bans']['value'] ?? 0,
        ];
    }
}
