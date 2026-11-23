<?php

declare(strict_types=1);

namespace ACQC\Core\Analysis;

use ACQC\Utils\SeverityMapper;
use Composer\InstalledVersions;

final class NativeCheckPolicy
{
    private const PHPMD_RULES = [
        'HighCyclomaticComplexity' => 'CyclomaticComplexity',
        'LongMethod' => 'ExcessiveMethodLength',
        'LongParameterList' => 'ExcessiveParameterList',
        'GodObject' => 'ExcessiveParameterList',
    ];
    private const PHPMD_CLASSES = [
        'CyclomaticComplexity' => 'PHPMD\Rule\CyclomaticComplexity',
        'ExcessiveMethodLength' => 'PHPMD\Rule\Design\LongMethod',
        'ExcessiveParameterList' => 'PHPMD\Rule\Design\LongParameterList',
    ];
    private const OBJECT_MANAGER_RULES = [
        'ObjectManagerUsage',
        'magento-performance-object-manager-usage',
        'magento-upgrade-objectmanager-usage',
    ];
    private const PHPCS_DUPLICATES = [
        'DirectSuperGlobalAccess' => 'PHPCS.Magento2.Security.Superglobal.',
        'CommandInjectionRisk' => 'PHPCS.Magento2.Security.InsecureFunction.',
        'FileInclusionRisk' => 'PHPCS.Magento2.Security.IncludeFile.',
        'DirectSqlQuery' => 'PHPCS.Magento2.SQL.RawQuery.',
        'MissingNamespace' => 'PHPCS.PSR1.Classes.ClassDeclaration.MissingNamespace',
    ];

    public static function validate(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('report.prefer_external_checks must be a boolean.');
        }
        return $value;
    }

    public function apply(array $violations, array $configuration, array $results, array $analyzedFiles): array
    {
        $coverage = [];
        $fallbacks = [];
        foreach (['php_phpmd', 'php_phpcs'] as $owner) {
            $coverage[$owner] = [];
            $status = $results[$owner]['status'] ?? null;
            if ($status !== 'success'
                && !($owner === 'php_phpmd' && $status === 'partial' && !empty($results[$owner]['skipped_files']))) {
                $fallbacks[] = $owner;
                continue;
            }
            if ($status === 'partial') {
                $fallbacks[] = $owner;
            }
            $skipped = [];
            foreach ($results[$owner]['skipped_files'] ?? [] as $failure) {
                $skipped[$this->fileKey($failure['file'])] = true;
            }
            foreach ($analyzedFiles[$owner] ?? [] as $file) {
                $key = $this->fileKey($file);
                if (!isset($skipped[$key])) {
                    $coverage[$owner][$key] = true;
                }
            }
            if ($coverage[$owner] === [] && !in_array($owner, $fallbacks, true)) {
                $fallbacks[] = $owner;
            }
        }

        $selected = [];
        if ($coverage['php_phpmd'] !== []) {
            $rulesets = $configuration['phpmd']['rulesets']
                ?? $configuration['rulesets']
                ?? ['cleancode', 'codesize', 'controversial', 'design', 'naming', 'unusedcode'];
            $priority = $configuration['phpmd']['minimum_priority'] ?? $configuration['minimum_priority'] ?? 1;
            foreach (array_unique(self::PHPMD_RULES) as $rule) {
                $selected[$rule] = false;
                foreach ($rulesets as $ruleset) {
                    if ($this->selectsPhpmdRule($this->phpmdFile($ruleset), $rule, $priority)) {
                        $selected[$rule] = true;
                        break;
                    }
                }
            }
        }
        $nesting = $coverage['php_phpcs'] !== [] ? $this->nestingRule($configuration) : null;
        $external = [];
        foreach ($violations as $violation) {
            $rule = $violation['rule'] ?? $violation['rule_id'];
            if (str_starts_with($rule, 'PHPCS.')) {
                $file = $this->fileKey($violation['file']);
                $external[$file][$violation['line'] ?? 0][] = $rule;
            }
        }

        $kept = [];
        $objects = [];
        $suppressed = [];
        $byAnalyzer = [];
        $remove = static function (array $violation) use (&$suppressed, &$byAnalyzer): void {
            $rule = $violation['rule'] ?? $violation['rule_id'];
            $suppressed[$rule] = ($suppressed[$rule] ?? 0) + 1;
            $analyzer = match ($rule) {
                'HighCyclomaticComplexity', 'LongMethod', 'LongParameterList', 'GodObject', 'DeeplyNestedCode' => 'php_code_health',
                'magento-performance-object-manager-usage' => 'magento_performance',
                'magento-upgrade-objectmanager-usage' => 'magento_upgrade',
                default => null,
            };
            if ($analyzer !== null) {
                $byAnalyzer[$analyzer] = ($byAnalyzer[$analyzer] ?? 0) + 1;
            }
        };
        foreach ($violations as $violation) {
            $rule = $violation['rule'] ?? $violation['rule_id'];
            $file = $this->fileKey($violation['file']);
            $line = $violation['line'] ?? 0;
            if (isset(self::PHPMD_RULES[$rule]) && ($selected[self::PHPMD_RULES[$rule]] ?? false)
                && isset($coverage['php_phpmd'][$file])) {
                $remove($violation);
                continue;
            }
            if ($rule === 'DeeplyNestedCode' && $nesting !== null && isset($coverage['php_phpcs'][$file])
                && $this->nestingCoversFile($nesting, $file)
                && $this->nestingCoversFile($nesting, str_replace('\\', '/', $violation['file']))) {
                $remove($violation);
                continue;
            }
            if (isset(self::PHPCS_DUPLICATES[$rule]) && isset($coverage['php_phpcs'][$file])) {
                $candidates = $rule === 'MissingNamespace'
                    ? array_merge([], ...array_values($external[$file] ?? []))
                    : ($external[$file][$line] ?? []);
                foreach ($candidates as $candidate) {
                    if (str_starts_with($candidate, self::PHPCS_DUPLICATES[$rule])) {
                        $remove($violation);
                        continue 2;
                    }
                }
            }
            if (in_array($rule, self::OBJECT_MANAGER_RULES, true) && $line > 0) {
                $key = $file . ':' . $line;
                $objects[$key][$rule][] = count($kept);
            }
            $kept[] = $violation;
        }
        foreach ($objects as $origins) {
            $bestOrigin = null;
            $bestSeverity = 0;
            foreach ($origins as $origin => $indices) {
                foreach ($indices as $index) {
                    $severity = SeverityMapper::toNumeric($kept[$index]['severity']);
                    if ($severity === null) {
                        throw new \InvalidArgumentException('Cannot rank an ObjectManager finding with an invalid severity.');
                    }
                    if ($severity > $bestSeverity) {
                        $bestOrigin = $origin;
                        $bestSeverity = $severity;
                    }
                }
            }
            foreach ($origins as $origin => $indices) {
                if ($origin !== $bestOrigin) {
                    foreach ($indices as $index) {
                        $remove($kept[$index]);
                        unset($kept[$index]);
                    }
                }
            }
        }
        ksort($suppressed);
        return [
            'violations' => array_values($kept),
            'suppressed_by_rule' => $suppressed,
            'suppressed_by_analyzer' => $byAnalyzer,
            'fallback_analyzers' => $fallbacks,
        ];
    }

    private function fileKey(string $file): string
    {
        return str_replace('\\', '/', realpath($file) ?: $file);
    }

    private function phpmdFile(string $ruleset): string
    {
        if (is_file($ruleset)) {
            return $ruleset;
        }
        $package = InstalledVersions::getInstallPath('phpmd/phpmd');
        if ($package === null) {
            throw new \RuntimeException('Cannot inspect PHPMD rules: its ACQC package is missing.');
        }
        $file = $package . '/src/main/resources/' . (str_starts_with($ruleset, 'rulesets/') ? $ruleset : 'rulesets/' . $ruleset . '.xml');
        if (!is_file($file)) {
            throw new \RuntimeException("Cannot inspect PHPMD ruleset: {$ruleset}");
        }
        return $file;
    }

    private function selectsPhpmdRule(string $file, string $target, int $priority, int $depth = 0, ?int $override = null): bool
    {
        if ($depth > 16) {
            throw new \RuntimeException('PHPMD rule references are cyclic or nested too deeply.');
        }
        $xml = $this->readXml($file);
        foreach ($xml->xpath('./*[local-name()="rule"]') as $rule) {
            foreach ($rule->xpath('./*[local-name()="exclude"]') as $excluded) {
                if ((string) $excluded['name'] === $target) {
                    continue 2;
                }
            }
            $priorities = $rule->xpath('./*[local-name()="priority"]');
            $localPriority = $override;
            if ($localPriority === null && $priorities !== []) {
                $text = trim((string) $priorities[0]);
                if (!ctype_digit($text) || (int) $text < 1 || (int) $text > 5) {
                    throw new \RuntimeException("Invalid PHPMD priority metadata in {$file}.");
                }
                $localPriority = (int) $text;
            }
            if ((string) $rule['name'] === $target
                && (string) $rule['class'] === self::PHPMD_CLASSES[$target]) {
                return $localPriority !== null && $localPriority <= $priority;
            }
            $ref = (string) $rule['ref'];
            if (preg_match('~^(.+\.xml)(?:/([^/]+))?$~D', $ref, $parts) !== 1
                || (isset($parts[2]) && $parts[2] !== $target)) {
                continue;
            }
            $referenced = str_starts_with($parts[1], 'rulesets/')
                ? $this->phpmdFile($parts[1])
                : (str_starts_with($parts[1], '/') ? $parts[1] : dirname($file) . '/' . $parts[1]);
            if ($this->selectsPhpmdRule($referenced, $target, $priority, $depth + 1, $localPriority)) {
                return true;
            }
        }
        return false;
    }

    private function nestingRule(array $configuration): ?\SimpleXMLElement
    {
        $sniff = 'Generic.Metrics.NestingLevel';
        if (($configuration['standard'] ?? 'Magento2') !== 'Magento2') {
            return null;
        }
        foreach ($configuration['exclude_sniffs'] ?? [] as $excluded) {
            if ($excluded === $sniff || str_starts_with($sniff, $excluded . '.') || str_starts_with($excluded, $sniff . '.')) {
                return null;
            }
        }
        $included = $configuration['sniffs'] ?? [];
        if ($included !== [] && !in_array($sniff, $included, true)) {
            return null;
        }
        $threshold = $configuration['phpcs']['warning_severity'] ?? $configuration['warning_severity'] ?? 5;
        if ($threshold === 0) {
            return null;
        }
        $package = InstalledVersions::getInstallPath('magento/magento-coding-standard');
        if ($package === null) {
            throw new \RuntimeException('Cannot inspect Magento2 PHPCS rules: its ACQC package is missing.');
        }
        $xml = $this->readXml($package . '/Magento2/ruleset.xml');
        foreach ($xml->xpath('./rule') as $rule) {
            if ((string) $rule['ref'] === $sniff && (string) $rule->type === 'warning'
                && (int) $rule->severity >= $threshold) {
                return $rule;
            }
        }
        return null;
    }

    private function nestingCoversFile(\SimpleXMLElement $rule, string $file): bool
    {
        foreach ($rule->{'exclude-pattern'} as $pattern) {
            if (fnmatch((string) $pattern, $file)) {
                return false;
            }
        }
        return true;
    }

    private function readXml(string $file): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($file, \SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                throw new \RuntimeException("Cannot read rule metadata from {$file}.");
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
