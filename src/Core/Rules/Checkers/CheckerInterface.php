<?php

declare(strict_types=1);

namespace ACQC\Core\Rules\Checkers;

use ACQC\Core\Rules\RuleDefinition;

interface CheckerInterface
{
    /** Run the rule against a single file and return all violations found. */
    public function check(RuleDefinition $rule, string $filePath): array;
}
