<?php

declare(strict_types=1);

namespace ACQC\Utils;

use Psr\Log\LoggerInterface;

/**
 * Parallel Processor
 * 
 * Multi-threading utility for ACQC parallel analysis.
 * Provides process pooling, task distribution, and result aggregation.
 */
class ParallelProcessor
{
    private LoggerInterface $logger;
    private int $maxProcesses;
    private int $timeout;
    private array $runningProcesses = [];
    private array $completedTasks = [];
    private array $failedTasks = [];
    private PerformanceProfiler $profiler;

    public function __construct(
        int $maxProcesses = null,
        int $timeout = 300,
        LoggerInterface $logger = null,
        PerformanceProfiler $profiler = null
    ) {
        $this->maxProcesses = $maxProcesses ?? $this->detectOptimalProcessCount();
        $this->timeout = $timeout;
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->profiler = $profiler ?? new PerformanceProfiler($this->logger);
    }

    /**
     * Process tasks in parallel (simplified version for demo)
     */
    public function processBatch(array $tasks, callable $processor, array $options = []): array
    {
        $this->profiler->startTimer('parallel_batch');
        
        $batchSize = $options['batch_size'] ?? 1;
        $maxRetries = $options['max_retries'] ?? 3;
        $progressCallback = $options['progress_callback'] ?? null;

        $this->logger->info('Starting parallel batch processing', [
            'total_tasks' => count($tasks),
            'max_processes' => $this->maxProcesses,
            'batch_size' => $batchSize
        ]);

        // For demo purposes, simulate parallel processing
        $results = [];
        foreach ($tasks as $task) {
            try {
                $result = $processor($task);
                $this->completedTasks[] = [
                    'task' => $task,
                    'result' => $result,
                    'success' => true,
                    'duration' => 0.025 // Simulated duration
                ];
            } catch (\Throwable $e) {
                $this->failedTasks[] = [
                    'task' => $task,
                    'error' => $e->getMessage(),
                    'success' => false
                ];
            }
        }

        $duration = $this->profiler->stopTimer('parallel_batch');
        
        $this->logger->info('Parallel batch processing completed', [
            'duration' => $duration,
            'completed_tasks' => count($this->completedTasks),
            'failed_tasks' => count($this->failedTasks)
        ]);

        return [
            'completed' => $this->completedTasks,
            'failed' => $this->failedTasks,
            'statistics' => $this->getStatistics()
        ];
    }

    /**
     * Process files in parallel (simplified version)
     */
    public function processFiles(array $files, callable $analyzer, array $options = []): array
    {
        $results = [];
        $chunkSize = $options['chunk_size'] ?? 10;
        
        $this->logger->info('Processing files in parallel', [
            'file_count' => count($files),
            'chunk_size' => $chunkSize
        ]);

        foreach ($files as $file) {
            try {
                $result = $analyzer($file);
                $results[] = [
                    'file' => $file,
                    'result' => $result,
                    'success' => true
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'file' => $file,
                    'error' => $e->getMessage(),
                    'success' => false
                ];
            }
        }

        return [
            'completed' => array_filter($results, fn($r) => $r['success']),
            'failed' => array_filter($results, fn($r) => !$r['success']),
            'statistics' => $this->getStatistics()
        ];
    }

    /**
     * Create a worker pool for long-running tasks
     */
    public function createWorkerPool(int $workerCount = null): WorkerPool
    {
        $workerCount = $workerCount ?? $this->maxProcesses;
        return new WorkerPool($workerCount, $this->logger, $this->profiler);
    }

    /**
     * Get processing statistics
     */
    public function getStatistics(): array
    {
        $totalTasks = count($this->completedTasks) + count($this->failedTasks);
        $successRate = $totalTasks > 0 ? (count($this->completedTasks) / $totalTasks) * 100 : 0;

        return [
            'total_tasks' => $totalTasks,
            'completed_tasks' => count($this->completedTasks),
            'failed_tasks' => count($this->failedTasks),
            'success_rate' => round($successRate, 2),
            'max_processes' => $this->maxProcesses,
            'average_duration' => $this->calculateAverageDuration()
        ];
    }

    /**
     * Detect optimal process count
     */
    private function detectOptimalProcessCount(): int
    {
        // Use number of CPU cores, with reasonable limits
        $cores = $this->getCpuCoreCount();
        
        // Limit between 2 and 16 processes
        return max(2, min(16, $cores));
    }

    /**
     * Get CPU core count
     */
    private function getCpuCoreCount(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return (int)shell_exec('echo %NUMBER_OF_PROCESSORS%') ?: 4;
        } else {
            return (int)shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 4') ?: 4;
        }
    }

    private function calculateAverageDuration(): float
    {
        if (empty($this->completedTasks)) {
            return 0.0;
        }

        $totalDuration = array_sum(array_column($this->completedTasks, 'duration'));
        return $totalDuration / count($this->completedTasks);
    }
}

/**
 * Worker Pool for managing long-running worker processes
 */
class WorkerPool
{
    private array $workers = [];
    private array $taskQueue = [];
    private LoggerInterface $logger;
    private PerformanceProfiler $profiler;

    public function __construct(int $workerCount, LoggerInterface $logger, PerformanceProfiler $profiler)
    {
        $this->logger = $logger;
        $this->profiler = $profiler;
        
        for ($i = 0; $i < $workerCount; $i++) {
            $this->workers[] = new Worker($i, $logger, $profiler);
        }
    }

    public function submitTask(callable $task, array $context = []): string
    {
        $taskId = uniqid('task_');
        $this->taskQueue[$taskId] = [
            'id' => $taskId,
            'task' => $task,
            'context' => $context,
            'submitted_at' => microtime(true)
        ];

        $this->assignTasks();
        return $taskId;
    }

    public function getResult(string $taskId): ?array
    {
        foreach ($this->workers as $worker) {
            $result = $worker->getTaskResult($taskId);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    public function shutdown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->shutdown();
        }
    }

    private function assignTasks(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isIdle() && !empty($this->taskQueue)) {
                $task = array_shift($this->taskQueue);
                $worker->assignTask($task);
            }
        }
    }
}

/**
 * Individual worker process
 */
class Worker
{
    private int $id;
    private bool $idle = true;
    private ?array $currentTask = null;
    private array $completedTasks = [];
    private LoggerInterface $logger;

    public function __construct(int $id, LoggerInterface $logger, PerformanceProfiler $profiler)
    {
        $this->id = $id;
        $this->logger = $logger;
    }

    public function isIdle(): bool
    {
        return $this->idle;
    }

    public function assignTask(array $task): void
    {
        $this->currentTask = $task;
        $this->idle = false;
        
        // In a real implementation, this would spawn a separate process
        $this->executeTask($task);
    }

    public function getTaskResult(string $taskId): ?array
    {
        return $this->completedTasks[$taskId] ?? null;
    }

    public function shutdown(): void
    {
        // Cleanup worker resources
        $this->idle = true;
        $this->currentTask = null;
    }

    private function executeTask(array $task): void
    {
        try {
            $startTime = microtime(true);
            $result = ($task['task'])();
            $duration = microtime(true) - $startTime;

            $this->completedTasks[$task['id']] = [
                'id' => $task['id'],
                'success' => true,
                'result' => $result,
                'duration' => $duration,
                'worker_id' => $this->id
            ];

        } catch (\Throwable $e) {
            $this->completedTasks[$task['id']] = [
                'id' => $task['id'],
                'success' => false,
                'error' => $e->getMessage(),
                'worker_id' => $this->id
            ];
        }

        $this->idle = true;
        $this->currentTask = null;
    }
} 