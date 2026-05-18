<?php

declare(strict_types=1);

namespace ACQC\Utils;

use Symfony\Component\Finder\Finder;

class FileScanner
{
    // Expanded default excludes derived from docs/references/ignore-patterns.md
    private const DEFAULT_EXCLUDES = [
        'vendor',
        'lib',
        'node_modules',
        'generated',
        'var',
        'pub/static',
        'pub/media',
        'pub/errors',
        'pub/opt',
        'dev/tests',
        'dev/tools',
        'setup',
        '.git',
        'phpserver',
        'update',
        'backup',
    ];

    // File extensions to skip (compiled/binary/log files)
    private const DEFAULT_SKIP_EXTENSIONS = [
        'min.js', 'min.css', 'log', 'jpg', 'jpeg', 'png', 'gif',
        'bmp', 'svg', 'ico', 'pdf', 'zip', 'tar', 'gz', 'woff',
        'woff2', 'ttf', 'eot', 'otf', 'mp4', 'mp3',
    ];

    // Priority tiers for file ordering (from docs/references/file-priority.md)
    // High-priority files are sorted first so critical issues surface at the top of reports.
    private const PRIORITY_PATTERNS = [
        0 => [ // High priority
            '*/Controller/*',
            '*/Api/*',
            '*/Observer/Webhook/*',
            '*/Observer/Payment/*',
            '*/Model/ResourceModel/*',
            '*/Plugin/*',
            '*/Cron/*',
            '*/Gateway/*',
        ],
        1 => [ // Medium priority
            '*/Model/*',
            '*/Block/*',
            '*/Helper/*',
            '*/Observer/*',
            '*/Console/*',
            '*/Ui/*',
        ],
        2 => [ // Low priority — everything else
        ],
    ];

    public function scanProject(
        string $projectPath,
        array $includePatterns = ['*.php'],
        array $excludePaths = [],
        bool $prioritySort = true
    ): array {
        if (!is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project path does not exist: {$projectPath}");
        }

        $finder = new Finder();
        $finder->files()->in($projectPath);

        foreach ($includePatterns as $pattern) {
            $finder->name($pattern);
        }

        // Merge caller-supplied excludes with expanded defaults
        $allExcludes = array_unique(array_merge(self::DEFAULT_EXCLUDES, $excludePaths));
        foreach ($allExcludes as $exclude) {
            $finder->exclude($exclude);
        }

        $files = [];
        foreach ($finder as $file) {
            $realPath = $file->getRealPath();

            // Skip compiled/binary extensions
            $ext = $file->getExtension();
            if (in_array($ext, self::DEFAULT_SKIP_EXTENSIONS, true)) {
                continue;
            }

            $files[] = $realPath;
        }

        return $prioritySort ? $this->sortByPriority($files) : $files;
    }

    /**
     * Sort files so high-priority paths (Controllers, Plugins, Cron, API)
     * appear first in the results, surfacing critical issues at the top of reports.
     */
    public function sortByPriority(array $files): array
    {
        $buckets = [0 => [], 1 => [], 2 => []];

        foreach ($files as $file) {
            $buckets[$this->getPriorityTier($file)][] = $file;
        }

        return array_merge($buckets[0], $buckets[1], $buckets[2]);
    }

    private function getPriorityTier(string $filePath): int
    {
        foreach ([0, 1] as $tier) {
            foreach (self::PRIORITY_PATTERNS[$tier] as $pattern) {
                if (fnmatch($pattern, $filePath) || $this->matchesPathSegment($pattern, $filePath)) {
                    return $tier;
                }
            }
        }

        return 2;
    }

    private function matchesPathSegment(string $pattern, string $filePath): bool
    {
        // Convert */Controller/* style to a regex that matches path segments
        $segment = trim(str_replace(['*/', '/*'], ['', ''], $pattern), '/');
        return str_contains($filePath, DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR);
    }

    public function getFileInfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        return [
            'path' => $filePath,
            'size' => filesize($filePath),
            'lines' => count($lines),
            'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
            'modified' => filemtime($filePath),
            'priority_tier' => $this->getPriorityTier($filePath),
        ];
    }

    public function filterFilesByType(array $files, string $type): array
    {
        return array_values(array_filter($files, static function (string $file) use ($type): bool {
            return pathinfo($file, PATHINFO_EXTENSION) === $type;
        }));
    }

    public function filterFilesByPattern(array $files, string $pattern): array
    {
        return array_values(array_filter($files, static function (string $file) use ($pattern): bool {
            return fnmatch($pattern, basename($file));
        }));
    }
}
