<?php

declare(strict_types=1);

namespace ACQC\Core\Setup;

use ACQC\Analyzers\PHP\PHPMDAnalyzer;
use ACQC\Core\Version\MagentoVersion;
use Symfony\Component\Yaml\Yaml;

class MagentoPhpmdInstaller
{
    private const RULESET = 'testsuite/Magento/Test/Php/_files/phpmd/ruleset.xml';
    private const FRAMEWORK = 'framework/Magento/CodeMessDetector';

    public function setup(string $root, string $configPath, array $config, string $version, bool $force): array
    {
        if (!MagentoVersion::isValidString($version)) {
            throw new \InvalidArgumentException('project.platform_version must be an exact Commerce release, such as 2.4.8-p5.');
        }
        foreach (['project', 'phpmd', 'analyzers'] as $key) {
            if (array_key_exists($key, $config)
                && (!is_array($config[$key]) || ($config[$key] !== [] && array_is_list($config[$key])))) {
                throw new \InvalidArgumentException("{$key} must be a mapping.");
            }
        }
        $severitySettings = PHPMDAnalyzer::resolveProjectConfiguration(
            array_intersect_key($config['phpmd'] ?? [], array_flip(['severity_mapping', 'rule_severity_overrides'])),
            $root
        );
        if (!is_file($root . '/vendor/autoload.php')) {
            throw new \InvalidArgumentException('Project vendor/autoload.php is missing. Run Composer install in the project first.');
        }
        $original = $this->readFile($configPath);
        $parsed = Yaml::parse($original);
        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw new \InvalidArgumentException('Project config must be a YAML mapping.');
        }
        if ($parsed !== $config) {
            throw new \RuntimeException('Project config changed before setup. Retry with the current settings.');
        }
        $base = $root . '/.acqc/phpmd';
        if (is_link($root . '/.acqc') || is_link($base) || is_link($configPath)) {
            throw new \InvalidArgumentException('Managed setup paths and project config must not be symlinks.');
        }
        $destination = $base . '/' . $version;
        $stage = null;
        $backup = null;
        $reused = is_dir($destination) && !$force;
        try {
            if ($reused) {
                $this->validateCache($destination, $version);
                $this->verifyRules($destination);
            } else {
                $this->makeDirectory($base);
                $stage = $base . '/.setup-' . bin2hex(random_bytes(8));
                $this->makeDirectory($stage);
                $commit = $this->resolveCommit($version);
                $this->downloadFile('dev/tests/static/' . self::RULESET, $stage . '/' . self::RULESET, $commit);
                $this->downloadDirectory('dev/tests/static/' . self::FRAMEWORK, $stage . '/' . self::FRAMEWORK, $commit);
                foreach (['LICENSE.txt', 'COPYING.txt'] as $file) {
                    $this->downloadFile($file, $stage . '/' . $file, $commit);
                }
                $this->writeFile($stage . '/bootstrap.php', <<<'PHP'
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);
if (!defined('BP')) {
    define('BP', $projectRoot);
}
require $projectRoot . '/vendor/autoload.php';

PHP);
                $this->verifyRules($stage);
                $hashes = [];
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $hashes[substr($file->getPathname(), strlen($stage) + 1)] = $this->checksum($file->getPathname());
                    }
                }
                $manifest = ['version' => $version, 'commit' => $commit, 'files' => $hashes];
                $this->writeFile($stage . '/setup.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            }

            $updated = $config;
            $updated['project']['platform_version'] = $version;
            $relative = '.acqc/phpmd/' . $version;
            $updated['phpmd'] = [
                'rulesets' => [$relative . '/' . self::RULESET],
                'minimum_priority' => 5,
                'bootstrap' => $relative . '/bootstrap.php',
            ] + $severitySettings;
            $updated['analyzers']['php_phpmd'] = true;
            if ($this->readFile($configPath) !== $original) {
                throw new \RuntimeException('Project config changed during setup. Retry without overwriting the concurrent change.');
            }
            if ($stage !== null) {
                if (file_exists($destination)) {
                    if (!$force || !is_dir($destination) || is_link($destination)) {
                        throw new \RuntimeException('Setup destination already exists. Use --force to replace a normal directory.');
                    }
                    $backup = $base . '/.backup-' . $version . '-' . bin2hex(random_bytes(6));
                    $this->rename($destination, $backup);
                }
                try {
                    $this->rename($stage, $destination);
                } catch (\Throwable $e) {
                    if ($backup !== null) {
                        $this->rename($backup, $destination);
                    }
                    throw $e;
                }
            }
            try {
                if ($updated !== $config) {
                    $this->writeConfig($configPath, Yaml::dump($updated, 10, 2));
                }
            } catch (\Throwable $e) {
                if ($stage !== null) {
                    $this->rename($destination, $stage);
                    if ($backup !== null) {
                        $this->rename($backup, $destination);
                    }
                }
                throw $e;
            }
            $stage = null;
            return ['directory' => $destination, 'reused' => $reused, 'backup' => $backup];
        } finally {
            if ($stage !== null && is_dir($stage)) {
                $this->removeStage($stage);
            }
        }
    }

    private function resolveCommit(string $version): string
    {
        $ref = $this->request('git/ref/tags/' . rawurlencode($version));
        $object = $ref['object'] ?? [];
        for ($i = 0; ($object['type'] ?? null) === 'tag' && $i < 5; $i++) {
            $sha = $object['sha'] ?? '';
            if (!is_string($sha) || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
                throw new \RuntimeException('GitHub returned an invalid release reference.');
            }
            $tag = $this->request('git/tags/' . $sha);
            $object = $tag['object'] ?? [];
        }
        $sha = $object['sha'] ?? '';
        if (($object['type'] ?? null) !== 'commit' || !is_string($sha) || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new \RuntimeException('GitHub did not resolve the requested release to a commit.');
        }
        return $sha;
    }

    private function downloadDirectory(string $source, string $destination, string $commit, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new \RuntimeException('Magento rule directory nesting exceeds the supported limit.');
        }
        $entries = $this->request('contents/' . $source . '?ref=' . $commit);
        if (!array_is_list($entries) || count($entries) > 100) {
            throw new \RuntimeException('GitHub returned an invalid rule directory listing.');
        }
        $this->makeDirectory($destination);
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? '';
            if (!is_string($name) || in_array($name, ['.', '..'], true)
                || preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1
                || ($entry['path'] ?? null) !== $source . '/' . $name) {
                throw new \RuntimeException('GitHub returned an unsafe rule resource path.');
            }
            if (($entry['type'] ?? null) === 'dir') {
                $this->downloadDirectory($source . '/' . $name, $destination . '/' . $name, $commit, $depth + 1);
            } elseif (($entry['type'] ?? null) === 'file') {
                $this->downloadFile($source . '/' . $name, $destination . '/' . $name, $commit);
            } else {
                throw new \RuntimeException('Only regular Magento rule files and directories are supported.');
            }
        }
    }

    private function downloadFile(string $source, string $destination, string $commit): void
    {
        $file = $this->request('contents/' . $source . '?ref=' . $commit);
        $content = $file['content'] ?? null;
        $bytes = is_string($content) ? base64_decode($content, true) : false;
        if (($file['type'] ?? null) !== 'file' || ($file['encoding'] ?? null) !== 'base64'
            || $bytes === false || strlen($bytes) > 2 * 1024 * 1024
            || sha1('blob ' . strlen($bytes) . "\0" . $bytes) !== ($file['sha'] ?? null)) {
            throw new \RuntimeException("GitHub returned invalid or oversized content for {$source}.");
        }
        $this->writeFile($destination, $bytes);
    }

    protected function request(string $path): array
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The PHP curl extension is required to download Magento rules.');
        }
        $handle = curl_init('https://api.github.com/repos/magento/magento2/' . $path);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize the GitHub download.');
        }
        try {
            $headers = ['Accept: application/vnd.github+json', 'User-Agent: ACQC-PHPMD-Setup'];
            $token = getenv('GITHUB_TOKEN');
            if (is_string($token) && $token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new \RuntimeException('GitHub download failed: ' . curl_error($handle));
            }
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($status !== 200) {
                throw new \RuntimeException("GitHub returned HTTP {$status} for {$path}. Check the exact release tag; for rate limits, supply GITHUB_TOKEN.");
            }
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('GitHub returned an invalid JSON response.');
            }
            return $data;
        } finally {
            curl_close($handle);
        }
    }

    private function verifyRules(string $directory): void
    {
        $analyzer = new PHPMDAnalyzer(['phpmd' => [
            'rulesets' => [$directory . '/' . self::RULESET],
            'minimum_priority' => 5,
            'bootstrap' => $directory . '/bootstrap.php',
        ], 'ignore_patterns' => [], 'excludes' => []]);
        if (!$analyzer->isAvailable()) {
            throw new \RuntimeException('PHPMD is missing from ACQC. Restore ACQC Composer dependencies with composer install.');
        }
        $file = tempnam(sys_get_temp_dir(), 'acqc-phpmd-verify-');
        if ($file === false) {
            throw new \RuntimeException('Could not create a PHPMD verification input.');
        }
        try {
            $this->writeFile($file, "<?php\n");
            $phpFile = $file . '.php';
            $this->rename($file, $phpFile);
            $file = $phpFile;
            $analyzer->analyze([$file]);
        } finally {
            if (is_file($file) && !unlink($file)) {
                throw new \RuntimeException('Could not remove the PHPMD verification input.');
            }
        }
    }

    private function validateCache(string $directory, string $version): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('Managed PHPMD directories must not be symlinks.');
        }
        $manifest = json_decode($this->readFile($directory . '/setup.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== $version
            || !is_array($manifest['files'] ?? null) || $manifest['files'] === []) {
            throw new \RuntimeException('PHPMD setup metadata is invalid. Use --force to reinstall.');
        }
        foreach ($manifest['files'] as $path => $hash) {
            if (!is_string($path) || str_starts_with($path, '/') || str_contains($path, '\\')
                || in_array('..', explode('/', $path), true)
                || !is_file($directory . '/' . $path) || is_link($directory . '/' . $path)
                || $this->checksum($directory . '/' . $path) !== $hash) {
                throw new \RuntimeException('Managed PHPMD files are missing or modified. Use --force to reinstall; existing files will be backed up.');
            }
        }
    }

    private function writeConfig(string $path, string $content): void
    {
        $temporary = tempnam(dirname($path), '.acqc-project-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not stage the project configuration.');
        }
        try {
            $this->writeFile($temporary, $content);
            $permissions = fileperms($path);
            if ($permissions === false || !chmod($temporary, $permissions & 0777)) {
                throw new \RuntimeException('Could not preserve project config permissions.');
            }
            $this->rename($temporary, $path);
        } finally {
            if (is_file($temporary) && !unlink($temporary)) {
                throw new \RuntimeException('Could not clean the staged project configuration.');
            }
        }
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new \RuntimeException("Could not checksum {$path}.");
        }
        return $checksum;
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new \RuntimeException("Could not create {$path}.");
        }
    }

    private function writeFile(string $path, string $content): void
    {
        $this->makeDirectory(dirname($path));
        if (file_put_contents($path, $content) !== strlen($content)) {
            throw new \RuntimeException("Could not write {$path}.");
        }
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Could not read {$path}.");
        }
        return $content;
    }

    private function rename(string $source, string $destination): void
    {
        if (!rename($source, $destination)) {
            throw new \RuntimeException("Could not move {$source} to {$destination}.");
        }
    }

    private function removeStage(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                $this->removeStage($file->getPathname());
            } elseif (!unlink($file->getPathname())) {
                throw new \RuntimeException('Could not remove a staged PHPMD file.');
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException('Could not remove the staged PHPMD directory.');
        }
    }
}
