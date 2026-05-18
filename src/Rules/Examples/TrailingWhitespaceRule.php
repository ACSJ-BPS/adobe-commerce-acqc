<?php

declare(strict_types=1);

namespace ACQC\Rules\Examples;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;

/**
 * Example fixable rule that detects and removes trailing whitespace
 */
class TrailingWhitespaceRule extends Rule
{
    protected string $id = 'trailing-whitespace';
    protected string $name = 'Trailing Whitespace';
    protected string $description = 'Detects and removes trailing whitespace from lines';
    protected string $severity = Violation::SEVERITY_INFO;
    protected string $type = Violation::TYPE_STYLE;
    protected ?string $category = 'formatting';
    protected int $priority = 10;
    protected array $tags = ['formatting', 'style'];
    protected array $fileExtensions = ['php', 'js', 'css', 'xml', 'json'];
    protected bool $fixable = true;
    protected ?string $documentationUrl = 'https://example.com/rules/trailing-whitespace';

    public function execute(string $filePath, string $content): array
    {
        $violations = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            // Check if line has trailing whitespace
            if (preg_match('/\s+$/', $line)) {
                $violation = $this->createViolation(
                    'Line has trailing whitespace',
                    $filePath,
                    $lineNumber + 1,
                    strlen(rtrim($line)) + 1,
                    $line
                );

                $violation->addContext('line_content', $line);
                $violation->addContext('trailing_chars', strlen($line) - strlen(rtrim($line)));
                $violation->setSuggestion('Remove trailing whitespace from the end of the line');
                $violation->setFixCode(rtrim($line));

                $violations[] = $violation;
            }
        }

        return $violations;
    }

    protected function performFix(string $content, array $violations): string
    {
        $lines = explode("\n", $content);
        
        // Remove trailing whitespace from all lines
        foreach ($lines as $index => $line) {
            $lines[$index] = rtrim($line);
        }

        return implode("\n", $lines);
    }
} 