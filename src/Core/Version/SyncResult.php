<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

readonly class SyncResult
{
    public function __construct(
        public bool $synced,
        public array $newVersionsFound,
        public array $errors,
        public string $source,
        public bool $isOffline,
    ) {}

    public static function offline(): self
    {
        return new self(
            synced: false,
            newVersionsFound: [],
            errors: ['Network unavailable; registry not updated'],
            source: 'none',
            isOffline: true,
        );
    }

    public static function success(array $newVersions, string $source): self
    {
        return new self(
            synced: true,
            newVersionsFound: $newVersions,
            errors: [],
            source: $source,
            isOffline: false,
        );
    }
}
