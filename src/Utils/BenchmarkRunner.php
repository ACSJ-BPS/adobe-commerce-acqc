<?php

declare(strict_types=1);

namespace ACQC\Utils;

use Psr\Log\LoggerInterface;

/**
 * Benchmark Runner
 * 
 * Performance benchmarking utility for ACQC components.
 */
class BenchmarkRunner
{
    private LoggerInterface $logger;
    private PerformanceProfiler $profiler;
    private array $benchmarks = [];
    private array $results = [];

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->profiler = new PerformanceProfiler($this->logger);
    }

    /**
     * Add benchmark test
     */
    public function addBenchmark(string $name, callable $test, array $config = []): self
    {
        $this->benchmarks[$name] = [
            'test' => $test,
            'iterations' => $config['iterations'] ?? 100,
            'warmup' => $config['warmup'] ?? 10,
            'timeout' => $config['timeout'] ?? 30,
            'memory_limit' => $config['memory_limit'] ?? null
        ];

        return $this;
    }

    /**
     * Run all benchmarks
     */
    public function runAll(): array
    {
        $this->logger->info('Starting benchmark suite', [
            'benchmarks' => count($this->benchmarks)
        ]);

        foreach ($this->benchmarks as $name => $config) {
            $this->results[$name] = $this->runBenchmark($name, $config);
        }

        return $this->results;
    }

    /**
     * Run specific benchmark
     */
    public function run(string $name): ?array
    {
        if (!isset($this->benchmarks[$name])) {
            throw new \InvalidArgumentException("Benchmark '$name' not found");
        }

        $config = $this->benchmarks[$name];
        return $this->runBenchmark($name, $config);
    }

    /**
     * Generate benchmark report
     */
    public function generateReport(string $format = 'text'): string
    {
        switch ($format) {
            case 'json':
                return json_encode($this->results, JSON_PRETTY_PRINT);
            case 'csv':
                return $this->generateCsvReport();
            default:
                return $this->generateTextReport();
        }
    }

    /**
     * Compare benchmark results
     */
    public function compare(array $baseline, array $current): array
    {
        $comparison = [];

        foreach ($current as $name => $result) {
            if (!isset($baseline[$name])) {
                continue;
            }

            $baselineResult = $baseline[$name];
            $comparison[$name] = [
                'baseline' => $baselineResult,
                'current' => $result,
                'performance_change' => $this->calculatePerformanceChange(
                    $baselineResult['avg_duration'],
                    $result['avg_duration']
                ),
                'memory_change' => $this->calculateMemoryChange(
                    $baselineResult['avg_memory'],
                    $result['avg_memory']
                )
            ];
        }

        return $comparison;
    }

    private function runBenchmark(string $name, array $config): array
    {
        $this->logger->info("Running benchmark: $name");

        $test = $config['test'];
        $iterations = $config['iterations'];
        $warmup = $config['warmup'];

        // Warmup runs
        for ($i = 0; $i < $warmup; $i++) {
            try {
                $test();
            } catch (\Throwable $e) {
                // Ignore warmup errors
            }
        }

        // Actual benchmark runs
        $durations = [];
        $memoryUsages = [];
        $errors = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            try {
                $test();
                
                $duration = microtime(true) - $startTime;
                $memoryUsed = memory_get_usage(true) - $startMemory;
                
                $durations[] = $duration;
                $memoryUsages[] = $memoryUsed;
                
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->warning("Benchmark error in $name", [
                    'iteration' => $i,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $this->calculateStatistics($name, $durations, $memoryUsages, $errors, $iterations);
    }

    private function calculateStatistics(string $name, array $durations, array $memoryUsages, int $errors, int $iterations): array
    {
        if (empty($durations)) {
            return [
                'name' => $name,
                'error' => 'No successful iterations',
                'errors' => $errors,
                'total_iterations' => $iterations
            ];
        }

        sort($durations);
        sort($memoryUsages);

        $count = count($durations);
        $avgDuration = array_sum($durations) / $count;
        $avgMemory = array_sum($memoryUsages) / $count;

        return [
            'name' => $name,
            'iterations' => $count,
            'errors' => $errors,
            'success_rate' => ($count / $iterations) * 100,
            'avg_duration' => $avgDuration,
            'min_duration' => min($durations),
            'max_duration' => max($durations),
            'median_duration' => $this->calculateMedian($durations),
            'std_dev_duration' => $this->calculateStandardDeviation($durations),
            'avg_memory' => $avgMemory,
            'min_memory' => min($memoryUsages),
            'max_memory' => max($memoryUsages),
            'median_memory' => $this->calculateMedian($memoryUsages),
            'operations_per_second' => 1 / $avgDuration,
            'formatted' => [
                'avg_duration' => $this->formatDuration($avgDuration),
                'avg_memory' => $this->formatBytes($avgMemory),
                'ops_per_sec' => number_format(1 / $avgDuration, 2)
            ]
        ];
    }

    private function calculateMedian(array $values): float
    {
        $count = count($values);
        
        if ($count % 2 === 0) {
            return ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
        } else {
            return $values[($count - 1) / 2];
        }
    }

    private function calculateStandardDeviation(array $values): float
    {
        $count = count($values);
        $mean = array_sum($values) / $count;
        
        $squaredDifferences = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);
        
        $variance = array_sum($squaredDifferences) / $count;
        
        return sqrt($variance);
    }

    private function calculatePerformanceChange(float $baseline, float $current): array
    {
        $change = (($current - $baseline) / $baseline) * 100;
        
        return [
            'percentage' => $change,
            'status' => $change < -5 ? 'improved' : ($change > 5 ? 'degraded' : 'stable'),
            'description' => $this->formatPerformanceChange($change)
        ];
    }

    private function calculateMemoryChange(int $baseline, int $current): array
    {
        $change = (($current - $baseline) / $baseline) * 100;
        
        return [
            'percentage' => $change,
            'status' => $change < -5 ? 'improved' : ($change > 5 ? 'degraded' : 'stable'),
            'description' => $this->formatMemoryChange($change)
        ];
    }

    private function generateTextReport(): string
    {
        $report = "=== ACQC Benchmark Report ===\n\n";
        
        foreach ($this->results as $name => $result) {
            if (isset($result['error'])) {
                $report .= "❌ $name: {$result['error']}\n\n";
                continue;
            }

            $report .= "✅ $name\n";
            $report .= "  Iterations: {$result['iterations']}\n";
            $report .= "  Success Rate: {$result['success_rate']}%\n";
            $report .= "  Avg Duration: {$result['formatted']['avg_duration']}\n";
            $report .= "  Avg Memory: {$result['formatted']['avg_memory']}\n";
            $report .= "  Ops/Second: {$result['formatted']['ops_per_sec']}\n\n";
        }

        return $report;
    }

    private function generateCsvReport(): string
    {
        $csv = "Name,Iterations,Success Rate,Avg Duration,Min Duration,Max Duration,Avg Memory,Ops per Second\n";
        
        foreach ($this->results as $result) {
            if (isset($result['error'])) {
                continue;
            }

            $csv .= sprintf(
                "%s,%d,%.2f,%.6f,%.6f,%.6f,%d,%.2f\n",
                $result['name'],
                $result['iterations'],
                $result['success_rate'],
                $result['avg_duration'],
                $result['min_duration'],
                $result['max_duration'],
                $result['avg_memory'],
                $result['operations_per_second']
            );
        }

        return $csv;
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 0.001) {
            return number_format($seconds * 1000000, 0) . 'μs';
        } elseif ($seconds < 1) {
            return number_format($seconds * 1000, 2) . 'ms';
        } else {
            return number_format($seconds, 3) . 's';
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    private function formatPerformanceChange(float $change): string
    {
        if ($change < -5) {
            return sprintf("%.1f%% faster", abs($change));
        } elseif ($change > 5) {
            return sprintf("%.1f%% slower", $change);
        } else {
            return "No significant change";
        }
    }

    private function formatMemoryChange(float $change): string
    {
        if ($change < -5) {
            return sprintf("%.1f%% less memory", abs($change));
        } elseif ($change > 5) {
            return sprintf("%.1f%% more memory", $change);
        } else {
            return "No significant change";
        }
    }
} 