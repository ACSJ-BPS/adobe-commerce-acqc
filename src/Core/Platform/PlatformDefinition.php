<?php

declare(strict_types=1);

namespace ACQC\Core\Platform;

/**
 * Immutable description of a supported platform (Adobe Commerce, App Builder,
 * Node.js, …), loaded from a config/platforms/<id>.yaml manifest.
 *
 * Adding a new language/app is a matter of dropping in a manifest plus, where
 * needed, an analyzer class — no engine change. See docs/extending.md.
 */
class PlatformDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** @var array<string,mixed> raw detect block */
        public readonly array $detect,
        /** @var string[] rule bundle dir names under config/rules/ */
        public readonly array $ruleBundles,
        /** @var string[] file glob patterns to scan */
        public readonly array $includePatterns,
        /** @var string[] analyzer ids enabled for this platform */
        public readonly array $analyzers,
        public readonly ?string $versionFeed,
        public readonly ?string $guardrailDomain,
    ) {
    }

    /**
     * @param array<string,mixed> $data the parsed `platform:` block
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:              (string) ($data['id'] ?? ''),
            name:            (string) ($data['name'] ?? ($data['id'] ?? '')),
            detect:          is_array($data['detect'] ?? null) ? $data['detect'] : [],
            ruleBundles:     array_values(array_filter((array) ($data['rule_bundles'] ?? []), 'is_string')),
            includePatterns: array_values(array_filter((array) ($data['include_patterns'] ?? []), 'is_string')),
            analyzers:       array_values(array_filter((array) ($data['analyzers'] ?? []), 'is_string')),
            versionFeed:     isset($data['version_feed']) ? (string) $data['version_feed'] : null,
            guardrailDomain: isset($data['guardrail_domain']) ? (string) $data['guardrail_domain'] : null,
        );
    }
}
