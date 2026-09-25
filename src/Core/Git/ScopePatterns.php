<?php

declare(strict_types=1);

namespace ACQC\Core\Git;

use ACQC\Core\AcqcPaths;
use ACQC\Core\Platform\PlatformDetector;
use ACQC\Core\Platform\PlatformRegistry;

/**
 * Decides which changed files enter a scoped audit (--staged / --diff-from),
 * based on the platforms detected in the repository.
 *
 * Commerce file types are always included (unchanged behaviour); each detected
 * platform adds its manifest include_patterns (config/platforms/*.yaml), so an
 * App Builder / Node.js repo also gets its JS/TS and config files.
 */
final class ScopePatterns
{
    /** Files abqc needs to lint a staged snapshot correctly, even when unchanged. */
    public const APP_BUILDER_CONTEXT_FILES = [
        'package.json', 'app.config.yaml', 'ext.config.yaml', 'manifest.yml', 'manifest.yaml',
        'tsconfig.json', '.gitignore', '.eslintrc', '.eslintrc.js', '.eslintrc.cjs', '.eslintrc.json',
        '.eslintrc.yml', '.eslintrc.yaml', 'eslint.config.js', 'eslint.config.mjs', 'eslint.config.cjs',
    ];

    public const JS_EXTENSIONS = ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx'];

    /** @return string[] entries understood by GitDiffResolver::hasExtension() */
    public static function forProject(string $projectRoot): array
    {
        $patterns = GitDiffResolver::DEFAULT_EXTENSIONS;
        try {
            $detector = new PlatformDetector(new PlatformRegistry(AcqcPaths::resourceRoot()));
            $patterns = array_merge($patterns, $detector->includePatternsFor($projectRoot));
        } catch (\Throwable) {
            // Platform manifests unavailable: keep the Commerce defaults.
        }
        // Mixed repo (e.g. Commerce at the root, App Builder under a sub-folder): the
        // root-level detector misses it, so add App Builder file types explicitly.
        if (array_filter(self::appBuilderRoots($projectRoot), static fn (string $r): bool => $r !== '') !== []) {
            $patterns = array_merge($patterns, self::JS_EXTENSIONS, self::APP_BUILDER_CONTEXT_FILES, ['yaml', 'yml']);
        }
        return array_values(array_unique(array_map('strtolower', $patterns)));
    }

    /**
     * App Builder roots in a repository: directories (relative, '' = repo root) that
     * contain app.config.yaml or ext.config.yaml next to a package.json. Uses the git
     * index (tracked + staged files) when available, else a bounded filesystem walk.
     * node_modules and vendor are skipped.
     *
     * @return string[]
     */
    public static function appBuilderRoots(string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $files = self::listFiles($repoRoot);
        $byDir = [];
        foreach ($files as $rel) {
            $rel = str_replace('\\', '/', $rel);
            if (preg_match('#(^|/)(node_modules|vendor)/#', $rel)) {
                continue;
            }
            $dir = dirname($rel);
            $dir = $dir === '.' ? '' : $dir;
            $byDir[$dir][strtolower(basename($rel))] = true;
        }
        $roots = [];
        foreach ($byDir as $dir => $names) {
            if (isset($names['package.json']) && (isset($names['app.config.yaml']) || isset($names['ext.config.yaml']))) {
                $roots[] = (string) $dir;
            }
        }
        sort($roots);
        return $roots;
    }

    /**
     * The deepest root containing $relativePath, or null.
     *
     * @param string[] $roots
     */
    public static function rootFor(string $relativePath, array $roots): ?string
    {
        $best = null;
        foreach ($roots as $root) {
            if ($root === '' || str_starts_with($relativePath, $root . '/')) {
                if ($best === null || strlen($root) > strlen($best)) {
                    $best = $root;
                }
            }
        }
        return $best;
    }

    /** @return string[] repo-relative paths */
    private static function listFiles(string $repoRoot): array
    {
        if (is_dir($repoRoot . '/.git') || is_file($repoRoot . '/.git')) {
            $out = @shell_exec('git -C ' . escapeshellarg($repoRoot) . ' ls-files -z --cached 2>/dev/null');
            if (is_string($out) && $out !== '') {
                return array_values(array_filter(explode("\0", $out), 'strlen'));
            }
        }
        $result = [];
        if (!is_dir($repoRoot)) {
            return $result;
        }
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($repoRoot, \FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), ['node_modules', 'vendor', '.git'], true))
                )
            );
            $it->setMaxDepth(6);
            foreach ($it as $f) {
                $name = strtolower($f->getFilename());
                if (in_array($name, ['package.json', 'app.config.yaml', 'ext.config.yaml'], true)) {
                    $result[] = substr($f->getPathname(), strlen($repoRoot) + 1);
                }
            }
        } catch (\Throwable) {
        }
        return $result;
    }

    public static function isJsLike(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::JS_EXTENSIONS, true);
    }

    /**
     * True when a changed file is one abqc should re-check: JS/TS or a known App Builder
     * config file. Generic YAML (.gitlab-ci.yml, .github/workflows/*) is not included.
     */
    public static function isAppBuilderRelevant(string $path): bool
    {
        return self::isJsLike($path)
            || in_array(strtolower(basename($path)), self::APP_BUILDER_CONTEXT_FILES, true);
    }
}
