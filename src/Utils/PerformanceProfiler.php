<?php

declare(strict_types=1);

namespace ACQC\Utils;

use Psr\Log\LoggerInterface;

/**
 * Performance Profiler
 * 
 * Comprehensive performance monitoring utility for ACQC.
 * Tracks execution times, memory usage, and provides detailed profiling reports.
 */
class PerformanceProfiler
{
    private array $timers = [];
    private array $memorySnapshots = [];
    private array $counters = [];
    private array $checkpoints = [];
    private float $sessionStartTime;
    private int $sessionStartMemory;
    private LoggerInterface $logger;
    private bool $enabled = true;

    // Performance thresholds
    private const SLOW_OPERATION_THRESHOLD = 1.0; // seconds
    private const HIGH_MEMORY_THRESHOLD = 100 * 1024 * 1024; // 100MB
    private const CRITICAL_MEMORY_THRESHOLD = 256 * 1024 * 1024; // 256MB

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->sessionStartTime = microtime(true);
        $this->sessionStartMemory = memory_get_usage(true);
    }

    /**
     * Start timing an operation
     */
    public function startTimer(string $name, array $context = []): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $this->timers[$name] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'context' => $context,
            'running' => true
        ];

        $this->logger->debug("Timer started: $name", $context);
        
        return $this;
    }

    /**
     * Stop timing an operation and return duration
     */
    public function stopTimer(string $name): float
    {
        if (!$this->enabled || !isset($this->timers[$name])) {
            return 0.0;
        }

        $timer = &$this->timers[$name];
        
        if (!$timer['running']) {
            return $timer['duration'] ?? 0.0;
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $duration = $endTime - $timer['start_time'];
        $memoryUsed = $endMemory - $timer['start_memory'];
        
        $timer['end_time'] = $endTime;
        $timer['end_memory'] = $endMemory;
        $timer['duration'] = $duration;
        $timer['memory_used'] = $memoryUsed;
        $timer['running'] = false;

        // Log slow operations
        if ($duration > self::SLOW_OPERATION_THRESHOLD) {
            $this->logger->warning("Slow operation detected: $name", [
                'duration' => $duration,
                'memory_used' => $memoryUsed,
                'context' => $timer['context']
            ]);
        }

        $this->logger->debug("Timer stopped: $name", [
            'duration' => $duration,
            'memory_used' => $memoryUsed
        ]);

        return $duration;
    }

    /**
     * Get timer duration (stops timer if running)
     */
    public function getTimerDuration(string $name): float
    {
        if (!isset($this->timers[$name])) {
            return 0.0;
        }

        if ($this->timers[$name]['running']) {
            $this->stopTimer($name);
        }

        return $this->timers[$name]['duration'] ?? 0.0;
    }

    /**
     * Take a memory snapshot
     */
    public function takeMemorySnapshot(string $label): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        
        $this->memorySnapshots[$label] = [
            'timestamp' => microtime(true),
            'current' => $current,
            'peak' => $peak,
            'formatted_current' => $this->formatBytes($current),
            'formatted_peak' => $this->formatBytes($peak)
        ];

        // Log high memory usage
        if ($current > self::HIGH_MEMORY_THRESHOLD) {
            $level = $current > self::CRITICAL_MEMORY_THRESHOLD ? 'critical' : 'warning';
            $this->logger->log($level, "High memory usage at $label", [
                'current' => $this->formatBytes($current),
                'peak' => $this->formatBytes($peak)
            ]);
        }

        return $this;
    }

    /**
     * Increment a performance counter
     */
    public function incrementCounter(string $name, int $amount = 1): self
    {
        if (!$this->enabled) {
            return $this;
        }

        if (!isset($this->counters[$name])) {
            $this->counters[$name] = 0;
        }
        
        $this->counters[$name] += $amount;
        
        return $this;
    }

    /**
     * Set a performance counter value
     */
    public function setCounter(string $name, int $value): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $this->counters[$name] = $value;
        
        return $this;
    }

    /**
     * Get counter value
     */
    public function getCounter(string $name): int
    {
        return $this->counters[$name] ?? 0;
    }

    /**
     * Add a checkpoint for detailed analysis
     */
    public function addCheckpoint(string $name, array $data = []): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $this->checkpoints[$name] = [
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'data' => $data
        ];

        return $this;
    }

    /**
     * Get comprehensive performance summary
     */
    public function getSummary(): array
    {
        $currentTime = microtime(true);
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        $totalDuration = $currentTime - $this->sessionStartTime;
        $totalMemoryIncrease = $currentMemory - $this->sessionStartMemory;

        // Calculate timer statistics
        $timerStats = $this->calculateTimerStatistics();
        
        // Calculate memory efficiency
        $memoryEfficiency = $this->calculateMemoryEfficiency();

        return [
            'session' => [
                'total_duration' => $totalDuration,
                'formatted_duration' => $this->formatDuration($totalDuration),
                'memory_start' => $this->formatBytes($this->sessionStartMemory),
                'memory_current' => $this->formatBytes($currentMemory),
                'memory_peak' => $this->formatBytes($peakMemory),
                'memory_increase' => $this->formatBytes($totalMemoryIncrease),
                'memory_efficiency' => $memoryEfficiency
            ],
            'timers' => $timerStats,
            'counters' => $this->counters,
            'memory_snapshots' => $this->memorySnapshots,
            'checkpoints' => $this->checkpoints,
            'performance_grade' => $this->calculatePerformanceGrade($totalDuration, $peakMemory),
            'recommendations' => $this->generateRecommendations()
        ];
    }

    /**
     * Generate detailed performance report
     */
    public function generateReport(string $format = 'text'): string
    {
        $summary = $this->getSummary();

        switch ($format) {
            case 'json':
                return json_encode($summary, JSON_PRETTY_PRINT);
            case 'html':
                return $this->generateHtmlReport($summary);
            default:
                return $this->generateTextReport($summary);
        }
    }

    /**
     * Profile a callable and return results
     */
    public function profile(callable $callback, string $name = 'anonymous', array $context = []): array
    {
        $this->startTimer($name, $context);
        $this->takeMemorySnapshot($name . '_start');

        try {
            $result = $callback();
            $success = true;
            $error = null;
        } catch (\Throwable $e) {
            $result = null;
            $success = false;
            $error = $e->getMessage();
            
            $this->logger->error("Profiled operation failed: $name", [
                'error' => $error,
                'context' => $context
            ]);
        }

        $this->takeMemorySnapshot($name . '_end');
        $duration = $this->stopTimer($name);

        return [
            'name' => $name,
            'success' => $success,
            'duration' => $duration,
            'formatted_duration' => $this->formatDuration($duration),
            'result' => $result,
            'error' => $error,
            'context' => $context
        ];
    }

    /**
     * Enable or disable profiling
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Check if profiling is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Reset all profiling data
     */
    public function reset(): self
    {
        $this->timers = [];
        $this->memorySnapshots = [];
        $this->counters = [];
        $this->checkpoints = [];
        $this->sessionStartTime = microtime(true);
        $this->sessionStartMemory = memory_get_usage(true);

        return $this;
    }

    /**
     * Export profiling data for external analysis
     */
    public function exportData(): array
    {
        return [
            'session_start_time' => $this->sessionStartTime,
            'session_start_memory' => $this->sessionStartMemory,
            'timers' => $this->timers,
            'memory_snapshots' => $this->memorySnapshots,
            'counters' => $this->counters,
            'checkpoints' => $this->checkpoints,
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
    }

    /**
     * Calculate timer statistics
     */
    private function calculateTimerStatistics(): array
    {
        $stats = [
            'total_operations' => count($this->timers),
            'completed_operations' => 0,
            'total_time' => 0.0,
            'average_time' => 0.0,
            'slowest_operation' => null,
            'fastest_operation' => null,
            'operations' => []
        ];

        $durations = [];

        foreach ($this->timers as $name => $timer) {
            if (!$timer['running']) {
                $stats['completed_operations']++;
                $duration = $timer['duration'];
                $durations[] = $duration;
                $stats['total_time'] += $duration;

                $operation = [
                    'name' => $name,
                    'duration' => $duration,
                    'formatted_duration' => $this->formatDuration($duration),
                    'memory_used' => $timer['memory_used'] ?? 0,
                    'formatted_memory' => $this->formatBytes($timer['memory_used'] ?? 0),
                    'context' => $timer['context'] ?? []
                ];

                $stats['operations'][] = $operation;

                // Track fastest and slowest
                if ($stats['slowest_operation'] === null || $duration > $stats['slowest_operation']['duration']) {
                    $stats['slowest_operation'] = $operation;
                }

                if ($stats['fastest_operation'] === null || $duration < $stats['fastest_operation']['duration']) {
                    $stats['fastest_operation'] = $operation;
                }
            }
        }

        if ($stats['completed_operations'] > 0) {
            $stats['average_time'] = $stats['total_time'] / $stats['completed_operations'];
        }

        // Sort operations by duration (slowest first)
        usort($stats['operations'], fn($a, $b) => $b['duration'] <=> $a['duration']);

        return $stats;
    }

    /**
     * Calculate memory efficiency score
     */
    private function calculateMemoryEfficiency(): string
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryIncrease = $currentMemory - $this->sessionStartMemory;

        if ($memoryIncrease < 10 * 1024 * 1024) { // < 10MB
            return 'Excellent';
        } elseif ($memoryIncrease < 50 * 1024 * 1024) { // < 50MB
            return 'Good';
        } elseif ($memoryIncrease < 100 * 1024 * 1024) { // < 100MB
            return 'Fair';
        } else {
            return 'Poor';
        }
    }

    /**
     * Calculate overall performance grade
     */
    private function calculatePerformanceGrade(float $totalDuration, int $peakMemory): string
    {
        $timeScore = 0;
        $memoryScore = 0;

        // Time scoring
        if ($totalDuration < 1.0) {
            $timeScore = 100;
        } elseif ($totalDuration < 5.0) {
            $timeScore = 80;
        } elseif ($totalDuration < 10.0) {
            $timeScore = 60;
        } elseif ($totalDuration < 30.0) {
            $timeScore = 40;
        } else {
            $timeScore = 20;
        }

        // Memory scoring
        if ($peakMemory < 50 * 1024 * 1024) { // < 50MB
            $memoryScore = 100;
        } elseif ($peakMemory < 100 * 1024 * 1024) { // < 100MB
            $memoryScore = 80;
        } elseif ($peakMemory < 200 * 1024 * 1024) { // < 200MB
            $memoryScore = 60;
        } elseif ($peakMemory < 400 * 1024 * 1024) { // < 400MB
            $memoryScore = 40;
        } else {
            $memoryScore = 20;
        }

        $overallScore = ($timeScore + $memoryScore) / 2;

        if ($overallScore >= 90) return 'A+';
        if ($overallScore >= 80) return 'A';
        if ($overallScore >= 70) return 'B';
        if ($overallScore >= 60) return 'C';
        if ($overallScore >= 50) return 'D';
        return 'F';
    }

    /**
     * Generate performance recommendations
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];
        $summary = $this->getSummary();

        // Check for slow operations
        foreach ($this->timers as $name => $timer) {
            if (!$timer['running'] && $timer['duration'] > self::SLOW_OPERATION_THRESHOLD) {
                $recommendations[] = "Consider optimizing '$name' operation (took {$this->formatDuration($timer['duration'])})";
            }
        }

        // Check memory usage
        $peakMemory = memory_get_peak_usage(true);
        if ($peakMemory > self::HIGH_MEMORY_THRESHOLD) {
            $recommendations[] = "High memory usage detected ({$this->formatBytes($peakMemory)}). Consider implementing memory optimization.";
        }

        // Check for too many operations
        if (count($this->timers) > 100) {
            $recommendations[] = "Large number of timed operations ({count($this->timers)}). Consider batching or reducing granularity.";
        }

        // General recommendations
        if (empty($recommendations)) {
            $recommendations[] = "Performance looks good! Consider implementing caching for frequently accessed data.";
        }

        return $recommendations;
    }

    /**
     * Generate text format report
     */
    private function generateTextReport(array $summary): string
    {
        $report = "=== ACQC Performance Report ===\n\n";
        
        // Session summary
        $session = $summary['session'];
        $report .= "Session Summary:\n";
        $report .= "  Duration: {$session['formatted_duration']}\n";
        $report .= "  Memory Start: {$session['memory_start']}\n";
        $report .= "  Memory Peak: {$session['memory_peak']}\n";
        $report .= "  Memory Increase: {$session['memory_increase']}\n";
        $report .= "  Memory Efficiency: {$session['memory_efficiency']}\n";
        $report .= "  Performance Grade: {$summary['performance_grade']}\n\n";

        // Timer statistics
        if (!empty($summary['timers']['operations'])) {
            $timers = $summary['timers'];
            $report .= "Timer Statistics:\n";
            $report .= "  Total Operations: {$timers['total_operations']}\n";
            $report .= "  Completed: {$timers['completed_operations']}\n";
            $report .= "  Total Time: {$this->formatDuration($timers['total_time'])}\n";
            $report .= "  Average Time: {$this->formatDuration($timers['average_time'])}\n\n";

            $report .= "Top 5 Slowest Operations:\n";
            foreach (array_slice($timers['operations'], 0, 5) as $op) {
                $report .= "  {$op['name']}: {$op['formatted_duration']} ({$op['formatted_memory']})\n";
            }
            $report .= "\n";
        }

        // Counters
        if (!empty($summary['counters'])) {
            $report .= "Counters:\n";
            foreach ($summary['counters'] as $name => $value) {
                $report .= "  $name: $value\n";
            }
            $report .= "\n";
        }

        // Recommendations
        if (!empty($summary['recommendations'])) {
            $report .= "Recommendations:\n";
            foreach ($summary['recommendations'] as $recommendation) {
                $report .= "  • $recommendation\n";
            }
        }

        return $report;
    }

    /**
     * Generate HTML format report
     */
    private function generateHtmlReport(array $summary): string
    {
        $session = $summary['session'];
        $timers = $summary['timers'];

        $html = '<!DOCTYPE html>
<html>
<head>
    <title>ACQC Performance Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #f8f9fa; padding: 20px; border-radius: 5px; }
        .section { margin: 20px 0; }
        .metric { display: inline-block; margin: 10px; padding: 10px; background: #e9ecef; border-radius: 3px; }
        .grade { font-size: 24px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        .slow { background-color: #ffe6e6; }
        .fast { background-color: #e6ffe6; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ACQC Performance Report</h1>
        <div class="grade">Performance Grade: ' . $summary['performance_grade'] . '</div>
    </div>

    <div class="section">
        <h2>Session Summary</h2>
        <div class="metric">Duration: ' . $session['formatted_duration'] . '</div>
        <div class="metric">Memory Peak: ' . $session['memory_peak'] . '</div>
        <div class="metric">Memory Increase: ' . $session['memory_increase'] . '</div>
        <div class="metric">Efficiency: ' . $session['memory_efficiency'] . '</div>
    </div>';

        if (!empty($timers['operations'])) {
            $html .= '
    <div class="section">
        <h2>Operations</h2>
        <table>
            <tr>
                <th>Operation</th>
                <th>Duration</th>
                <th>Memory Used</th>
                <th>Status</th>
            </tr>';

            foreach ($timers['operations'] as $op) {
                $class = $op['duration'] > self::SLOW_OPERATION_THRESHOLD ? 'slow' : 'fast';
                $html .= "
            <tr class=\"$class\">
                <td>{$op['name']}</td>
                <td>{$op['formatted_duration']}</td>
                <td>{$op['formatted_memory']}</td>
                <td>" . ($op['duration'] > self::SLOW_OPERATION_THRESHOLD ? 'Slow' : 'Normal') . "</td>
            </tr>";
            }

            $html .= '
        </table>
    </div>';
        }

        if (!empty($summary['recommendations'])) {
            $html .= '
    <div class="section">
        <h2>Recommendations</h2>
        <ul>';
            foreach ($summary['recommendations'] as $rec) {
                $html .= "<li>$rec</li>";
            }
            $html .= '
        </ul>
    </div>';
        }

        $html .= '
</body>
</html>';

        return $html;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Format duration to human readable format
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 0.001) {
            return number_format($seconds * 1000000, 0) . 'μs';
        } elseif ($seconds < 1) {
            return number_format($seconds * 1000, 2) . 'ms';
        } elseif ($seconds < 60) {
            return number_format($seconds, 3) . 's';
        } else {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $minutes . 'm ' . number_format($remainingSeconds, 1) . 's';
        }
    }
} 