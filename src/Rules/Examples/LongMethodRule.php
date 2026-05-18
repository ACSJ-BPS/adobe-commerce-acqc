<?php

declare(strict_types=1);

namespace ACQC\Rules\Examples;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;

/**
 * Example rule that detects methods that are too long
 */
class LongMethodRule extends Rule
{
    protected string $id = 'long-method';
    protected string $name = 'Long Method';
    protected string $description = 'Detects methods that exceed the maximum line count';
    protected string $severity = Violation::SEVERITY_WARNING;
    protected string $type = Violation::TYPE_MAINTAINABILITY;
    protected ?string $category = 'code-quality';
    protected int $priority = 50;
    protected array $tags = ['complexity', 'maintainability'];
    protected array $fileExtensions = ['php'];
    protected ?string $documentationUrl = 'https://example.com/rules/long-method';
    
    protected array $configuration = [
        'max_lines' => 50,
        'exclude_comments' => true,
        'exclude_empty_lines' => true,
    ];

    public function execute(string $filePath, string $content): array
    {
        $violations = [];
        $maxLines = $this->getConfigValue('max_lines', 50);
        $excludeComments = $this->getConfigValue('exclude_comments', true);
        $excludeEmptyLines = $this->getConfigValue('exclude_empty_lines', true);

        // Find all method definitions
        $pattern = '/(?:public|private|protected|static)?\s*function\s+(\w+)\s*\([^{]*\)\s*{/';
        $methodMatches = $this->findPattern($content, $pattern);

        foreach ($methodMatches as $match) {
            $methodName = $this->extractMethodName($match['match']);
            $methodStart = $match['line'];
            $methodEnd = $this->findMethodEnd($content, $match['offset']);
            
            if ($methodEnd === null) {
                continue;
            }

            $methodContent = $this->extractMethodContent($content, $match['offset'], $methodEnd);
            $lineCount = $this->countMethodLines($methodContent, $excludeComments, $excludeEmptyLines);

            if ($lineCount > $maxLines) {
                $violation = $this->createViolation(
                    "Method '{$methodName}' is too long ({$lineCount} lines, max: {$maxLines})",
                    $filePath,
                    $methodStart,
                    0,
                    $this->extractSourceLine($content, $methodStart)
                );

                $violation->addContext('method_name', $methodName);
                $violation->addContext('actual_lines', $lineCount);
                $violation->addContext('max_lines', $maxLines);
                $violation->setSuggestion("Consider breaking this method into smaller, more focused methods");

                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Extract method name from match
     */
    private function extractMethodName(string $match): string
    {
        if (preg_match('/function\s+(\w+)/', $match, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }

    /**
     * Find the end of a method
     */
    private function findMethodEnd(string $content, int $startOffset): ?int
    {
        $openBraces = 0;
        $inMethod = false;
        $length = strlen($content);

        for ($i = $startOffset; $i < $length; $i++) {
            $char = $content[$i];
            
            if ($char === '{') {
                $openBraces++;
                $inMethod = true;
            } elseif ($char === '}') {
                $openBraces--;
                if ($inMethod && $openBraces === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Extract method content between start and end positions
     */
    private function extractMethodContent(string $content, int $start, int $end): string
    {
        return substr($content, $start, $end - $start + 1);
    }

    /**
     * Count lines in method content
     */
    private function countMethodLines(string $content, bool $excludeComments, bool $excludeEmptyLines): int
    {
        $lines = explode("\n", $content);
        $count = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines if configured
            if ($excludeEmptyLines && empty($trimmed)) {
                continue;
            }

            // Skip comment lines if configured
            if ($excludeComments && $this->isCommentLine($trimmed)) {
                continue;
            }

            // Skip opening and closing braces
            if ($trimmed === '{' || $trimmed === '}') {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Check if a line is a comment
     */
    private function isCommentLine(string $line): bool
    {
        return preg_match('/^\s*\/\/|^\s*\/\*|\*\/|^\s*\*/', $line) === 1;
    }
} 