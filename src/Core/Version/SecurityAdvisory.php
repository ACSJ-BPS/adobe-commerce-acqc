<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

readonly class SecurityAdvisory
{
    public function __construct(
        public string $id,
        public ?string $cve,
        public string $severity,
        public string $description,
        public array $affectedVersions,
        public ?string $fixedIn,
        public bool $patchAvailable,
        public ?string $patchUrl,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            cve: $data['cve'] ?? null,
            severity: $data['severity'] ?? 'medium',
            description: $data['description'] ?? '',
            affectedVersions: $data['affected_versions'] ?? [],
            fixedIn: $data['fixed_in'] ?? null,
            patchAvailable: (bool)($data['patch_available'] ?? false),
            patchUrl: isset($data['patch_url']) && $data['patch_url'] !== '' ? $data['patch_url'] : null,
        );
    }
}
