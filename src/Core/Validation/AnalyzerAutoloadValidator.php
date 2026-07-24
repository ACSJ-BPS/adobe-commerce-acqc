<?php

declare(strict_types=1);

namespace ACQC\Core\Validation;

/**
 * Detects PSR-4 autoload mismatches (wrong class name casing vs filename).
 *
 * Case-insensitive filesystems (default macOS) hide these; Linux/Docker fail at runtime.
 */
final class AnalyzerAutoloadValidator
{
    /**
     * @return list<string> Human-readable issue descriptions
     */
    public function validateAcqcPackage(string $acqcRoot): array
    {
        $acqcRoot = rtrim($acqcRoot, '/');
        $issues = [];
        $canonicalByLower = $this->buildCanonicalClassMap($acqcRoot . '/src/Analyzers');

        $orchestratorPath = $acqcRoot . '/src/Core/Analysis/AnalyzerOrchestrator.php';
        if (is_readable($orchestratorPath)) {
            $issues = array_merge(
                $issues,
                $this->validateOrchestratorUses($orchestratorPath, $acqcRoot, $canonicalByLower)
            );
        }

        $issues = array_merge(
            $issues,
            $this->validateSourceReferences($acqcRoot . '/src', $acqcRoot, $canonicalByLower)
        );

        return array_values(array_unique($issues));
    }

    /**
     * @return array<string, string> lowercase FQCN => canonical FQCN
     */
    private function buildCanonicalClassMap(string $analyzersRoot): array
    {
        $map = [];
        if (!is_dir($analyzersRoot)) {
            return $map;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($analyzersRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (!preg_match('/namespace\s+(ACQC\\\\Analyzers[^;]*);/', $content, $namespaceMatch)) {
                continue;
            }
            if (!preg_match('/^(?:abstract\s+)?class\s+(\w+)/m', $content, $classMatch)) {
                continue;
            }

            $fileBase = $file->getBasename('.php');
            if ($fileBase !== $classMatch[1]) {
                continue;
            }

            $fqcn = $namespaceMatch[1] . '\\' . $classMatch[1];
            $map[strtolower($fqcn)] = $fqcn;
        }

        return $map;
    }

    /**
     * @param array<string, string> $canonicalByLower
     * @return list<string>
     */
    private function validateOrchestratorUses(
        string $orchestratorPath,
        string $acqcRoot,
        array $canonicalByLower
    ): array {
        $content = (string) file_get_contents($orchestratorPath);
        $issues = [];

        if (!preg_match_all('/use (ACQC\\\\Analyzers\\\\[^;]+);/', $content, $matches)) {
            return $issues;
        }

        foreach ($matches[1] as $fqcn) {
            $issues = array_merge(
                $issues,
                $this->validateFqcn($fqcn, $orchestratorPath, $acqcRoot, $canonicalByLower)
            );
        }

        return $issues;
    }

    /**
     * @param array<string, string> $canonicalByLower
     * @return list<string>
     */
    private function validateSourceReferences(
        string $srcRoot,
        string $acqcRoot,
        array $canonicalByLower
    ): array {
        $issues = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (!preg_match_all('/(?:use|new)\s+(ACQC\\\\Analyzers\\\\[A-Za-z0-9\\\\]+)/', $content, $matches)) {
                continue;
            }

            foreach (array_unique($matches[1]) as $ref) {
                $issues = array_merge(
                    $issues,
                    $this->validateFqcn($ref, $file->getPathname(), $acqcRoot, $canonicalByLower)
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, string> $canonicalByLower
     * @return list<string>
     */
    private function validateFqcn(
        string $fqcn,
        string $contextPath,
        string $acqcRoot,
        array $canonicalByLower
    ): array {
        $lower = strtolower($fqcn);
        if (isset($canonicalByLower[$lower]) && $canonicalByLower[$lower] !== $fqcn) {
            return [
                sprintf(
                    '%s references %s but canonical class is %s',
                    $contextPath,
                    $fqcn,
                    $canonicalByLower[$lower]
                ),
            ];
        }

        $relativePath = 'src/' . str_replace('\\', '/', substr($fqcn, strlen('ACQC\\'))) . '.php';
        $fullPath = $acqcRoot . '/' . $relativePath;
        if (is_file($fullPath)) {
            return [];
        }

        $dir = dirname($fullPath);
        $expectedFile = basename($fullPath);
        if (!is_dir($dir)) {
            return [];
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strcasecmp($entry, $expectedFile) === 0 && $entry !== $expectedFile) {
                return [
                    sprintf(
                        '%s references %s (expects %s) but file on disk is %s',
                        $contextPath,
                        $fqcn,
                        $expectedFile,
                        $entry
                    ),
                ];
            }
        }

        return [];
    }
}
