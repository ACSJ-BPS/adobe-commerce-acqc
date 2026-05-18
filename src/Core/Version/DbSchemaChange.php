<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

readonly class DbSchemaChange
{
    public function __construct(
        public string $table,
        public ?string $column,
        public string $changeType,
        public string $migrationRisk,
        public bool $dataMigrationRequired,
        public ?int $estimatedDurationMinutes,
        public ?string $indexName,
        public ?string $indexType,
    ) {}

    public function isHighRisk(): bool
    {
        return in_array($this->migrationRisk, ['high', 'critical'], true);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            table: $data['table'] ?? '',
            column: $data['column'] ?? null,
            changeType: $data['change_type'] ?? 'unknown',
            migrationRisk: $data['migration_risk'] ?? 'low',
            dataMigrationRequired: (bool)($data['data_migration_required'] ?? false),
            estimatedDurationMinutes: isset($data['estimated_duration_minutes'])
                ? (int)$data['estimated_duration_minutes']
                : null,
            indexName: $data['index_name'] ?? null,
            indexType: $data['index_type'] ?? null,
        );
    }
}
