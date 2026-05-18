<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class VersionFeedSyncer
{
    public const REPO_MAGENTO_BASE = 'https://repo.magento.com';
    private const PACKAGIST_CE_URL = 'https://repo.packagist.org/p2/magento/community-edition.json';

    public function __construct(
        private readonly string $registryPath,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Apply the bundled offline seed (config/versions/seed.php) to the registry.
     * Creates stub YAMLs for any version in the seed that is not yet on disk, and
     * ensures registry.yaml reflects all seeded versions. Safe to call repeatedly.
     *
     * @return int Number of new stubs written
     */
    public function applySeed(): int
    {
        $seedFile = dirname($this->registryPath) . '/seed.php';

        if (!file_exists($seedFile)) {
            $this->logger->warning('Seed file not found: ' . $seedFile);
            return 0;
        }

        $seed    = include $seedFile;
        $written = 0;

        foreach ($seed as $version => $meta) {
            $yamlPath = $this->resolveYamlPath((string) $version);
            if (file_exists($yamlPath)) {
                continue;
            }

            $this->writeVersionYaml((string) $version, [
                'schema_version'      => '1',
                'version'             => (string) $version,
                'release_date'        => $meta['release_date'] ?? null,
                'release_type'        => $meta['release_type'] ?? 'unknown',
                'breaking_changes'    => [],
                'deprecated_symbols'  => ['classes' => [], 'methods' => [], 'events' => []],
                'db_schema_changes'   => [],
                'security_advisories' => [],
            ]);

            $written++;
        }

        if ($written > 0) {
            $this->updateRegistryIndex(array_keys($seed));
        }

        $this->markSeeded(count($seed));

        return $written;
    }

    public function sync(
        array  $sources   = ['repo.magento.com'],
        string $repoUrl   = self::REPO_MAGENTO_BASE
    ): SyncResult {
        // Always apply the bundled seed first so the registry is populated offline
        $this->applySeed();

        $newVersions = [];
        $errors      = [];

        foreach ($sources as $source) {
            try {
                $found = match ($source) {
                    'repo.magento.com' => $this->syncRepoMagento($repoUrl),
                    'packagist'        => $this->syncPackagist(),
                    default            => [],
                };
                $newVersions = array_merge($newVersions, $found);
            } catch (\Throwable $e) {
                $this->logger->warning("Sync source '{$source}' failed: " . $e->getMessage());
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors) && empty($newVersions)) {
            return SyncResult::offline();
        }

        if (!empty($newVersions)) {
            $this->updateRegistryIndex($newVersions);
        }

        $this->touchLastSynced();

        return SyncResult::success($newVersions, implode(',', $sources));
    }

    /**
     * Sync from a Composer repository (default: repo.magento.com) by delegating to the
     * Composer CLI. This avoids re-implementing Composer's authentication, gzip handling,
     * and API negotiation. Auth is read from ACQC_MAGENTO_PUBLIC_KEY / ACQC_MAGENTO_PRIVATE_KEY
     * env vars or ~/.composer/auth.json, same as Composer itself.
     *
     * Mirrors how UCT's core:code:changes command delegates to
     *   composer create-project --repository-url=https://repo.magento.com ...
     */
    public function syncRepoMagento(string $repoUrl = self::REPO_MAGENTO_BASE): array
    {
        [$publicKey, $privateKey] = $this->resolveMagentoAuthKeysForHost((string)parse_url($repoUrl, PHP_URL_HOST));

        if ($publicKey === '' || $privateKey === '') {
            throw new \RuntimeException(
                "Magento auth keys not configured for {$repoUrl}. " .
                'Set ACQC_MAGENTO_PUBLIC_KEY and ACQC_MAGENTO_PRIVATE_KEY, ' .
                'or add credentials to ~/.composer/auth.json. ' .
                'Use --source=packagist to sync without authentication.'
            );
        }

        $rawVersions = $this->runComposerShow('magento/product-community-edition', $repoUrl, $publicKey, $privateKey);

        // Cross-reference release dates from public Packagist (repo.magento.com returns null dates)
        $releaseDates = $this->fetchPackagistReleaseDates();

        $newVersions = [];
        foreach ($rawVersions as $versionString) {
            if (!preg_match('/^2\.\d+\.\d+(-p\d+|-(?:alpha|beta|rc)\d*)?$/', $versionString)) {
                continue;
            }

            $yamlPath = $this->resolveYamlPath($versionString);
            if (file_exists($yamlPath)) {
                continue;
            }

            $releaseType = $this->inferReleaseType($versionString);
            $releaseDate = $releaseDates[$versionString] ?? null;

            $this->writeVersionYaml($versionString, [
                'schema_version'      => '1',
                'version'             => $versionString,
                'release_date'        => $releaseDate,
                'release_type'        => $releaseType,
                'breaking_changes'    => [],
                'deprecated_symbols'  => ['classes' => [], 'methods' => [], 'events' => []],
                'db_schema_changes'   => [],
                'security_advisories' => [],
            ]);

            $newVersions[] = $versionString;
        }

        return $newVersions;
    }

    /**
     * Shell out to `composer show --all --format=json` in a temp working directory
     * configured with the given repository. Returns a flat array of version strings.
     *
     * This mirrors the pattern used by UCT's CoreCodeChangesCommand::downloadVanilla():
     *   composer create-project --repository-url=https://repo.magento.com <pkg>=<ver> <dir>
     * but uses `composer show` to list all versions without downloading package files.
     */
    private function runComposerShow(string $package, string $repoUrl, string $publicKey, string $privateKey): array
    {
        $tempDir = sys_get_temp_dir() . '/acqc_sync_' . uniqid('', true);
        mkdir($tempDir, 0700, true);

        try {
            // Minimal composer.json pointing at the target repository
            file_put_contents($tempDir . '/composer.json', json_encode([
                'repositories' => [['type' => 'composer', 'url' => $repoUrl]],
                'require'      => new \stdClass(),
            ], JSON_PRETTY_PRINT));

            // Scoped auth.json — only credentials for this host, not the global keychain
            $host = (string) parse_url($repoUrl, PHP_URL_HOST);
            file_put_contents($tempDir . '/auth.json', json_encode([
                'http-basic' => [$host => ['username' => $publicKey, 'password' => $privateKey]],
            ]));

            $composerBin = $this->resolveComposerBin();
            $process = new Process(
                [$composerBin, 'show', $package, '--all', '--format=json', '--no-interaction', '--no-ansi'],
                $tempDir,
                ['COMPOSER_HOME' => $tempDir]
            );
            $process->setTimeout(60);
            $process->run();

            $output = $process->getOutput();
        } finally {
            $this->removeTempDirectory($tempDir);
        }

        if (empty($output)) {
            throw new \RuntimeException("composer show returned no output for {$repoUrl} ({$package})");
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            throw new \RuntimeException("composer show returned non-JSON for {$repoUrl} ({$package})");
        }

        return $data['versions'] ?? [];
    }

    private function resolveComposerBin(): string
    {
        foreach (['composer', 'composer.phar', '/usr/local/bin/composer', '/usr/bin/composer'] as $candidate) {
            $p = new Process(['which', $candidate]);
            $p->run();
            if ($p->isSuccessful() && trim($p->getOutput()) !== '') {
                return $candidate;
            }
        }
        throw new \RuntimeException('composer binary not found in PATH. Install Composer: https://getcomposer.org');
    }

    private function removeTempDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    private function inferReleaseType(string $version): string
    {
        if (preg_match('/-p\d+$/', $version))                   return 'security';
        if (preg_match('/-(alpha|beta|rc)\d*$/', $version))     return 'prerelease';
        $base  = explode('-', $version)[0];
        $parts = explode('.', $base);
        return ((int)($parts[2] ?? 0) === 0) ? 'minor' : 'patch';
    }

    /**
     * Sync from public Packagist using magento/community-edition.
     * No authentication required. Provides release dates.
     * Fallback when Magento auth keys are not configured.
     */
    public function syncPackagist(): array
    {
        $json = $this->fetchUrl(self::PACKAGIST_CE_URL);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Packagist returned non-JSON response');
        }

        $packages    = $data['packages']['magento/community-edition'] ?? [];
        $newVersions = [];

        foreach ($packages as $pkg) {
            $versionString = $pkg['version'] ?? '';
            if (!preg_match('/^2\.\d+\.\d+(?:-p\d+)?$/', $versionString)) {
                continue;
            }

            $yamlPath = $this->resolveYamlPath($versionString);
            if (file_exists($yamlPath)) {
                continue;
            }

            $releaseDate = $pkg['time'] ?? null;
            if ($releaseDate !== null) {
                try {
                    $releaseDate = (new \DateTimeImmutable($releaseDate))->format('Y-m-d');
                } catch (\Exception) {
                    $releaseDate = null;
                }
            }

            $this->writeVersionYaml($versionString, [
                'schema_version'     => '1',
                'version'            => $versionString,
                'release_date'       => $releaseDate,
                'release_type'       => 'unknown',
                'breaking_changes'   => [],
                'deprecated_symbols' => ['classes' => [], 'methods' => [], 'events' => []],
                'db_schema_changes'  => [],
                'security_advisories' => [],
            ]);

            $newVersions[] = $versionString;
        }

        return $newVersions;
    }

    public function isRegistryStale(int $maxAgeDays = 7): bool
    {
        $registryFile = dirname($this->registryPath) . '/registry.yaml';

        if (!file_exists($registryFile)) {
            return true;
        }

        $data = Yaml::parseFile($registryFile);

        // A seeded registry (shipped with the tool) is never considered stale on first run —
        // it just means the user hasn't yet synced against the live repo.
        // We return true only when enough days have passed since the last *live* sync.
        $lastSynced = $data['last_synced'] ?? null;
        $syncSource = $data['sync_source'] ?? 'unknown';

        if ($lastSynced === null || $syncSource === 'seed') {
            return true;
        }

        try {
            $syncDate = new \DateTimeImmutable($lastSynced);
            $now      = new \DateTimeImmutable();
            $diff     = $now->diff($syncDate);

            return $diff->days > $maxAgeDays;
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * Resolve auth keys for the given Composer repository host.
     * Priority: ACQC_MAGENTO_PUBLIC_KEY / ACQC_MAGENTO_PRIVATE_KEY env vars → ~/.composer/auth.json.
     * Env vars are only applied when the host is repo.magento.com (their original intent).
     */
    private function resolveMagentoAuthKeysForHost(string $host): array
    {
        if ($host === 'repo.magento.com') {
            $publicKey  = (string)(getenv('ACQC_MAGENTO_PUBLIC_KEY')  ?: '');
            $privateKey = (string)(getenv('ACQC_MAGENTO_PRIVATE_KEY') ?: '');

            if ($publicKey !== '' && $privateKey !== '') {
                return [$publicKey, $privateKey];
            }
        }

        // Fall back to ~/.composer/auth.json for any host
        $authFile = (getenv('HOME') ?: getenv('USERPROFILE') ?: '') . '/.composer/auth.json';
        if (file_exists($authFile)) {
            $auth  = json_decode(file_get_contents($authFile), true) ?? [];
            $creds = $auth['http-basic'][$host] ?? [];
            if (!empty($creds['username']) && !empty($creds['password'])) {
                return [(string)$creds['username'], (string)$creds['password']];
            }
        }

        return ['', ''];
    }

    /**
     * Fetch release dates from public Packagist CE package (used to enrich repo.magento.com data).
     * Returns ['2.4.8' => '2025-04-08', ...]
     */
    private function fetchPackagistReleaseDates(): array
    {
        $dates = [];
        try {
            $json = $this->fetchUrl(self::PACKAGIST_CE_URL);
            $data = json_decode($json, true);
            foreach ($data['packages']['magento/community-edition'] ?? [] as $pkg) {
                $v = $pkg['version'] ?? '';
                $t = $pkg['time'] ?? null;
                if ($v !== '' && $t !== null) {
                    try {
                        $dates[$v] = (new \DateTimeImmutable($t))->format('Y-m-d');
                    } catch (\Exception) {
                        // skip
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('Could not fetch release dates from Packagist (non-fatal): ' . $e->getMessage());
        }
        return $dates;
    }

    protected function fetchUrl(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'method'  => 'GET',
                'header'  => "User-Agent: ACQC/3.0 VersionFeedSyncer\r\n",
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \RuntimeException("HTTP request failed for URL: {$url}");
        }

        return $result;
    }

    private function writeVersionYaml(string $version, array $data): void
    {
        $path = $this->resolveYamlPath($version);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, Yaml::dump($data, 4, 2));
    }

    private function updateRegistryIndex(array $newVersions): void
    {
        $registryFile = dirname($this->registryPath) . '/registry.yaml';
        $registry     = file_exists($registryFile)
            ? (Yaml::parseFile($registryFile) ?? [])
            : ['schema_version' => '1', 'known_versions' => []];

        $known = $registry['known_versions'] ?? [];
        foreach ($newVersions as $v) {
            if (!in_array($v, $known, true)) {
                $known[] = $v;
            }
        }

        $registry['known_versions'] = $known;
        file_put_contents($registryFile, Yaml::dump($registry, 4, 2));
    }

    private function touchLastSynced(): void
    {
        $registryFile = dirname($this->registryPath) . '/registry.yaml';

        if (!file_exists($registryFile)) {
            return;
        }

        $registry                = Yaml::parseFile($registryFile) ?? [];
        $registry['last_synced'] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
        $registry['sync_source'] = 'live';
        file_put_contents($registryFile, Yaml::dump($registry, 4, 2));
    }

    private function markSeeded(int $seedCount): void
    {
        $registryFile = dirname($this->registryPath) . '/registry.yaml';
        $registry     = file_exists($registryFile)
            ? (Yaml::parseFile($registryFile) ?? [])
            : ['schema_version' => '1', 'known_versions' => []];

        $registry['seeded']       = true;
        $registry['seed_version'] = (string) $seedCount;

        // Only stamp sync_source=seed if there has never been a live sync
        if (($registry['sync_source'] ?? 'seed') === 'seed') {
            $registry['last_synced'] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
            $registry['sync_source'] = 'seed';
        }

        file_put_contents($registryFile, Yaml::dump($registry, 4, 2));
    }

    private function resolveYamlPath(string $version): string
    {
        return rtrim($this->registryPath, '/') . '/' . $version . '.yaml';
    }
}
