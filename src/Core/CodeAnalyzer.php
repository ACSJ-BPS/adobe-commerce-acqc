<?php

declare(strict_types=1);

namespace ACQC\Core;

use ACQC\Analyzers\AnalyzerInterface;
use ACQC\Analyzers\MagentoSpecificAnalyzer;
use ACQC\Analyzers\SecurityAnalyzer;
use ACQC\Analyzers\PerformanceAnalyzer;
use ACQC\Analyzers\CodeQualityAnalyzer;
use ACQC\Utils\FileScanner;
use Symfony\Component\Finder\Finder;

class CodeAnalyzer
{
    private array $availableAnalyzers = [];
    private FileScanner $fileScanner;

    public function __construct()
    {
        $this->fileScanner = new FileScanner();
        $this->initializeAnalyzers();
    }

    public function analyze(string $target, array $options = []): array
    {
        $files = $this->resolveTargetFiles($target);
        $analyzers = $this->getSelectedAnalyzers($options['analyzers'] ?? []);

        $results = [
            'target' => $target,
            'files_processed' => count($files),
            'issues' => [],
            'metrics' => ['lines_of_code' => $this->countLines($files)],
        ];

        foreach ($analyzers as $analyzer) {
            $analyzerResults = $analyzer->analyze($files, []);
            $results['issues'] = array_merge($results['issues'], $analyzerResults['issues'] ?? []);
            $results['metrics'] = array_merge($results['metrics'], $analyzerResults['metrics'] ?? []);
        }

        // Apply auto-fixes if requested
        if ($options['fix'] ?? false) {
            $this->applyAutoFixes($results['issues']);
        }

        return $results;
    }

    private function countLines(array $files): int
    {
        $lines = 0;
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $lines += substr_count($content, "\n") + 1;
            }
        }

        return $lines;
    }

    public function getAvailableAnalyzers(): array
    {
        return array_keys($this->availableAnalyzers);
    }

    public function analyzeModule(string $moduleNamespace, string $basePath = 'app/code', array $options = []): array
    {
        $modulePath = $this->resolveModulePath($moduleNamespace, $basePath);
        
        if (!is_dir($modulePath)) {
            throw new \InvalidArgumentException("Module not found: {$moduleNamespace} at {$modulePath}");
        }

        $files = $this->fileScanner->scanProject($modulePath, ['*.php'], []);
        $analyzers = $this->getSelectedAnalyzers($options['analyzers'] ?? []);

        $results = [
            'module' => $moduleNamespace,
            'module_path' => $modulePath,
            'files_processed' => count($files),
            'issues' => [],
            'metrics' => [],
        ];

        foreach ($analyzers as $analyzer) {
            $analyzerResults = $analyzer->analyze($files, []);
            $results['issues'] = array_merge($results['issues'], $analyzerResults['issues'] ?? []);
            $results['metrics'] = array_merge($results['metrics'], $analyzerResults['metrics'] ?? []);
        }

        // Apply auto-fixes if requested
        if ($options['fix'] ?? false) {
            $this->applyAutoFixes($results['issues']);
        }

        return $results;
    }

    public function analyzeAllModules(string $basePath = 'app/code', array $options = []): array
    {
        $modules = $this->discoverModules($basePath);
        
        if (empty($modules)) {
            throw new \InvalidArgumentException("No modules found in: {$basePath}");
        }

        $allResults = [
            'modules_analyzed' => count($modules),
            'modules' => [],
            'issues' => [],
            'metrics' => [],
            'summary' => [],
        ];

        foreach ($modules as $moduleNamespace) {
            try {
                $moduleResults = $this->analyzeModule($moduleNamespace, $basePath, $options);
                $allResults['modules'][$moduleNamespace] = $moduleResults;
                $allResults['issues'] = array_merge($allResults['issues'], $moduleResults['issues']);
                $allResults['metrics'] = array_merge($allResults['metrics'], $moduleResults['metrics']);
                
                // Add to summary
                $allResults['summary'][] = [
                    'module' => $moduleNamespace,
                    'files' => $moduleResults['files_processed'],
                    'issues' => count($moduleResults['issues']),
                ];
            } catch (\Exception $e) {
                // Continue with other modules if one fails
                $allResults['issues'][] = [
                    'file' => "Module: {$moduleNamespace}",
                    'line' => 0,
                    'severity' => 'error',
                    'type' => 'module_analysis_error',
                    'message' => "Failed to analyze module: " . $e->getMessage(),
                ];
            }
        }

        return $allResults;
    }

    private function initializeAnalyzers(): void
    {
        $this->availableAnalyzers = [
            'magento' => new MagentoSpecificAnalyzer(),
            'security' => new SecurityAnalyzer(),
            'performance' => new PerformanceAnalyzer(),
            'quality' => new CodeQualityAnalyzer(),
        ];
    }

    private function resolveTargetFiles(string $target): array
    {
        if (is_file($target)) {
            return [$target];
        }

        if (is_dir($target)) {
            return $this->fileScanner->scanProject($target, ['*.php'], []);
        }

        // Handle glob patterns
        $finder = new Finder();
        $files = [];
        
        try {
            foreach ($finder->files()->name($target) as $file) {
                $files[] = $file->getRealPath();
            }
        } catch (\Exception $e) {
            // If glob pattern fails, return empty array
        }

        return $files;
    }

    private function getSelectedAnalyzers(array $selectedAnalyzers): array
    {
        if (empty($selectedAnalyzers)) {
            return array_values($this->availableAnalyzers);
        }

        $analyzers = [];
        foreach ($selectedAnalyzers as $analyzerName) {
            if (isset($this->availableAnalyzers[$analyzerName])) {
                $analyzers[] = $this->availableAnalyzers[$analyzerName];
            }
        }

        return $analyzers;
    }

    private function applyAutoFixes(array $issues): void
    {
        foreach ($issues as $issue) {
            if (isset($issue['auto_fix']) && $issue['auto_fix']) {
                // Apply auto-fix logic here
                // This would involve modifying the actual files
            }
        }
    }

    private function resolveModulePath(string $moduleNamespace, string $basePath): string
    {
        // Convert namespace to path (e.g., "Vendor/Module" -> "Vendor/Module")
        $modulePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $moduleNamespace);
        
        // Ensure we have a valid module namespace format
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*[\/\\\\][A-Z][a-zA-Z0-9]*$/', $moduleNamespace)) {
            throw new \InvalidArgumentException("Invalid module namespace format: {$moduleNamespace}. Expected format: Vendor/Module");
        }

        $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $modulePath;
        
        return $fullPath;
    }

    private function discoverModules(string $basePath): array
    {
        if (!is_dir($basePath)) {
            throw new \InvalidArgumentException("Base path does not exist: {$basePath}");
        }

        $modules = [];
        $finder = new Finder();
        
        try {
            // Look for registration.php files which indicate Magento modules
            $finder->files()
                   ->name('registration.php')
                   ->in($basePath)
                   ->depth(2); // Should be at Vendor/Module/registration.php level

            foreach ($finder as $file) {
                $modulePath = $file->getPath();
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $modulePath);
                $moduleNamespace = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                
                // Validate module namespace format
                if (preg_match('/^[A-Z][a-zA-Z0-9]*\/[A-Z][a-zA-Z0-9]*$/', $moduleNamespace)) {
                    $modules[] = $moduleNamespace;
                }
            }
        } catch (\Exception $e) {
            // Fallback: scan for directories with proper structure
            $this->discoverModulesByStructure($basePath, $modules);
        }

        return array_unique($modules);
    }

    private function discoverModulesByStructure(string $basePath, array &$modules): void
    {
        $finder = new Finder();
        
        try {
            $finder->directories()
                   ->in($basePath)
                   ->depth(1); // Vendor/Module level

            foreach ($finder as $dir) {
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $dir->getRealPath());
                $moduleNamespace = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                
                // Check if it looks like a module (has PHP files and proper structure)
                if (preg_match('/^[A-Z][a-zA-Z0-9]*\/[A-Z][a-zA-Z0-9]*$/', $moduleNamespace)) {
                    $modulePath = $dir->getRealPath();
                    
                    // Verify it has some PHP files
                    $phpFinder = new Finder();
                    $phpFiles = $phpFinder->files()->name('*.php')->in($modulePath)->depth('< 3');
                    
                    if ($phpFiles->count() > 0) {
                        $modules[] = $moduleNamespace;
                    }
                }
            }
        } catch (\Exception $e) {
            // Silent fallback
        }
    }
} 