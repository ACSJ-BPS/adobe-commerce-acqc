<?php

declare(strict_types=1);

namespace ACQC\Core\Profiles;

class ProfileResolver
{
    public function __construct(
        private readonly string $acqcRoot,
        private readonly string $projectRoot,
    ) {}

    /**
     * Resolve a profile by name.
     *
     * Search order (first match wins):
     *   1. ~/.acqc/profiles/{name}.yaml           (personal override)
     *   2. $projectRoot/.acqc/profiles/{name}.yaml (project override)
     *   3. $acqcRoot/config/profiles/{name}.yaml   (built-in)
     *
     * Both 'tech-lead' and 'tech_lead' are accepted.
     *
     * @throws \InvalidArgumentException when profile is not found
     */
    public function resolve(string $profileName): ProfileConfig
    {
        $slug = $this->normalize($profileName);

        foreach ($this->searchPaths($slug) as $path) {
            if (file_exists($path)) {
                return ProfileConfig::fromYaml((string) file_get_contents($path));
            }
        }

        $available = implode(', ', $this->listBuiltIn());
        throw new \InvalidArgumentException(
            "Profile '{$profileName}' not found. Available built-in profiles: {$available}"
        );
    }

    // ── private helpers ────────────────────────────────────────────────────────

    private function normalize(string $name): string
    {
        return str_replace('_', '-', strtolower($name));
    }

    /** @return string[] */
    private function searchPaths(string $slug): array
    {
        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: '');

        $paths = [
            rtrim($this->projectRoot, '/') . '/.acqc/profiles/' . $slug . '.yaml',
            rtrim($this->acqcRoot, '/')    . '/config/profiles/' . $slug . '.yaml',
        ];

        if ($home !== '') {
            array_unshift($paths, $home . '/.acqc/profiles/' . $slug . '.yaml');
        }

        return $paths;
    }

    /** @return string[] */
    private function listBuiltIn(): array
    {
        $dir = rtrim($this->acqcRoot, '/') . '/config/profiles';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.yaml') ?: [];
        sort($files);
        return array_map(fn(string $f) => basename($f, '.yaml'), $files);
    }
}
