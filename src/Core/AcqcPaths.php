<?php

declare(strict_types=1);

namespace ACQC\Core;

/**
 * Resolves the root under which bundled resources (config/, templates/) live.
 *
 * Normally this is the repository/package root (`dirname(__DIR__, 2)`), so
 * behaviour is byte-identical to the previous inline `dirname(__DIR__)` calls.
 *
 * When the tool runs from a PHAR, those resources live at `phar://…` paths that
 * Symfony Finder / glob() / realpath() / Twig's FilesystemLoader do not traverse
 * reliably. In that case we extract `config/` and `templates/` once to a stable
 * temp directory and return that — so all existing filesystem-based loading
 * keeps working unchanged. This is the single source of truth for the resource
 * root; callers must not recompute it via `dirname(__DIR__)`.
 */
final class AcqcPaths
{
    private static ?string $resourceRoot = null;

    public static function resourceRoot(): string
    {
        if (self::$resourceRoot !== null) {
            return self::$resourceRoot;
        }

        $pharPath = \Phar::running(false); // '' when not running from a PHAR
        if ($pharPath === '') {
            return self::$resourceRoot = dirname(__DIR__, 2);
        }

        $dest = sys_get_temp_dir() . '/acqc-res-' . substr(md5($pharPath . '|' . (string) @filemtime($pharPath)), 0, 12);
        if (!is_dir($dest . '/config')) {
            self::extractResources($pharPath, $dest);
        }

        return self::$resourceRoot = $dest;
    }

    /** Reset the memoized root (test helper). */
    public static function reset(): void
    {
        self::$resourceRoot = null;
    }

    /** Extract only config/ and templates/ from the PHAR to $dest (idempotent). */
    private static function extractResources(string $pharPath, string $dest): void
    {
        try {
            $phar   = new \Phar($pharPath);
            $prefix = 'phar://' . $pharPath . '/';
            $entries = [];
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                $rel = str_replace('\\', '/', (string) $file->getPathname());
                $rel = substr($rel, strlen($prefix));
                if (str_starts_with($rel, 'config/') || str_starts_with($rel, 'templates/')) {
                    $entries[] = $rel;
                }
            }
            if ($entries !== []) {
                @mkdir($dest, 0777, true);
                $phar->extractTo($dest, $entries, true);
            }
        } catch (\Throwable) {
            // Extraction failure leaves resourceRoot pointing at a missing dir;
            // callers degrade the same way they would for any absent config.
        }
    }
}
