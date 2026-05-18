<?php

declare(strict_types=1);

namespace ACQC\Core\Rules\Checkers;

use ACQC\Core\Rules\RuleDefinition;

class RegexChecker implements CheckerInterface
{
    public function check(RuleDefinition $rule, string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $pattern = $rule->check['pattern'] ?? '';
        if ($pattern === '') {
            return [];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $violations = [];
        $regex      = '~' . $pattern . '~';

        foreach ($lines as $index => $line) {
            if (@preg_match($regex, $line)) {
                $violations[] = new RuleViolation(
                    ruleId:         $rule->id,
                    severity:       $rule->severity,
                    filePath:       $filePath,
                    lineNumber:     $index + 1,
                    message:        $rule->title,
                    recommendation: $rule->recommendation,
                );
            }
        }

        return $violations;
    }
}
