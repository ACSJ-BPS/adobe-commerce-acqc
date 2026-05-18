<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

class RuleRegistry
{
    /** @var array<string, RuleDefinition[]> Loaded rule sets per profile */
    private array $profileCache = [];

    public function __construct(private readonly YamlRuleLoader $loader) {}

    /**
     * Return all rules for the given profile.
     *
     * @return RuleDefinition[]
     */
    public function forProfile(string $profile = 'developer'): array
    {
        if (!isset($this->profileCache[$profile])) {
            $this->profileCache[$profile] = $this->loader->load($profile);
        }

        return $this->profileCache[$profile];
    }

    /** Look up a single rule by ID, or null if not found. */
    public function get(string $id, string $profile = 'developer'): ?RuleDefinition
    {
        return $this->forProfile($profile)[$id] ?? null;
    }

    /**
     * Return only rules whose globs match the given file path.
     *
     * @return RuleDefinition[]
     */
    public function forFile(string $filePath, string $profile = 'developer'): array
    {
        return array_filter(
            $this->forProfile($profile),
            fn(RuleDefinition $rule) => $rule->appliesToFile($filePath)
        );
    }

    /** Clear the in-memory profile cache (useful in tests). */
    public function flush(): void
    {
        $this->profileCache = [];
    }
}
