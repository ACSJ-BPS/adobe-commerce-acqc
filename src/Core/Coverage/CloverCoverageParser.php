<?php

declare(strict_types=1);

namespace ACQC\Core\Coverage;

/**
 * Parses PHPUnit Clover XML coverage reports.
 *
 * Module grouping in parseWithModuleBreakdown() mirrors
 * ReportCommand::extractModuleName() exactly so the returned keys line up
 * with the module_metrics keys the caller already builds.
 */
class CloverCoverageParser
{
    use CoverageFallbackTrait;

    /**
     * @return array{line_coverage: float, branch_coverage: float, function_coverage: float}
     */
    public function parse(string $path, string $basePath): array
    {
        $project = $this->loadProjectMetrics($path);
        if ($project === null) {
            return $this->zeroed();
        }

        $lineCoverage = $this->ratio((int) $project['coveredstatements'], (int) $project['statements']);
        $functionCoverage = $this->ratio((int) $project['coveredmethods'], (int) $project['methods']);

        return [
            'line_coverage' => $lineCoverage,
            'branch_coverage' => $this->estimateBranchCoverage($lineCoverage),
            'function_coverage' => $functionCoverage,
        ];
    }

    /**
     * @return array{modules: array<string, array{lines: array{covered: int, total: int, pct: float}, functions: array{covered: int, total: int, pct: float}, classes: array{covered: int, total: int, pct: float}}>}
     */
    public function parseWithModuleBreakdown(string $path, string $basePath): array
    {
        $xml = $this->loadXml($path);
        if ($xml === null) {
            return ['modules' => []];
        }

        $modules = [];

        foreach ($xml->xpath('//file') ?: [] as $file) {
            $name = (string) ($file['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $metrics = $file->metrics ?? null;
            if ($metrics === null) {
                continue;
            }

            $moduleName = $this->extractModuleName($name, $basePath);

            if (!isset($modules[$moduleName])) {
                $modules[$moduleName] = [
                    'lines' => ['covered' => 0, 'total' => 0],
                    'functions' => ['covered' => 0, 'total' => 0],
                    'classes' => ['covered' => 0, 'total' => 0],
                ];
            }

            $modules[$moduleName]['lines']['covered'] += (int) ($metrics['coveredstatements'] ?? 0);
            $modules[$moduleName]['lines']['total'] += (int) ($metrics['statements'] ?? 0);
            $modules[$moduleName]['functions']['covered'] += (int) ($metrics['coveredmethods'] ?? 0);
            $modules[$moduleName]['functions']['total'] += (int) ($metrics['methods'] ?? 0);
            $modules[$moduleName]['classes']['covered'] += (int) ($metrics['coveredelements'] ?? 0);
            $modules[$moduleName]['classes']['total'] += (int) ($metrics['elements'] ?? 0);
        }

        foreach ($modules as &$module) {
            foreach (['lines', 'functions', 'classes'] as $key) {
                $module[$key]['pct'] = $module[$key]['total'] > 0
                    ? round(($module[$key]['covered'] / $module[$key]['total']) * 100, 2)
                    : 0.0;
            }
        }
        unset($module);

        return ['modules' => $modules];
    }

    /**
     * @return array<string, string|int>|null
     */
    private function loadProjectMetrics(string $path): ?array
    {
        $xml = $this->loadXml($path);
        if ($xml === null) {
            return null;
        }

        $metrics = $xml->xpath('//project/metrics');
        if ($metrics === null || count($metrics) === 0) {
            return null;
        }

        $attributes = [];
        foreach ($metrics[0]->attributes() ?? [] as $key => $value) {
            $attributes[$key] = (string) $value;
        }

        return $attributes;
    }

    private function loadXml(string $path): ?\SimpleXMLElement
    {
        if (!is_file($path)) {
            return null;
        }

        libxml_use_internal_errors(true);
        try {
            $content = @file_get_contents($path);
            if ($content === false || trim($content) === '') {
                return null;
            }

            $xml = simplexml_load_string($content);

            return $xml !== false ? $xml : null;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
        }
    }

    private function ratio(int $covered, int $total): float
    {
        return $total > 0 ? $covered / $total : 0.0;
    }

    /**
     * Mirrors ReportCommand::extractModuleName() so module keys align.
     */
    private function extractModuleName(string $filePath, string $basePath): string
    {
        $relativePath = str_replace($basePath . '/', '', $filePath);
        $pathParts = explode('/', $relativePath);

        if (count($pathParts) >= 2) {
            return $pathParts[0] . '_' . $pathParts[1];
        }

        if (count($pathParts) === 1 && $pathParts[0] !== '') {
            return $pathParts[0];
        }

        return basename($filePath);
    }
}
