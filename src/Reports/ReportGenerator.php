<?php

declare(strict_types=1);

namespace ACQC\Reports;

class ReportGenerator
{
    private const SUPPORTED_FORMATS = ['html', 'json', 'markdown'];

    /** @var array<string, object> Optional injected format generators (for testing/DI) */
    private array $generators;

    /** Cached results from the last loadResults() call to avoid re-reading the same file */
    private ?array $cachedResults = null;
    private ?string $cachedFile = null;

    public function __construct(array $generators = [])
    {
        $this->generators = $generators;
    }

    /**
     * Generate a report. Accepts either:
     *   - generate(array $violations, string $format)  — violations array + format string
     *   - generate(string $inputFile, array $options)  — legacy file-based API
     *
     * @param array|string $input
     * @param array|string $formatOrOptions
     */
    public function generate($input, $formatOrOptions = []): string
    {
        // Violations-array API: generate(array $violations, string $format)
        if (is_array($input) && is_string($formatOrOptions)) {
            return $this->generateFromViolations($input, $formatOrOptions);
        }

        // Legacy file-based API: generate(string $inputFile, array $options)
        if (is_string($input)) {
            $options = is_array($formatOrOptions) ? $formatOrOptions : [];
            $results = $this->loadResults($input);
            $format = $options['format'] ?? 'html';
            $outputFile = $options['output'] ?? $this->generateOutputPath($input, $format);
            $content = $this->renderFormat($results, $format, $options);
            file_put_contents($outputFile, $content);
            return $outputFile;
        }

        throw new \InvalidArgumentException('generate() expects (array $violations, string $format) or (string $inputFile, array $options)');
    }

    public function getSupportedFormats(): array
    {
        return array_unique(array_merge(self::SUPPORTED_FORMATS, array_keys($this->generators)));
    }

    private function generateFromViolations(array $violations, string $format): string
    {
        // Delegate to injected generator if provided (used in tests for mocking)
        if (isset($this->generators[$format])) {
            $result = $this->generators[$format]->generate($violations);
            // Handle both string returns and ReportResult objects
            if (is_object($result) && method_exists($result, 'getContent')) {
                return $result->getContent();
            }
            return (string) $result;
        }

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new \InvalidArgumentException("Unsupported report format: {$format}");
        }

        $results = ['violations' => $violations, 'issues' => $violations];
        return $this->renderFormat($results, $format, []);
    }

    private function renderFormat(array $results, string $format, array $options): string
    {
        switch ($format) {
            case 'html':
                return $this->generateHtmlReport($results, $options);
            case 'json':
                return $this->generateJsonReport($results, $options);
            case 'markdown':
                return $this->generateMarkdownReport($results, $options);
            default:
                throw new \InvalidArgumentException("Unsupported report format: {$format}");
        }
    }

    private function loadResults(string $inputFile): array
    {
        // Return cached results when the same file is requested more than once
        if ($this->cachedFile === $inputFile && $this->cachedResults !== null) {
            return $this->cachedResults;
        }

        if (!file_exists($inputFile)) {
            throw new \InvalidArgumentException("Input file not found: {$inputFile}");
        }

        $content = file_get_contents($inputFile);
        $results = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON in input file: " . json_last_error_msg());
        }

        $this->cachedFile = $inputFile;
        $this->cachedResults = $results;

        return $results;
    }

    private function generateOutputPath(string $inputFile, string $format): string
    {
        $pathInfo = pathinfo($inputFile);
        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_report.' . $format;
    }

    private function generateHtmlReport(array $results, array $options): string
    {
        $html = '<!DOCTYPE html><html><head><title>ACQC Analysis Report</title></head><body>';
        $html .= '<h1>Adobe Commerce Quality Control Report</h1>';
        $html .= '<p><strong>Project:</strong> ' . htmlspecialchars($results['project_path'] ?? 'Unknown') . '</p>';
        $html .= '<p><strong>Files Analyzed:</strong> ' . ($results['files_analyzed'] ?? 0) . '</p>';
        $html .= '<p><strong>Score:</strong> ' . ($results['score'] ?? 0) . '/100</p>';
        $html .= '<h2>Issues</h2>';

        $issues = $results['issues'] ?? $results['violations'] ?? [];
        if (!empty($issues)) {
            $html .= '<table border="1"><tr><th>File</th><th>Line</th><th>Severity</th><th>Message</th></tr>';
            foreach ($issues as $issue) {
                $html .= sprintf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    htmlspecialchars(is_object($issue) ? $issue->getFilePath() : ($issue['file'] ?? '')),
                    is_object($issue) ? $issue->getLine() : ($issue['line'] ?? ''),
                    htmlspecialchars(is_object($issue) ? $issue->getSeverity() : ($issue['severity'] ?? '')),
                    htmlspecialchars(is_object($issue) ? $issue->getMessage() : ($issue['message'] ?? ''))
                );
            }
            $html .= '</table>';
        } else {
            $html .= '<p>No issues found!</p>';
        }

        $html .= '</body></html>';
        return $html;
    }

    private function generateJsonReport(array $results, array $options): string
    {
        return json_encode($results, JSON_PRETTY_PRINT);
    }

    private function generateMarkdownReport(array $results, array $options): string
    {
        $md  = "# ACQC Report\n\n";
        $md .= "**Project:** " . ($results['project_path'] ?? 'Unknown') . "\n";
        $md .= "**Files Analyzed:** " . ($results['files_analyzed'] ?? 0) . "\n";
        $md .= "**Score:** " . ($results['score'] ?? 0) . "/100\n\n## Issues\n\n";

        $issues = $results['issues'] ?? $results['violations'] ?? [];
        if (!empty($issues)) {
            foreach ($issues as $issue) {
                $md .= sprintf(
                    "- **%s** (Line %s): %s\n",
                    is_object($issue) ? $issue->getSeverity() : ($issue['severity'] ?? 'Unknown'),
                    is_object($issue) ? $issue->getLine() : ($issue['line'] ?? 'N/A'),
                    is_object($issue) ? $issue->getMessage() : ($issue['message'] ?? 'No message')
                );
            }
        } else {
            $md .= "No issues found!\n";
        }

        return $md;
    }
}
