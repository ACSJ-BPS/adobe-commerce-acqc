<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

readonly class VersionManifest
{
    public function __construct(
        public string $version,
        public ?\DateTimeImmutable $releaseDate,
        public ?\DateTimeImmutable $eolDate,
        public string $releaseType,
        public array $phpSupport,
        public array $searchEngineSupport,
        public array $composerRequirements,
        public array $breakingChanges,
        public array $deprecatedSymbols,
        public array $dbSchemaChanges,
        public array $securityAdvisories,
        public array $upgradeNotes,
    ) {}

    public function isEndOfLife(?\DateTimeImmutable $asOf = null): bool
    {
        if ($this->eolDate === null) {
            return false;
        }

        $checkDate = $asOf ?? new \DateTimeImmutable();

        return $checkDate >= $this->eolDate;
    }

    public function isPatchRelease(): bool
    {
        return $this->releaseType === 'security' || str_contains($this->version, '-p');
    }

    public static function fromArray(array $data): self
    {
        $releaseDate = null;
        if (!empty($data['release_date'])) {
            try {
                $releaseDate = new \DateTimeImmutable($data['release_date']);
            } catch (\Exception) {
                $releaseDate = null;
            }
        }

        $eolDate = null;
        if (!empty($data['eol_date'])) {
            try {
                $eolDate = new \DateTimeImmutable($data['eol_date']);
            } catch (\Exception) {
                $eolDate = null;
            }
        }

        $breakingChanges = [];
        foreach ($data['breaking_changes'] ?? [] as $bc) {
            $breakingChanges[] = BreakingChange::fromArray($bc);
        }

        $dbSchemaChanges = [];
        foreach ($data['db_schema_changes'] ?? [] as $dsc) {
            $dbSchemaChanges[] = DbSchemaChange::fromArray($dsc);
        }

        $securityAdvisories = [];
        foreach ($data['security_advisories'] ?? [] as $sa) {
            $securityAdvisories[] = SecurityAdvisory::fromArray($sa);
        }

        return new self(
            version: (string)($data['version'] ?? ''),
            releaseDate: $releaseDate,
            eolDate: $eolDate,
            releaseType: (string)($data['release_type'] ?? 'minor'),
            phpSupport: $data['php_support'] ?? [],
            searchEngineSupport: $data['search_engine_support'] ?? [],
            composerRequirements: $data['composer_requirements'] ?? [],
            breakingChanges: $breakingChanges,
            deprecatedSymbols: $data['deprecated_symbols'] ?? [],
            dbSchemaChanges: $dbSchemaChanges,
            securityAdvisories: $securityAdvisories,
            upgradeNotes: $data['upgrade_notes'] ?? [],
        );
    }
}
