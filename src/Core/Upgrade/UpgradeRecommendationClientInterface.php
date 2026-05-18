<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

interface UpgradeRecommendationClientInterface
{
    public function isConfigured(): bool;

    /**
     * Call FluffyJaws and return raw recommendation data.
     * Returns array with keys: root_cause, code_example, step_by_step, doc_links, confidence_score
     * Returns null on any network/auth failure.
     */
    public function recommend(string $findingType, array $context): ?array;
}
