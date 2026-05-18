<?php

declare(strict_types=1);

namespace ACQC\Core\Profiles;

use Symfony\Component\Yaml\Yaml;

readonly class ProfileConfig
{
    /**
     * @param int[]    $tiers
     * @param string[] $failOn
     * @param array<string,string> $severityLabels
     */
    public function __construct(
        public string  $name,
        public string  $description,
        public array   $tiers,
        public string  $minSeverity,
        public string  $format,
        public array   $failOn,
        public bool    $personalRules,
        public bool    $hideFilePaths,
        public bool    $hideLineNumbers,
        public bool    $hideRuleIds,
        public bool    $tier2AsWarnings,
        public ?string $secondaryFormat,
        public array   $severityLabels,
    ) {}

    public static function fromYaml(string $yaml): self
    {
        $data = Yaml::parse($yaml) ?? [];

        return new self(
            name:            (string) ($data['name']             ?? ''),
            description:     (string) ($data['description']      ?? ''),
            tiers:           array_map('intval', (array) ($data['tiers'] ?? [1, 2, 3])),
            minSeverity:     (string) ($data['min_severity']      ?? 'warning'),
            format:          (string) ($data['format']            ?? 'console'),
            failOn:          (array)  ($data['fail_on']           ?? ['error', 'critical', 'blocker']),
            personalRules:   (bool)   ($data['personal_rules']    ?? true),
            hideFilePaths:   (bool)   ($data['hide_file_paths']   ?? false),
            hideLineNumbers: (bool)   ($data['hide_line_numbers'] ?? false),
            hideRuleIds:     (bool)   ($data['hide_rule_ids']     ?? false),
            tier2AsWarnings: (bool)   ($data['tier2_as_warnings'] ?? false),
            secondaryFormat: isset($data['secondary_format']) && $data['secondary_format'] !== null
                ? (string) $data['secondary_format']
                : null,
            severityLabels:  (array)  ($data['severity_labels']  ?? []),
        );
    }
}
