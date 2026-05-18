<?php

declare(strict_types=1);

namespace ACQC\Analyzers;

class PerformanceAnalyzer implements AnalyzerInterface
{
    public function analyze(array $files, array $config = []): array
    {
        $issues = [];
        $metrics = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $fileIssues = $this->analyzeFileForPerformance($file, $content);
            $issues = array_merge($issues, $fileIssues);
        }

        $metrics['performance_score'] = $this->calculatePerformanceScore($issues);
        $metrics['potential_bottlenecks'] = count($issues);

        return [
            'issues' => $issues,
            'metrics' => $metrics,
            'recommendations' => $this->generatePerformanceRecommendations($issues),
        ];
    }

    public function getName(): string
    {
        return 'Performance Analyzer';
    }

    public function getDescription(): string
    {
        return 'Identifies potential performance bottlenecks and optimization opportunities';
    }

    public function getSupportedSeverities(): array
    {
        return ['error', 'warning', 'info'];
    }

    private function analyzeFileForPerformance(string $file, string $content): array
    {
        $issues = [];

        // Check for N+1 query patterns
        $issues = array_merge($issues, $this->checkNPlusOneQueries($file, $content));

        // Check for inefficient loops
        $issues = array_merge($issues, $this->checkInefficientLoops($file, $content));

        // Check for memory leaks
        $issues = array_merge($issues, $this->checkMemoryLeaks($file, $content));

        // Check for expensive operations in loops
        $issues = array_merge($issues, $this->checkExpensiveOperationsInLoops($file, $content));

        return $issues;
    }

    private function checkNPlusOneQueries(string $file, string $content): array
    {
        $issues = [];
        
        // Look for database queries inside loops
        if (preg_match_all('/(foreach|for|while).*?\{.*?(->load\(|->save\(|->delete\(|SELECT|INSERT|UPDATE|DELETE)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $issues[] = [
                    'file' => $file,
                    'line' => $line,
                    'severity' => 'error',
                    'type' => 'n_plus_one_query',
                    'message' => 'Potential N+1 query: database operation inside loop',
                ];
            }
        }

        return $issues;
    }

    private function checkInefficientLoops(string $file, string $content): array
    {
        $issues = [];
        
        // Check for count() in loop conditions
        if (preg_match_all('/for\s*\([^;]*;\s*[^;]*count\([^)]+\)[^;]*;/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $issues[] = [
                    'file' => $file,
                    'line' => $line,
                    'severity' => 'warning',
                    'type' => 'inefficient_loop',
                    'message' => 'Inefficient loop: count() called in loop condition',
                ];
            }
        }

        return $issues;
    }

    private function checkMemoryLeaks(string $file, string $content): array
    {
        $issues = [];
        
        // Check for potential memory leaks with large arrays
        if (preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\[\]\s*=.*?(foreach|for|while)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $issues[] = [
                    'file' => $file,
                    'line' => $line,
                    'severity' => 'warning',
                    'type' => 'memory_leak',
                    'message' => 'Potential memory leak: array growing in loop without bounds',
                ];
            }
        }

        return $issues;
    }

    private function checkExpensiveOperationsInLoops(string $file, string $content): array
    {
        $issues = [];
        $expensiveOperations = [
            'file_get_contents',
            'curl_exec',
            'preg_match',
            'preg_replace',
            'json_decode',
            'unserialize',
        ];

        foreach ($expensiveOperations as $operation) {
            if (preg_match_all('/(foreach|for|while).*?\{.*?' . preg_quote($operation) . '/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $issues[] = [
                        'file' => $file,
                        'line' => $line,
                        'severity' => 'warning',
                        'type' => 'expensive_operation_in_loop',
                        'message' => "Expensive operation '{$operation}' called inside loop",
                    ];
                }
            }
        }

        return $issues;
    }

    private function calculatePerformanceScore(array $issues): int
    {
        $errorCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'error'));
        $warningCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'warning'));
        
        $baseScore = 100;
        $penalty = ($errorCount * 15) + ($warningCount * 5);
        
        return max(0, $baseScore - $penalty);
    }

    private function generatePerformanceRecommendations(array $issues): array
    {
        $recommendations = [];
        $issueTypes = array_count_values(array_column($issues, 'type'));

        if (($issueTypes['n_plus_one_query'] ?? 0) > 0) {
            $recommendations[] = 'Use batch loading or collection-based queries to avoid N+1 query problems';
        }

        if (($issueTypes['inefficient_loop'] ?? 0) > 0) {
            $recommendations[] = 'Cache loop conditions and optimize loop performance';
        }

        if (($issueTypes['memory_leak'] ?? 0) > 0) {
            $recommendations[] = 'Implement proper memory management and array size limits';
        }

        if (($issueTypes['expensive_operation_in_loop'] ?? 0) > 0) {
            $recommendations[] = 'Move expensive operations outside of loops when possible';
        }

        return $recommendations;
    }
} 