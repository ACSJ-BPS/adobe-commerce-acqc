<?php

declare(strict_types=1);

namespace ACQC\Core\Git;

/**
 * Materialises the *staged* (index) version of changed files into a temporary
 * directory so a pre-commit audit checks exactly what will be committed —
 * not the working-tree copy, which may contain unstaged edits (git add -p).
 *
 * Relative paths are preserved inside the snapshot so path-based rules
 * (e.g. Controller/, Plugin/) still match. Call cleanup() when done.
 */
final class StagedSnapshot
{
    /** @var array<string,string> snapshot absolute path => repo-relative path */
    private array $map = [];

    private function __construct(
        private readonly string $repoRoot,
        private readonly string $dir
    ) {
    }

    /**
     * @param string[] $extensions lower-case extensions to include ([] = all)
     */
    public static function create(
        string $path,
        array $extensions = GitDiffResolver::DEFAULT_EXTENSIONS,
        ?string $tmpBase = null
    ): self {
        $root = (new GitDiffResolver())->getRepositoryRoot($path);

        $dir = rtrim($tmpBase ?? sys_get_temp_dir(), '/') . '/acqc-staged-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create snapshot directory: {$dir}");
        }
        $snapshot = new self($root, $dir);
        self::track($snapshot);

        [$code, $stdout, $stderr] = GitDiffResolver::git(
            $root,
            ['diff', '--cached', '--name-only', '-z', '-M', '--diff-filter=ACMR']
        );
        if ($code !== 0) {
            $snapshot->cleanup();
            throw new \RuntimeException('Cannot list staged files: ' . trim($stderr));
        }

        foreach (GitDiffResolver::splitNul($stdout) as $relative) {
            if (!GitDiffResolver::hasExtension($relative, $extensions) || !self::isSafeRelative($relative)) {
                continue;
            }

            [$showCode, $content, $showErr] = GitDiffResolver::git($root, ['show', ':' . $relative]);
            if ($showCode !== 0) {
                $snapshot->cleanup();
                throw new \RuntimeException("Cannot read staged content of {$relative}: " . trim($showErr));
            }

            $target = $dir . '/' . $relative;
            $snapshot->writeFile($target, $content);
            $snapshot->map[realpath($target) ?: $target] = $relative;
        }

        return $snapshot;
    }

    /**
     * Copy the *staged* version of unchanged context files (e.g. package.json,
     * app.config.yaml) into the snapshot so tools that need project context
     * (abqc) can run on it. Context files are NOT part of getFiles(): findings in
     * them are not reported unless they were also staged.
     *
     * @param string[] $basenames file names to copy wherever they are tracked
     * @return string[] repo-relative paths copied
     */
    public function addContextFiles(array $basenames): array
    {
        if ($basenames === []) {
            return [];
        }
        [$code, $stdout] = GitDiffResolver::git($this->repoRoot, ['ls-files', '-z', '--cached']);
        if ($code !== 0) {
            return [];
        }
        $wanted = array_flip(array_map('strtolower', $basenames));
        $copied = [];
        foreach (GitDiffResolver::splitNul($stdout) as $relative) {
            if (!isset($wanted[strtolower(basename($relative))]) || !self::isSafeRelative($relative)
                || str_contains('/' . $relative, '/node_modules/')) {
                continue;
            }
            $target = $this->dir . '/' . $relative;
            if (is_file($target)) {
                continue; // already present as a changed file
            }
            [$showCode, $content] = GitDiffResolver::git($this->repoRoot, ['show', ':' . $relative]);
            if ($showCode !== 0) {
                continue;
            }
            $this->writeFile($target, $content);
            $copied[] = $relative;
        }
        return $copied;
    }

    private function writeFile(string $target, string $content): void
    {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new \RuntimeException("Cannot create directory: {$parent}");
        }
        if (file_put_contents($target, $content) === false) {
            throw new \RuntimeException("Cannot write snapshot file: {$target}");
        }
    }

    public function getRepoRoot(): string
    {
        return $this->repoRoot;
    }

    public function getDirectory(): string
    {
        return $this->dir;
    }

    /** @return string[] absolute snapshot paths */
    public function getFiles(): array
    {
        return array_keys($this->map);
    }

    /** @return string[] repo-relative paths */
    public function getRelativePaths(): array
    {
        return array_values($this->map);
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }

    /**
     * Map a snapshot path (as reported by analyzers) back to its repo-relative path.
     * Unknown paths are returned unchanged.
     */
    public function toRelative(string $path): string
    {
        if (isset($this->map[$path])) {
            return $this->map[$path];
        }
        $real = realpath($path);
        if ($real !== false && isset($this->map[$real])) {
            return $this->map[$real];
        }
        foreach ([$this->dir, realpath($this->dir) ?: $this->dir] as $prefix) {
            if (str_starts_with($path, $prefix . '/')) {
                return substr($path, strlen($prefix) + 1);
            }
        }
        return $path;
    }

    public function cleanup(): void
    {
        unset(self::$live[spl_object_id($this)]);
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        $this->map = [];
    }

    /** @var array<int,self> snapshots not yet cleaned up (removed on shutdown/signal) */
    private static array $live = [];
    private static bool $handlersInstalled = false;

    /**
     * Staged source code must not be left in $TMPDIR if the process dies
     * (fatal error, exit(), Ctrl-C, SIGTERM from a CI timeout).
     */
    private static function track(self $snapshot): void
    {
        self::$live[spl_object_id($snapshot)] = $snapshot;
        if (self::$handlersInstalled) {
            return;
        }
        self::$handlersInstalled = true;
        register_shutdown_function([self::class, 'cleanupAll']);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGINT => 130, SIGTERM => 143, SIGHUP => 129] as $signal => $exitCode) {
                $previous = pcntl_signal_get_handler($signal);
                if ($previous !== SIG_DFL) {
                    continue; // someone else (e.g. Symfony Console) owns this signal
                }
                pcntl_signal($signal, static function () use ($exitCode): void {
                    self::cleanupAll();
                    exit($exitCode);
                });
            }
        }
    }

    /** @internal */
    public static function cleanupAll(): void
    {
        foreach (self::$live as $snapshot) {
            try {
                $snapshot->cleanup();
            } catch (\Throwable) {
                // best effort during shutdown
            }
        }
        self::$live = [];
    }

    private static function isSafeRelative(string $relative): bool
    {
        return $relative !== ''
            && !str_starts_with($relative, '/')
            && !in_array('..', explode('/', $relative), true);
    }
}
