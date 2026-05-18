<?php

declare(strict_types=1);

namespace ACQC\Core\Rules\Checkers;

use ACQC\Core\Rules\RuleDefinition;

/** Tier 3 guidance rules never produce CLI findings. */
class GuidanceChecker implements CheckerInterface
{
    public function check(RuleDefinition $rule, string $filePath): array
    {
        return [];
    }
}
