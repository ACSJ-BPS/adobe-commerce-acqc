<?php

namespace Example\CustomRules\Analyzers;

use M2Quality\Analyzers\AbstractAnalyzer;
use M2Quality\Core\Scanner\ScanContext;
use M2Quality\Core\Scanner\ScanResult;
use M2Quality\Core\Rules\Violation;

/**
 * Example Custom Security Analyzer
 * 
 * Demonstrates how to create a custom analyzer for company-specific security rules
 */
class CustomSecurityAnalyzer extends AbstractAnalyzer
{
    public function getName(): string
    {
        return 'custom-security';
    }

    public function getDescription(): string
    {
        return 'Custom security analyzer for company-specific security rules';
    }

    public function supports(string $fileExtension): bool
    {
        return in_array($fileExtension, ['.php', '.phtml']);
    }

    public function analyze(ScanContext $context): ScanResult
    {
        $violations = [];
        $metrics = [
            'files_analyzed' => 0,
            'security_issues_found' => 0,
            'high_risk_patterns' => 0
        ];

        foreach ($context->getFiles() as $file) {
            $fileViolations = $this->analyzeFile($file);
            $violations = array_merge($violations, $fileViolations);
            $metrics['files_analyzed']++;
            $metrics['security_issues_found'] += count($fileViolations);
        }

        return new ScanResult($violations, true, $metrics);
    }

    private function analyzeFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            // Check for hardcoded API keys
            if (preg_match('/[\'"][a-zA-Z0-9]{32,}[\'"]/', $line)) {
                $violations[] = new Violation(
                    'hardcoded-api-key',
                    'Potential hardcoded API key detected',
                    $filePath,
                    $lineNumber + 1,
                    'critical'
                );
            }

            // Check for eval() usage
            if (preg_match('/\beval\s*\(/', $line)) {
                $violations[] = new Violation(
                    'eval-usage',
                    'eval() function usage detected - security risk',
                    $filePath,
                    $lineNumber + 1,
                    'high'
                );
            }

            // Check for file inclusion vulnerabilities
            if (preg_match('/include\s*\(\s*\$/', $line) || preg_match('/require\s*\(\s*\$/', $line)) {
                $violations[] = new Violation(
                    'dynamic-file-inclusion',
                    'Dynamic file inclusion detected - potential LFI vulnerability',
                    $filePath,
                    $lineNumber + 1,
                    'high'
                );
            }

            // Check for unescaped output in templates
            if (str_ends_with($filePath, '.phtml') && preg_match('/echo\s+\$(?!block->escape)/', $line)) {
                $violations[] = new Violation(
                    'unescaped-template-output',
                    'Unescaped output in template - XSS risk',
                    $filePath,
                    $lineNumber + 1,
                    'high'
                );
            }
        }

        return $violations;
    }
} 