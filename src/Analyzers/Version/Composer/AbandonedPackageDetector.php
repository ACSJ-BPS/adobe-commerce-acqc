<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\Composer;

class AbandonedPackageDetector
{
    private const CACHE_TTL_SECONDS  = 86400; // 24 hours
    private const CONNECT_TIMEOUT    = 5;      // seconds per request
    private const REQUEST_TIMEOUT    = 8;      // seconds per request
    private const CONCURRENCY        = 10;     // parallel Packagist requests

    public function __construct(private readonly string $cacheDir) {}

    public function detectAbandoned(array $packages): array
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Partition into cache-hits and packages that need a live fetch
        $cachedData  = [];
        $fetchNeeded = [];

        foreach ($packages as $name => $_info) {
            if (!str_contains($name, '/')) {
                continue;
            }
            $cached = $this->loadFromCache($name);
            if ($cached !== null) {
                $cachedData[$name] = $cached;
            } else {
                $fetchNeeded[] = $name;
            }
        }

        // Fetch all missing packages in parallel batches
        $fetched = $this->fetchParallel($fetchNeeded);

        // Merge and detect
        $allData = array_merge($cachedData, $fetched);
        $findings = [];

        foreach ($allData as $name => $data) {
            $abandoned = $data['package']['abandoned'] ?? false;
            if ($abandoned === false || $abandoned === null) {
                continue;
            }

            $replacement = ($abandoned === true || $abandoned === 1) ? null : (string)$abandoned;
            $findings[] = [
                'type'             => 'composer_package_abandoned',
                'severity'         => 'warning',
                'package'          => $name,
                'message'          => sprintf(
                    'Package %s is abandoned on Packagist. %s',
                    $name,
                    $replacement ? 'Replacement: ' . $replacement : 'No official replacement.'
                ),
                'effort_hours_min' => 2,
                'effort_hours_max' => 16,
            ];
        }

        return $findings;
    }

    // -------------------------------------------------------------------------
    // Parallel fetch via curl_multi
    // -------------------------------------------------------------------------

    /**
     * Fetch Packagist JSON for each package name in parallel, saving hits to cache.
     * Returns [package_name => decoded_json_array] for packages that responded.
     *
     * @param  string[] $names
     * @return array<string, array>
     */
    private function fetchParallel(array $names): array
    {
        if (empty($names)) {
            return [];
        }

        $results = [];

        foreach (array_chunk($names, self::CONCURRENCY) as $batch) {
            $results = array_merge($results, $this->fetchBatch($batch));
        }

        return $results;
    }

    /**
     * Fetch one batch of package names from Packagist via parallel curl_multi.
     * Protected so tests can override it without a real HTTP stack.
     *
     * @param  string[] $batch  package names like "vendor/package"
     * @return array<string, array>
     */
    protected function fetchBatch(array $batch): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($batch as $name) {
            [$vendor, $package] = explode('/', $name, 2);
            $url    = "https://repo.packagist.org/packages/{$vendor}/{$package}.json";
            $ch     = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_USERAGENT      => 'ACQC/3.0 (upgrade-readiness)',
                CURLOPT_ENCODING       => 'gzip',
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$name] = $ch;
        }

        // Execute all handles concurrently
        $active = null;
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $name => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($raw === false || $raw === '' || $code !== 200) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $results[$name] = $decoded;
            $this->saveToCache($name, $decoded);
        }

        curl_multi_close($multi);
        return $results;
    }

    // -------------------------------------------------------------------------
    // Cache helpers
    // -------------------------------------------------------------------------

    private function cachePathFor(string $packageName): string
    {
        return rtrim($this->cacheDir, '/') . '/' . md5($packageName) . '.json';
    }

    private function loadFromCache(string $packageName): ?array
    {
        $path = $this->cachePathFor($packageName);
        if (!file_exists($path)) {
            return null;
        }
        if ((time() - filemtime($path)) >= self::CACHE_TTL_SECONDS) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    protected function saveToCache(string $packageName, array $data): void
    {
        file_put_contents($this->cachePathFor($packageName), json_encode($data));
    }

    // Keep for test overrides
    protected function fetchPackagistJson(string $vendor, string $package): ?array
    {
        $results = $this->fetchBatch(["$vendor/$package"]);
        return $results["$vendor/$package"] ?? null;
    }
}
