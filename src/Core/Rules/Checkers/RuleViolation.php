<?php

declare(strict_types=1);

namespace ACQC\Core\Rules\Checkers;

readonly class RuleViolation
{
    public function __construct(
        public string  $ruleId,
        public string  $severity,
        public string  $filePath,
        public ?int    $lineNumber,
        public string  $message,
        public ?string $recommendation,
    ) {}
}
