<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class UpgradeRecommendationEngine
{
    private const ELIGIBLE_FINDING_TYPES = [
        'class_removed',
        'method_signature_changed',
        'plugin_conflict',
        'template_override_changed',
        'legacy_setup_script',
        'graphql_breaking',
        'deprecated_class',
        'composer_abandoned',
        'db_schema_change',
    ];

    private const SEVERITY_ORDER = ['blocker' => 0, 'critical' => 1, 'error' => 2];
    private const BATCH_SEVERITIES = ['error', 'critical', 'blocker'];
    private const CACHE_TTL = 7 * 24 * 3600;

    public function __construct(
        private UpgradeRecommendationClientInterface $client,
        private CacheInterface $cache
    ) {}

    public function getRecommendation(array $finding): ?UpgradeRecommendation
    {
        $type = $finding['type'] ?? '';

        if (!in_array($type, self::ELIGIBLE_FINDING_TYPES, true)) {
            return null;
        }

        $symbol   = $finding['symbol'] ?? $finding['class'] ?? '';
        $file     = $finding['file'] ?? $finding['file_path'] ?? '';
        $cacheKey = 'acqc_rec_' . sha1($type . ':' . $symbol . ':' . $file);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($finding, $type, $cacheKey): mixed {
            $item->expiresAfter(self::CACHE_TTL);

            $context = [
                'source_version' => $finding['source_version'] ?? '',
                'target_version' => $finding['target_version'] ?? '',
                'symbol'         => $finding['symbol'] ?? $finding['class'] ?? null,
                'file_path'      => $finding['file'] ?? $finding['file_path'] ?? null,
                'code_snippet'   => $finding['code_snippet'] ?? $finding['snippet'] ?? '',
            ];

            $response = $this->client->recommend($type, $context);

            if ($response === null) {
                // Signal cache to not store this result
                $item->expiresAfter(0);
                return null;
            }

            return new UpgradeRecommendation(
                findingId:        (string)($finding['id'] ?? $cacheKey),
                findingType:      $type,
                rootCause:        (string)($response['root_cause'] ?? ''),
                codeExample:      (string)($response['code_example'] ?? ''),
                stepByStep:       (string)($response['step_by_step'] ?? ''),
                docLinks:         (array)($response['doc_links'] ?? []),
                confidenceScore:  (int)($response['confidence_score'] ?? 5),
                isFromFluffyJaws: $this->client->isConfigured(),
                generatedAt:      date('c'),
                cacheKey:         $cacheKey,
            );
        });
    }

    public function batchGetRecommendations(array $findings, int $max = 20): array
    {
        $eligible = array_filter($findings, function (array $f): bool {
            $severity = $f['severity'] ?? '';
            $type     = $f['type'] ?? '';

            return in_array($severity, self::BATCH_SEVERITIES, true)
                && in_array($type, self::ELIGIBLE_FINDING_TYPES, true);
        });

        usort($eligible, function (array $a, array $b): int {
            $sa = self::SEVERITY_ORDER[$a['severity'] ?? ''] ?? 99;
            $sb = self::SEVERITY_ORDER[$b['severity'] ?? ''] ?? 99;
            return $sa <=> $sb;
        });

        $capped  = array_slice(array_values($eligible), 0, $max);
        $results = [];

        foreach ($capped as $finding) {
            $rec = $this->getRecommendation($finding);
            if ($rec !== null) {
                $id           = $finding['id'] ?? $rec->cacheKey;
                $results[$id] = $rec;
            }
        }

        return $results;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
