<?php

declare(strict_types=1);

namespace ACQC\Core\Git;

/**
 * Installs / removes / inspects the ACQC git pre-commit hook in a target repository.
 *
 * - Respects core.hooksPath and worktrees (uses `git rev-parse --git-path hooks`).
 * - Never destroys a foreign hook: an existing non-ACQC pre-commit hook is moved to
 *   pre-commit.local and chained (run first) by the ACQC hook; uninstall restores it.
 */
final class HookInstaller
{
    public const MARKER       = '>>> ACQC pre-commit hook';
    public const HOOK_VERSION = 1;
    public const HOOK_NAME    = 'pre-commit';
    public const CHAINED_NAME = 'pre-commit.local';

    public function __construct(
        private readonly string $templatePath = '',
    ) {
    }

    /**
     * @return array{hook:string,chained:?string,updated:bool}
     */
    public function install(string $path, string $acqcBin, string $phpBinary, string $profile = 'pre-commit', bool $force = false, bool $strict = false): array
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $profile)) {
            throw new \InvalidArgumentException("Invalid profile name: {$profile}");
        }
        if (!is_file($acqcBin)) {
            throw new \InvalidArgumentException("acqc binary not found: {$acqcBin}");
        }

        $hooksDir = $this->hooksDir($path);
        if (!is_dir($hooksDir) && !mkdir($hooksDir, 0755, true) && !is_dir($hooksDir)) {
            throw new \RuntimeException("Cannot create hooks directory: {$hooksDir}");
        }

        $hook    = $hooksDir . '/' . self::HOOK_NAME;
        $chained = $hooksDir . '/' . self::CHAINED_NAME;
        $updated = false;

        if (is_file($hook) || is_link($hook)) {
            if ($this->isAcqcHook($hook)) {
                $updated = true;
            } elseif (file_exists($chained) && !$force) {
                throw new \RuntimeException(sprintf(
                    'A non-ACQC %s hook exists and %s is already taken. Move one of them or use --force (overwrites %s).',
                    self::HOOK_NAME, self::CHAINED_NAME, self::HOOK_NAME
                ));
            } elseif (!file_exists($chained)) {
                if (!rename($hook, $chained)) {
                    throw new \RuntimeException("Cannot preserve existing hook as {$chained}");
                }
            }
        }

        $script = $this->render($acqcBin, $phpBinary, $profile, $strict);
        if (file_put_contents($hook, $script) === false) {
            throw new \RuntimeException("Cannot write hook: {$hook}");
        }
        chmod($hook, 0755);

        return ['hook' => $hook, 'chained' => is_file($chained) ? $chained : null, 'updated' => $updated];
    }

    /**
     * @return array{removed:bool,restored:bool,hook:string}
     */
    public function uninstall(string $path): array
    {
        $hooksDir = $this->hooksDir($path);
        $hook     = $hooksDir . '/' . self::HOOK_NAME;
        $chained  = $hooksDir . '/' . self::CHAINED_NAME;

        if (!is_file($hook) || !$this->isAcqcHook($hook)) {
            return ['removed' => false, 'restored' => false, 'hook' => $hook];
        }

        unlink($hook);
        $restored = false;
        if (is_file($chained)) {
            $restored = rename($chained, $hook);
        }

        return ['removed' => true, 'restored' => $restored, 'hook' => $hook];
    }

    /**
     * @return array{installed:bool,hook:string,hooks_dir:string,version:?int,acqc_bin:?string,acqc_bin_exists:bool,php:?string,profile:?string,chained:?string,foreign_hook:bool,executable:bool}
     */
    public function status(string $path): array
    {
        $hooksDir = $this->hooksDir($path);
        $hook     = $hooksDir . '/' . self::HOOK_NAME;
        $chained  = $hooksDir . '/' . self::CHAINED_NAME;

        $status = [
            'installed' => false, 'hook' => $hook, 'hooks_dir' => $hooksDir, 'version' => null,
            'acqc_bin' => null, 'acqc_bin_exists' => false, 'php' => null, 'profile' => null,
            'chained' => is_file($chained) ? $chained : null,
            'foreign_hook' => false, 'executable' => false,
        ];

        if (!is_file($hook)) {
            return $status;
        }
        if (!$this->isAcqcHook($hook)) {
            $status['foreign_hook'] = true;
            return $status;
        }

        $content = (string) file_get_contents($hook);
        $status['installed']  = true;
        $status['executable'] = is_executable($hook);
        if (preg_match('/^# acqc-hook-version: (\d+)$/m', $content, $m)) {
            $status['version'] = (int) $m[1];
        }
        if (preg_match("/^ACQC_INSTALLED_BIN='(.*)'$/m", $content, $m)) {
            $status['acqc_bin']        = self::shellUnquote($m[1]);
            $status['acqc_bin_exists'] = is_file($status['acqc_bin']);
        }
        if (preg_match("/^ACQC_INSTALLED_PHP='(.*)'$/m", $content, $m)) {
            $status['php'] = self::shellUnquote($m[1]);
        }
        if (preg_match('/ACQC_PROFILE:-([A-Za-z0-9_.-]+)\}/', $content, $m)) {
            $status['profile'] = $m[1];
        }

        return $status;
    }

    public function hooksDir(string $path): string
    {
        $root = (new GitDiffResolver())->getRepositoryRoot($path);
        [$code, $stdout, $stderr] = GitDiffResolver::git($root, ['rev-parse', '--git-path', 'hooks']);
        if ($code !== 0) {
            throw new \RuntimeException('Cannot resolve git hooks directory: ' . trim($stderr));
        }
        $dir = trim($stdout);
        if (!str_starts_with($dir, '/')) {
            $dir = $root . '/' . $dir;
        }
        return rtrim($dir, '/');
    }

    public function isAcqcHook(string $file): bool
    {
        $head = (string) @file_get_contents($file, false, null, 0, 4096);
        return str_contains($head, self::MARKER);
    }

    public function render(string $acqcBin, string $phpBinary, string $profile, bool $strict = false): string
    {
        $template = $this->templatePath !== '' ? $this->templatePath : self::defaultTemplatePath();
        $content  = file_get_contents($template);
        if ($content === false) {
            throw new \RuntimeException("Hook template not found: {$template}");
        }

        return strtr($content, [
            '__ACQC_BIN__'     => self::shellQuoteInner($acqcBin),
            '__ACQC_PHP__'     => self::shellQuoteInner($phpBinary),
            '__ACQC_PROFILE__' => $profile,
            '__ACQC_STRICT__'  => $strict ? '1' : '0',
        ]);
    }

    public static function defaultTemplatePath(): string
    {
        return dirname(__DIR__, 3) . '/templates/hooks/pre-commit.sh';
    }

    public static function defaultAcqcBin(): string
    {
        return dirname(__DIR__, 3) . '/bin/acqc';
    }

    /** Escape for use inside a single-quoted POSIX shell string. */
    private static function shellQuoteInner(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }

    private static function shellUnquote(string $value): string
    {
        return str_replace("'\\''", "'", $value);
    }
}
