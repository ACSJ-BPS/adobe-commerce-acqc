<?php

declare(strict_types=1);

namespace ACQC\Core\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Locates the nearest `.acqc/project.yaml` walking upwards from a target path,
 * bounded by the git repository root. When the target is not inside a git
 * repository, only the target folder itself is checked, so a stray
 * `~/.acqc/project.yaml` or `/opt/.acqc/project.yaml` never applies.
 */
final class ProjectConfigLocator
{
    /**
     * Find the nearest `.acqc/project.yaml` starting from $targetPath and walking up.
     * Stops at the nearest ancestor containing `.git` (inclusive).
     */
    public function locate(string $targetPath): ?string
    {
        $startDir = $this->resolveStartDirectory($targetPath);
        if ($startDir === null) {
            return null;
        }

        if ($this->findGitRoot($startDir) === null) {
            $candidate = $startDir . DIRECTORY_SEPARATOR . '.acqc' . DIRECTORY_SEPARATOR . 'project.yaml';
            return is_file($candidate) && is_readable($candidate) ? $candidate : null;
        }

        $current = $startDir;
        while ($current !== '' && is_dir($current)) {
            $candidate = $current . DIRECTORY_SEPARATOR . '.acqc' . DIRECTORY_SEPARATOR . 'project.yaml';
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }

            // Stop walking upwards once we hit the git root
            if (file_exists($current . DIRECTORY_SEPARATOR . '.git')) {
                break;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * Locate and parse the nearest `.acqc/project.yaml`.
     *
     * @return array{path: ?string, project_root: ?string, config: array<string, mixed>}
     */
    public function loadNearest(string $targetPath): array
    {
        $file = $this->locate($targetPath);
        if ($file === null) {
            return [
                'path' => null,
                'project_root' => null,
                'config' => [],
            ];
        }

        $parsed = Yaml::parseFile($file);

        return [
            'path' => $file,
            'project_root' => dirname(dirname($file)),
            'config' => is_array($parsed) ? $parsed : [],
        ];
    }

    private function findGitRoot(string $startDir): ?string
    {
        $current = $startDir;
        while ($current !== '' && is_dir($current)) {
            if (file_exists($current . DIRECTORY_SEPARATOR . '.git')) {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
        return null;
    }

    private function resolveStartDirectory(string $targetPath): ?string
    {
        $real = realpath($targetPath);
        $path = $real !== false ? $real : $targetPath;

        if (is_file($path)) {
            $path = dirname($path);
        }

        if (!is_dir($path)) {
            return null;
        }

        return rtrim($path, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
    }
}
