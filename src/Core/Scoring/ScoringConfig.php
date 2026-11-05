<?php

declare(strict_types=1);

namespace ACQC\Core\Scoring;

/**
 * Immutable value object representing the effective merged scoring configuration.
 */
final class ScoringConfig
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $sources
     */
    public function __construct(
        private readonly array $data,
        private readonly array $sources = ['config/scoring.yaml']
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return list<string>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * @return array<string, string>
     */
    public function getSeverityAliases(): array
    {
        return $this->data['severity_aliases'] ?? [
            'high' => 'critical',
            'medium' => 'warning',
            'low' => 'info',
        ];
    }

    /**
     * @return array<string, float>
     */
    public function getCategoryWeights(): array
    {
        $weights = [];
        foreach (($this->data['category_weights'] ?? []) as $cat => $weight) {
            $weights[strtolower((string) $cat)] = (float) $weight;
        }
        return $weights;
    }

    /**
     * @return array{healthy: int, attention: int}
     */
    public function getBands(): array
    {
        return [
            'healthy' => (int) ($this->data['bands']['healthy'] ?? 80),
            'attention' => (int) ($this->data['bands']['attention'] ?? 60),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverall(): array
    {
        return $this->data['overall'] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDimensions(): array
    {
        $dims = [];
        foreach (($this->data['dimensions'] ?? []) as $id => $cfg) {
            if (is_array($cfg)) {
                $dims[(string) $id] = $cfg;
            }
        }
        return $dims;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getReadinessRules(): array
    {
        return array_values($this->data['readiness'] ?? []);
    }

    /**
     * @return list<array{min: float|int, grade: string, label: string}>
     */
    public function getGrades(): array
    {
        return array_values($this->data['grades'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuditOverall(): array
    {
        return $this->data['audit']['overall'] ?? [];
    }

    /**
     * Severity aliases used only by the `audit` score (YAML rule severities high/medium/low).
     *
     * @return array<string, string>
     */
    public function getAuditSeverityAliases(): array
    {
        $aliases = [];
        foreach (($this->data['audit']['severity_aliases'] ?? ['high' => 'error', 'medium' => 'warning', 'low' => 'info']) as $from => $to) {
            $aliases[strtolower((string) $from)] = strtolower((string) $to);
        }
        return $aliases;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDebt(): array
    {
        return $this->data['debt'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGates(): array
    {
        return array_values($this->data['gates'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAssessmentBands(): array
    {
        return array_values($this->data['assessment'] ?? []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getKpis(): array
    {
        $kpis = [];
        foreach (($this->data['kpis'] ?? []) as $id => $cfg) {
            if (is_array($cfg)) {
                $kpis[(string) $id] = $cfg;
            }
        }
        return $kpis;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return $this->data['status'] ?? [];
    }

    public function getPlaceholdersMode(): string
    {
        return (string) ($this->data['placeholders'] ?? 'show_na');
    }
}
