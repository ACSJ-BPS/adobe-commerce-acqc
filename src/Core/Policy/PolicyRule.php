<?php
declare(strict_types=1);
namespace ACQC\Core\Policy;

/**
 * Represents a single user-defined policy rule loaded from YAML.
 */
class PolicyRule
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $severity,   // minor|info|warning|error|critical|blocker
        public readonly string $category,
        public readonly string $type,       // pattern|ast|metric|file_exists|file_missing
        public readonly array  $conditions, // type-specific config
        public readonly string $message,    // violation message template
        public readonly bool   $enabled,
        public readonly ?string $fix,       // optional fix suggestion
        public readonly array  $tags,
    ) {}

    public static function fromArray(string $id, array $data): self
    {
        return new self(
            id:          $id,
            name:        $data['name'] ?? $id,
            description: $data['description'] ?? '',
            severity:    $data['severity'] ?? 'warning',
            category:    $data['category'] ?? 'custom',
            type:        $data['type'] ?? 'pattern',
            conditions:  $data['conditions'] ?? [],
            message:     $data['message'] ?? $data['description'] ?? 'Policy violation: ' . $id,
            enabled:     $data['enabled'] ?? true,
            fix:         $data['fix'] ?? null,
            tags:        $data['tags'] ?? [],
        );
    }
}
