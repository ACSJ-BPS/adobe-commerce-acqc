<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

readonly class BreakingChange
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $symbol,
        public ?string $class,
        public ?string $method,
        public ?string $changeDescription,
        public string $severity,
        public ?string $affectedFilesPattern,
        public string $remediationSummary,
        public ?string $docUrl,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            type: $data['type'] ?? 'unknown',
            symbol: $data['symbol'] ?? null,
            class: $data['class'] ?? null,
            method: $data['method'] ?? null,
            changeDescription: $data['change_description'] ?? null,
            severity: $data['severity'] ?? 'notice',
            affectedFilesPattern: $data['affected_files_pattern'] ?? null,
            remediationSummary: $data['remediation_summary'] ?? '',
            docUrl: isset($data['doc_url']) && $data['doc_url'] !== '' ? $data['doc_url'] : null,
        );
    }
}
