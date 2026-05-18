<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\Composer;

use ACQC\Core\Version\VersionRegistry;

class PlatformConstraintValidator
{
    public function __construct(private readonly VersionRegistry $registry) {}

    public function validate(string $projectRoot, string $targetVersion): array
    {
        $jsonFile = $projectRoot . '/composer.json';
        if (!file_exists($jsonFile)) {
            return [];
        }

        $json     = json_decode((string)file_get_contents($jsonFile), true) ?? [];
        $platform = $json['config']['platform'] ?? [];

        try {
            $requirements = $this->registry->getComposerRequirements($targetVersion);
        } catch (\Throwable) {
            return [];
        }

        $findings = [];

        // PHP version check
        $platformPhp = $platform['php'] ?? null;
        $requiredPhp = $requirements['php'] ?? null;

        if ($platformPhp !== null && $requiredPhp !== null) {
            $minPhp = $this->extractMinVersion((string)$requiredPhp);
            if ($minPhp !== null && version_compare((string)$platformPhp, $minPhp, '<')) {
                $findings[] = [
                    'type'     => 'platform_php_mismatch',
                    'severity' => 'error',
                    'message'  => sprintf(
                        'composer.json config.platform.php is %s but AC %s requires PHP >= %s.',
                        $platformPhp,
                        $targetVersion,
                        $minPhp,
                    ),
                ];
            }
        }

        // Extension check
        $requiredExtensions = $requirements['platform_extensions']['required'] ?? [];
        foreach ($requiredExtensions as $ext) {
            $extName = ltrim((string)$ext, 'ext-');
            $extKey  = 'ext-' . $extName;

            if (isset($platform[$extKey])) {
                continue;
            }

            if (extension_loaded($extName)) {
                continue;
            }

            $findings[] = [
                'type'     => 'platform_extension_missing',
                'severity' => 'warning',
                'message'  => sprintf(
                    'AC %s requires PHP extension %s which is not declared in composer.json config.platform.',
                    $targetVersion,
                    $ext,
                ),
            ];
        }

        return $findings;
    }

    private function extractMinVersion(string $constraint): ?string
    {
        // Handle ">= X.Y" or ">=X.Y <Z"
        if (preg_match('/>=\s*(\d+\.\d+(?:\.\d+)?)/', $constraint, $m)) {
            return $m[1];
        }
        // Handle "^X.Y"
        if (preg_match('/^\^(\d+\.\d+(?:\.\d+)?)/', trim($constraint), $m)) {
            return $m[1];
        }
        // Handle "~X.Y"
        if (preg_match('/^~(\d+\.\d+(?:\.\d+)?)/', trim($constraint), $m)) {
            return $m[1];
        }
        return null;
    }
}
