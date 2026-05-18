<?php

declare(strict_types=1);

namespace ACQC\Analyzers;

class SecurityAnalyzer implements AnalyzerInterface
{
    public function analyze(array $files, array $config = []): array
    {
        $issues = [];
        $metrics = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $fileIssues = $this->analyzeFileForSecurity($file, $content);
            $issues = array_merge($issues, $fileIssues);
        }

        $metrics['security_score'] = $this->calculateSecurityScore($issues);
        $metrics['vulnerable_patterns_found'] = count($issues);

        return [
            'issues' => $issues,
            'metrics' => $metrics,
            'recommendations' => $this->generateSecurityRecommendations($issues),
        ];
    }

    public function getName(): string
    {
        return 'Security Analyzer';
    }

    public function getDescription(): string
    {
        return 'Identifies potential security vulnerabilities and unsafe coding practices';
    }

    public function getSupportedSeverities(): array
    {
        return ['critical', 'error', 'warning', 'info'];
    }

    private function analyzeFileForSecurity(string $file, string $content): array
    {
        $issues = [];

        // Check for SQL injection vulnerabilities
        $issues = array_merge($issues, $this->checkSqlInjection($file, $content));

        // Check for XSS vulnerabilities
        $issues = array_merge($issues, $this->checkXssVulnerabilities($file, $content));

        // Check for hardcoded secrets
        $issues = array_merge($issues, $this->checkHardcodedSecrets($file, $content));

        // Check for unsafe file operations
        $issues = array_merge($issues, $this->checkUnsafeFileOperations($file, $content));

        return $issues;
    }

    private function checkSqlInjection(string $file, string $content): array
    {
        $issues = [];
        $vulnerablePatterns = [
            '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\.\s*["\']SELECT.*?\$/' => 'Potential SQL injection: string concatenation in SQL query',
            '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\.\s*["\']UPDATE.*?\$/' => 'Potential SQL injection: string concatenation in UPDATE query',
            '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\.\s*["\']DELETE.*?\$/' => 'Potential SQL injection: string concatenation in DELETE query',
        ];

        foreach ($vulnerablePatterns as $pattern => $message) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $issues[] = [
                        'file' => $file,
                        'line' => $line,
                        'severity' => 'critical',
                        'type' => 'sql_injection',
                        'message' => $message,
                    ];
                }
            }
        }

        return $issues;
    }

    private function checkXssVulnerabilities(string $file, string $content): array
    {
        $issues = [];
        
        // Check for direct output without escaping
        if (preg_match_all('/echo\s+\$[a-zA-Z_][a-zA-Z0-9_]*/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $issues[] = [
                    'file' => $file,
                    'line' => $line,
                    'severity' => 'error',
                    'type' => 'xss_vulnerability',
                    'message' => 'Potential XSS: variable output without escaping',
                ];
            }
        }

        return $issues;
    }

    private function checkHardcodedSecrets(string $file, string $content): array
    {
        $issues = [];
        $secretPatterns = [
            '/password\s*=\s*["\'][^"\']{3,}["\']/' => 'Hardcoded password detected',
            '/api[_-]?key\s*=\s*["\'][^"\']{10,}["\']/' => 'Hardcoded API key detected',
            '/secret\s*=\s*["\'][^"\']{10,}["\']/' => 'Hardcoded secret detected',
        ];

        foreach ($secretPatterns as $pattern => $message) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $issues[] = [
                        'file' => $file,
                        'line' => $line,
                        'severity' => 'critical',
                        'type' => 'hardcoded_secret',
                        'message' => $message,
                    ];
                }
            }
        }

        return $issues;
    }

    private function checkUnsafeFileOperations(string $file, string $content): array
    {
        $issues = [];
        $unsafeFunctions = [
            'file_get_contents' => 'Use file_get_contents with proper validation',
            'file_put_contents' => 'Use file_put_contents with proper validation',
            'fopen' => 'Ensure proper file path validation when using fopen',
            'include' => 'Dynamic include statements can be dangerous',
            'require' => 'Dynamic require statements can be dangerous',
        ];

        foreach ($unsafeFunctions as $function => $message) {
            if (preg_match_all('/' . preg_quote($function) . '\s*\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $issues[] = [
                        'file' => $file,
                        'line' => $line,
                        'severity' => 'warning',
                        'type' => 'unsafe_file_operation',
                        'message' => $message,
                    ];
                }
            }
        }

        return $issues;
    }

    private function calculateSecurityScore(array $issues): int
    {
        $criticalCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'critical'));
        $errorCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'error'));
        $warningCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'warning'));
        
        $baseScore = 100;
        $penalty = ($criticalCount * 25) + ($errorCount * 10) + ($warningCount * 3);
        
        return max(0, $baseScore - $penalty);
    }

    private function generateSecurityRecommendations(array $issues): array
    {
        $recommendations = [];
        $issueTypes = array_count_values(array_column($issues, 'type'));

        if (($issueTypes['sql_injection'] ?? 0) > 0) {
            $recommendations[] = 'Use prepared statements and parameterized queries to prevent SQL injection';
        }

        if (($issueTypes['xss_vulnerability'] ?? 0) > 0) {
            $recommendations[] = 'Always escape output and validate input to prevent XSS attacks';
        }

        if (($issueTypes['hardcoded_secret'] ?? 0) > 0) {
            $recommendations[] = 'Move sensitive data to environment variables or secure configuration files';
        }

        if (($issueTypes['unsafe_file_operation'] ?? 0) > 0) {
            $recommendations[] = 'Implement proper input validation and file path sanitization';
        }

        return $recommendations;
    }
} 