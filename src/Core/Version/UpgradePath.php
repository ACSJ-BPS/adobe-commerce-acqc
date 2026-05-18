<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

readonly class UpgradePath
{
    public function __construct(
        public string $source,
        public string $target,
        public array $hops,
        public bool $isDirect,
        public string $rationale,
        public array $prerequisites,
        public int $totalBreakingChanges,
        public string $estimatedDuration,
    ) {}
}
