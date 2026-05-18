<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

readonly class UpgradeRecommendation
{
    public function __construct(
        public readonly string $findingId,
        public readonly string $findingType,
        public readonly string $rootCause,
        public readonly string $codeExample,
        public readonly string $stepByStep,
        public readonly array  $docLinks,
        public readonly int    $confidenceScore,
        public readonly bool   $isFromFluffyJaws,
        public readonly string $generatedAt,
        public readonly string $cacheKey,
    ) {}
}
