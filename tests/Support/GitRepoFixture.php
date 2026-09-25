<?php

declare(strict_types=1);

namespace ACQC\Tests\Support;

/**
 * Throwaway git repository under sys_get_temp_dir() for git-aware tests.
 */
final class GitRepoFixture
{
    public readonly string $root;

    public function __construct()
    {
        $dir = sys_get_temp_dir() . '/acqc_git_fixture_' . bin2hex(random_bytes(5));
        mkdir($dir, 0755, true);
        $this->root = realpath($dir) ?: $dir;
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'ACQC Test');
        $this->git('config', 'commit.gpgsign', 'false');
    }

    public static function gitAvailable(): bool
    {
        exec('git --version 2>/dev/null', $out, $code);
        return $code === 0;
    }

    public function write(string $relative, string $content): string
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
        return $path;
    }

    public function git(string ...$args): string
    {
        $cmd  = array_merge(['git', '-C', $this->root], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . $err);
        }
        return $out;
    }

    public function commitAll(string $message = 'commit'): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
    }

    public function destroy(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }
}
