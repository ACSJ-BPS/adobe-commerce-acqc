<?php
declare(strict_types=1);
namespace ACQC\Core\Git;

/**
 * Resolves changed files from git diff for --diff mode.
 */
class GitDiffResolver
{
    /** File extensions the audit engine analyses by default. */
    public const DEFAULT_EXTENSIONS = ['php', 'phtml', 'xml', 'graphqls'];

    /**
     * Return absolute paths of files changed relative to $base, filtered by extension.
     *
     * Unlike getChangedPhpFiles() this never silently falls back to another diff:
     * a quality gate must fail loudly when the requested range cannot be resolved.
     *
     * @param string   $base       'staged', 'working', a ref (e.g. main) or a range (a..b)
     * @param string[] $extensions lower-case extensions without dot
     * @return string[]
     */
    public function getChangedFiles(
        string $projectRoot,
        string $base = 'HEAD',
        array $extensions = self::DEFAULT_EXTENSIONS
    ): array {
        $root = $this->getRepositoryRoot($projectRoot);

        $args = match ($base) {
            'staged'  => ['diff', '--cached'],
            'working' => ['diff'],
            default   => ['diff', $base],
        };
        $args = array_merge($args, ['--name-only', '-z', '-M', '--diff-filter=ACMR']);

        [$code, $stdout, $stderr] = self::git($root, $args);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('git diff failed for "%s": %s', $base, trim($stderr)));
        }

        $files = [];
        foreach (self::splitNul($stdout) as $relative) {
            if (!self::hasExtension($relative, $extensions)) {
                continue;
            }
            $full = $root . '/' . $relative;
            if (is_file($full)) {
                $files[] = $full;
            }
        }

        return $files;
    }

    /**
     * Absolute path of the repository top-level directory containing $path.
     */
    public function getRepositoryRoot(string $path): string
    {
        $dir = is_dir($path) ? $path : dirname($path);
        [$code, $stdout, $stderr] = self::git($dir, ['rev-parse', '--show-toplevel']);
        if ($code !== 0) {
            throw new \RuntimeException("Not a git repository: {$path}" . ($stderr !== '' ? ' (' . trim($stderr) . ')' : ''));
        }
        return rtrim(trim($stdout), '/');
    }

    /**
     * Run git with an argument list (no shell interpolation).
     *
     * @param string[] $args
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    public static function git(string $cwd, array $args): array
    {
        $cmd = array_merge(['git', '-C', $cwd], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [127, '', 'failed to start git'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $stdout, $stderr];
    }

    /** @return string[] */
    public static function splitNul(string $output): array
    {
        return array_values(array_filter(explode("\0", $output), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Match a repo-relative path against a list of either bare extensions ("php")
     * or basename globs ("*.js", "package.json", "*.config.yaml"). [] matches all.
     *
     * @param string[] $extensions
     */
    public static function hasExtension(string $path, array $extensions): bool
    {
        if ($extensions === []) {
            return true;
        }
        $base = strtolower(basename($path));
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        foreach ($extensions as $entry) {
            $entry = strtolower($entry);
            if (strpbrk($entry, '*?[.') === false) {
                if ($ext === $entry) {
                    return true;
                }
            } elseif (fnmatch($entry, $base, FNM_PERIOD)) {
                return true;
            }
        }
        return false;
    }

    public function getChangedPhpFiles(string $projectRoot, string $base = 'HEAD'): array
    {
        $projectRoot = rtrim($projectRoot, '/');

        // Validate this is a git repo
        $checkCmd = sprintf('git -C %s rev-parse --is-inside-work-tree 2>&1', escapeshellarg($projectRoot));
        exec($checkCmd, $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException("Not a git repository: {$projectRoot}");
        }

        // Get changed files
        if ($base === 'staged') {
            $cmd = sprintf('git -C %s diff --cached --name-only --diff-filter=ACMR 2>&1', escapeshellarg($projectRoot));
        } elseif ($base === 'working') {
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR 2>&1', escapeshellarg($projectRoot));
        } elseif (str_contains($base, '..')) {
            // Range like main..HEAD
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR %s 2>&1', escapeshellarg($projectRoot), escapeshellarg($base));
        } else {
            // vs a branch/commit e.g. main or HEAD
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR %s 2>&1', escapeshellarg($projectRoot), escapeshellarg($base));
        }

        exec($cmd, $lines, $exitCode);
        if ($exitCode !== 0) {
            // Fallback: unstaged changes
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR 2>&1', escapeshellarg($projectRoot));
            exec($cmd, $lines, $exitCode);
        }

        $phpFiles = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!str_ends_with(strtolower($line), '.php')) continue;
            $full = $projectRoot . '/' . $line;
            if (is_file($full)) {
                $phpFiles[] = $full;
            }
        }

        return $phpFiles;
    }

    public function getCurrentBranch(string $projectRoot): string
    {
        $cmd = sprintf('git -C %s rev-parse --abbrev-ref HEAD 2>&1', escapeshellarg($projectRoot));
        exec($cmd, $lines, $code);
        return $code === 0 && !empty($lines) ? trim($lines[0]) : 'unknown';
    }

    public function getDefaultBase(string $projectRoot): string
    {
        // Try to find main/master
        foreach (['main', 'master', 'develop'] as $branch) {
            $cmd = sprintf('git -C %s rev-parse --verify %s 2>/dev/null', escapeshellarg($projectRoot), escapeshellarg($branch));
            exec($cmd, $out, $code);
            if ($code === 0) return $branch;
        }
        return 'HEAD';
    }
}
