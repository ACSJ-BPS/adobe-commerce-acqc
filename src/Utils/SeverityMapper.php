<?php

declare(strict_types=1);

namespace ACQC\Utils;

use ACQC\Core\Rules\Violation;

/**
 * Severity Level Mapper
 * 
 * Maps numeric severity levels to text-based severity names
 * and vice versa for command line parameter flexibility.
 */
class SeverityMapper
{
    /**
     * Numeric to text severity mapping
     */
    private const NUMERIC_TO_TEXT = [
        1 => Violation::SEVERITY_MINOR,
        2 => Violation::SEVERITY_INFO,
        3 => Violation::SEVERITY_WARNING,
        4 => Violation::SEVERITY_ERROR,
        5 => Violation::SEVERITY_CRITICAL,
        6 => Violation::SEVERITY_BLOCKER,
    ];

    /**
     * Text to numeric severity mapping
     */
    private const TEXT_TO_NUMERIC = [
        Violation::SEVERITY_MINOR => 1,
        Violation::SEVERITY_INFO => 2,
        Violation::SEVERITY_WARNING => 3,
        Violation::SEVERITY_ERROR => 4,
        Violation::SEVERITY_CRITICAL => 5,
        Violation::SEVERITY_BLOCKER => 6,
    ];

    /**
     * Convert numeric severity to text
     */
    public static function toText(int $numericSeverity): ?string
    {
        return self::NUMERIC_TO_TEXT[$numericSeverity] ?? null;
    }

    /**
     * Convert text severity to numeric
     */
    public static function toNumeric(string $textSeverity): ?int
    {
        return self::TEXT_TO_NUMERIC[$textSeverity] ?? null;
    }

    /**
     * Normalize severity input (accepts both numeric and text)
     * Returns text-based severity name
     */
    public static function normalize($severity): ?string
    {
        // If already text, validate and return
        if (is_string($severity)) {
            $severity = strtolower($severity);
            if (in_array($severity, Violation::getSeverityLevels())) {
                return $severity;
            }
        }

        // If numeric, convert to text
        if (is_numeric($severity)) {
            return self::toText((int)$severity);
        }

        return null;
    }

    /**
     * Validate severity input (accepts both numeric and text)
     */
    public static function isValid($severity): bool
    {
        return self::normalize($severity) !== null;
    }

    /**
     * Get all valid severity levels as text
     */
    public static function getAllTextLevels(): array
    {
        return Violation::getSeverityLevels();
    }

    /**
     * Get all valid severity levels as numeric
     */
    public static function getAllNumericLevels(): array
    {
        return array_keys(self::NUMERIC_TO_TEXT);
    }

    /**
     * Get severity level description
     */
    public static function getDescription($severity): string
    {
        $text = self::normalize($severity);
        
        $descriptions = [
            Violation::SEVERITY_BLOCKER => 'Level 6 - Blocker: Must be fixed immediately, blocks deployment',
            Violation::SEVERITY_CRITICAL => 'Level 5 - Critical: Critical issues requiring urgent attention',
            Violation::SEVERITY_ERROR => 'Level 4 - Error: Errors that should be fixed',
            Violation::SEVERITY_WARNING => 'Level 3 - Warning: Warnings to be addressed',
            Violation::SEVERITY_INFO => 'Level 2 - Info: Informational issues',
            Violation::SEVERITY_MINOR => 'Level 1 - Minor: Minor issues, low priority',
        ];

        return $descriptions[$text] ?? 'Unknown severity level';
    }

    /**
     * Get help text for command line usage
     */
    public static function getHelpText(): string
    {
        return <<<HELP
Severity levels can be specified either by name or number:

By Name:
  blocker, critical, error, warning, info, minor

By Number:
  6 = blocker   (Must fix immediately, blocks deployment)
  5 = critical  (Critical issues requiring urgent attention)
  4 = error     (Errors that should be fixed)
  3 = warning   (Warnings to be addressed)
  2 = info      (Informational issues)
  1 = minor     (Minor issues, low priority)

Examples:
  --severity error      (filter by name)
  --severity 4          (filter by number, same as 'error')
  --severity blocker    (filter by name)
  --severity 6          (filter by number, same as 'blocker')
HELP;
    }
}
