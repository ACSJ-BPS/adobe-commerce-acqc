<?php

declare(strict_types=1);

namespace ACQC\Core\Scanner;

class ScanResult
{
    private string $filePath;
    private string $fileType;
    private int $fileSize;
    private \DateTime $scannedAt;
    private float $scanDuration;
    private array $issues = [];
    private array $metrics = [];
    private array $metadata = [];
    private bool $hasErrors = false;
    private array $errors = [];
    private ?string $checksum = null;

    public function __construct(
        string $filePath,
        string $fileType = 'unknown',
        int $fileSize = 0
    ) {
        $this->filePath = $filePath;
        $this->fileType = $fileType;
        $this->fileSize = $fileSize;
        $this->scannedAt = new \DateTime();
        $this->scanDuration = 0.0;
    }

    /**
     * Get the file path being scanned
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get the detected file type
     */
    public function getFileType(): string
    {
        return $this->fileType;
    }

    /**
     * Set the detected file type
     */
    public function setFileType(string $fileType): void
    {
        $this->fileType = $fileType;
    }

    /**
     * Get the file size in bytes
     */
    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * Set the file size
     */
    public function setFileSize(int $fileSize): void
    {
        $this->fileSize = $fileSize;
    }

    /**
     * Get when the file was scanned
     */
    public function getScannedAt(): \DateTime
    {
        return $this->scannedAt;
    }

    /**
     * Get the scan duration in seconds
     */
    public function getScanDuration(): float
    {
        return $this->scanDuration;
    }

    /**
     * Set the scan duration
     */
    public function setScanDuration(float $duration): void
    {
        $this->scanDuration = $duration;
    }

    /**
     * Add an issue found during scanning
     */
    public function addIssue(array $issue): void
    {
        // Ensure required fields are present
        $issue = array_merge([
            'type' => 'unknown',
            'severity' => 'warning',
            'message' => '',
            'line' => 0,
            'column' => 0,
            'rule' => '',
            'category' => 'general',
            'fixable' => false,
        ], $issue);

        $this->issues[] = $issue;
    }

    /**
     * Get all issues found during scanning
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Get issues filtered by severity
     */
    public function getIssuesBySeverity(string $severity): array
    {
        return array_filter($this->issues, function($issue) use ($severity) {
            return ($issue['severity'] ?? 'warning') === $severity;
        });
    }

    /**
     * Get issues filtered by category
     */
    public function getIssuesByCategory(string $category): array
    {
        return array_filter($this->issues, function($issue) use ($category) {
            return ($issue['category'] ?? 'general') === $category;
        });
    }

    /**
     * Get total issue count
     */
    public function getIssueCount(): int
    {
        return count($this->issues);
    }

    /**
     * Get issue count by severity
     */
    public function getIssueCountBySeverity(): array
    {
        $counts = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
        
        foreach ($this->issues as $issue) {
            $severity = $issue['severity'] ?? 'warning';
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    /**
     * Check if there are any critical or error issues
     */
    public function hasCriticalIssues(): bool
    {
        foreach ($this->issues as $issue) {
            $severity = $issue['severity'] ?? 'warning';
            if (in_array($severity, ['critical', 'error'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Set a metric value
     */
    public function setMetric(string $name, $value): void
    {
        $this->metrics[$name] = $value;
    }

    /**
     * Get a metric value
     */
    public function getMetric(string $name, $default = null)
    {
        return $this->metrics[$name] ?? $default;
    }

    /**
     * Get all metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Set multiple metrics at once
     */
    public function setMetrics(array $metrics): void
    {
        $this->metrics = array_merge($this->metrics, $metrics);
    }

    /**
     * Set metadata
     */
    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Get metadata
     */
    public function getMetadata(string $key = null)
    {
        if ($key === null) {
            return $this->metadata;
        }
        return $this->metadata[$key] ?? null;
    }

    /**
     * Add an error that occurred during scanning
     */
    public function addError(string $error, string $context = ''): void
    {
        $this->hasErrors = true;
        $this->errors[] = [
            'message' => $error,
            'context' => $context,
            'timestamp' => new \DateTime(),
        ];
    }

    /**
     * Check if there were errors during scanning
     */
    public function hasErrors(): bool
    {
        return $this->hasErrors;
    }

    /**
     * Get all errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Set file checksum for change detection
     */
    public function setChecksum(string $checksum): void
    {
        $this->checksum = $checksum;
    }

    /**
     * Get file checksum
     */
    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    /**
     * Check if this result matches another (same file, same checksum)
     */
    public function matches(ScanResult $other): bool
    {
        return $this->filePath === $other->filePath && 
               $this->checksum === $other->checksum;
    }

    /**
     * Compare with baseline result to find new/resolved issues
     */
    public function compareWithBaseline(ScanResult $baseline): array
    {
        $newIssues = [];
        $resolvedIssues = [];
        $unchangedIssues = [];

        // Create issue fingerprints for comparison
        $currentFingerprints = [];
        foreach ($this->issues as $issue) {
            $fingerprint = $this->createIssueFingerprint($issue);
            $currentFingerprints[$fingerprint] = $issue;
        }

        $baselineFingerprints = [];
        foreach ($baseline->issues as $issue) {
            $fingerprint = $this->createIssueFingerprint($issue);
            $baselineFingerprints[$fingerprint] = $issue;
        }

        // Find new issues
        foreach ($currentFingerprints as $fingerprint => $issue) {
            if (!isset($baselineFingerprints[$fingerprint])) {
                $newIssues[] = $issue;
            } else {
                $unchangedIssues[] = $issue;
            }
        }

        // Find resolved issues
        foreach ($baselineFingerprints as $fingerprint => $issue) {
            if (!isset($currentFingerprints[$fingerprint])) {
                $resolvedIssues[] = $issue;
            }
        }

        return [
            'new' => $newIssues,
            'resolved' => $resolvedIssues,
            'unchanged' => $unchangedIssues,
        ];
    }

    /**
     * Create a unique fingerprint for an issue
     */
    private function createIssueFingerprint(array $issue): string
    {
        return md5(
            ($issue['rule'] ?? '') . '|' .
            ($issue['line'] ?? 0) . '|' .
            ($issue['column'] ?? 0) . '|' .
            ($issue['message'] ?? '')
        );
    }

    /**
     * Get a summary of the scan result
     */
    public function getSummary(): array
    {
        $severityCounts = $this->getIssueCountBySeverity();
        
        return [
            'file_path' => $this->filePath,
            'file_type' => $this->fileType,
            'file_size' => $this->fileSize,
            'scanned_at' => $this->scannedAt->format('Y-m-d H:i:s'),
            'scan_duration' => $this->scanDuration,
            'total_issues' => $this->getIssueCount(),
            'critical_issues' => $severityCounts['critical'],
            'error_issues' => $severityCounts['error'],
            'warning_issues' => $severityCounts['warning'],
            'info_issues' => $severityCounts['info'],
            'has_critical_issues' => $this->hasCriticalIssues(),
            'has_errors' => $this->hasErrors,
            'error_count' => count($this->errors),
            'checksum' => $this->checksum,
            'metrics' => $this->metrics,
        ];
    }

    /**
     * Export result to array format
     */
    public function toArray(): array
    {
        return [
            'file_path' => $this->filePath,
            'file_type' => $this->fileType,
            'file_size' => $this->fileSize,
            'scanned_at' => $this->scannedAt->format(\DateTime::ISO8601),
            'scan_duration' => $this->scanDuration,
            'issues' => $this->issues,
            'metrics' => $this->metrics,
            'metadata' => $this->metadata,
            'has_errors' => $this->hasErrors,
            'errors' => $this->errors,
            'checksum' => $this->checksum,
        ];
    }

    /**
     * Create ScanResult from array data
     */
    public static function fromArray(array $data): self
    {
        $result = new self(
            $data['file_path'] ?? '',
            $data['file_type'] ?? 'unknown',
            $data['file_size'] ?? 0
        );

        if (isset($data['scanned_at'])) {
            $result->scannedAt = new \DateTime($data['scanned_at']);
        }

        $result->scanDuration = $data['scan_duration'] ?? 0.0;
        $result->issues = $data['issues'] ?? [];
        $result->metrics = $data['metrics'] ?? [];
        $result->metadata = $data['metadata'] ?? [];
        $result->hasErrors = $data['has_errors'] ?? false;
        $result->errors = $data['errors'] ?? [];
        $result->checksum = $data['checksum'] ?? null;

        return $result;
    }
} 