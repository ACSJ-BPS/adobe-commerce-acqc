<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

/**
 * PHP Version Value Object
 * 
 * Represents a specific PHP version and provides
 * feature detection and compatibility checking.
 */
class PhpVersion
{
    private int $major;
    private int $minor;
    private int $release;

    public function __construct(int $major, int $minor, int $release = 0)
    {
        $this->major = $major;
        $this->minor = $minor;
        $this->release = $release;
    }

    public function getMajor(): int
    {
        return $this->major;
    }

    public function getMinor(): int
    {
        return $this->minor;
    }

    public function getRelease(): int
    {
        return $this->release;
    }

    public function toString(): string
    {
        return sprintf('%d.%d.%d', $this->major, $this->minor, $this->release);
    }

    public function toShortString(): string
    {
        return sprintf('%d.%d', $this->major, $this->minor);
    }

    /**
     * Check if PHP version supports a feature
     */
    public function supports(string $feature): bool
    {
        $features = [
            // PHP 7.4
            'typed_properties' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'],
            'arrow_functions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'],

            // PHP 8.0
            'named_arguments' => ['8.0', '8.1', '8.2', '8.3', '8.4'],
            'attributes' => ['8.0', '8.1', '8.2', '8.3', '8.4'],
            'union_types' => ['8.0', '8.1', '8.2', '8.3', '8.4'],
            'match_expression' => ['8.0', '8.1', '8.2', '8.3', '8.4'],
            'nullsafe_operator' => ['8.0', '8.1', '8.2', '8.3', '8.4'],

            // PHP 8.1
            'enums' => ['8.1', '8.2', '8.3', '8.4'],
            'readonly_properties' => ['8.1', '8.2', '8.3', '8.4'],
            'first_class_callables' => ['8.1', '8.2', '8.3', '8.4'],
            'fibers' => ['8.1', '8.2', '8.3', '8.4'],

            // PHP 8.2
            'readonly_classes' => ['8.2', '8.3', '8.4'],
            'disjunctive_normal_form_types' => ['8.2', '8.3', '8.4'],

            // PHP 8.3
            'typed_constants' => ['8.3', '8.4'],

            // PHP 8.4
            'property_hooks' => ['8.4'],
            'asymmetric_visibility' => ['8.4'],
            'lazy_objects' => ['8.4'],
            'array_find' => ['8.4'],
            'new_without_parens' => ['8.4'],
        ];

        if (!isset($features[$feature])) {
            return false;
        }

        return in_array($this->toShortString(), $features[$feature]);
    }

    /**
     * Check if a feature is deprecated
     */
    public function isDeprecated(string $feature): bool
    {
        $deprecations = [
            // PHP 8.0
            'dynamic_properties' => '8.2',
            'utf8_encode_decode' => '8.2',
            
            // PHP 8.1
            'null_to_non_nullable' => '8.1',
            'serializable_interface' => '8.1',
            
            // PHP 8.2
            'dynamic_properties' => '8.2',

            // PHP 8.4
            'implicit_nullable_params' => '8.4',
        ];

        if (!isset($deprecations[$feature])) {
            return false;
        }

        list($depMajor, $depMinor) = explode('.', $deprecations[$feature]);
        
        return $this->major > (int)$depMajor || 
               ($this->major === (int)$depMajor && $this->minor >= (int)$depMinor);
    }

    /**
     * Get breaking changes for this version
     */
    public function getBreakingChanges(): array
    {
        $changes = [
            '8.0' => [
                'stricter_type_checks' => 'Stricter type checking for arithmetic/bitwise operators',
                'fatal_errors' => 'Many warnings converted to Error exceptions',
                'removed_functions' => 'Removed deprecated functions (create_function, etc.)',
            ],
            '8.1' => [
                'null_to_non_nullable' => 'Passing null to non-nullable parameters deprecated',
                'serializable' => 'Serializable interface deprecated',
            ],
            '8.2' => [
                'dynamic_properties' => 'Dynamic properties deprecated (use #[AllowDynamicProperties])',
                'utf8_functions' => 'utf8_encode/decode deprecated',
            ],
        ];

        $versionKey = $this->toShortString();
        return $changes[$versionKey] ?? [];
    }

    /**
     * Get removed functions/features
     */
    public function getRemovedFeatures(): array
    {
        $removed = [
            '8.0' => [
                'create_function',
                'each',
                'read_exif_data',
                '__autoload',
            ],
            '8.1' => [],
            '8.2' => [],
        ];

        $versionKey = $this->toShortString();
        return $removed[$versionKey] ?? [];
    }

    /**
     * Check if version is End-of-Life
     */
    public function isEol(): bool
    {
        $eolDates = [
            '7.2' => '2020-11-30',
            '7.3' => '2021-12-06',
            '7.4' => '2022-11-28',
            '8.0' => '2023-11-26',
            '8.1' => '2025-11-25',
            '8.2' => '2026-12-31',
            '8.3' => '2027-12-31',
            '8.4' => '2028-12-31',
        ];

        $versionKey = $this->toShortString();
        if (!isset($eolDates[$versionKey])) {
            return false;
        }

        $eolDate = strtotime($eolDates[$versionKey]);
        return time() > $eolDate;
    }

    /**
     * Compare with another PHP version
     */
    public function isGreaterThan(PhpVersion $other): bool
    {
        if ($this->major > $other->major) {
            return true;
        }
        if ($this->major < $other->major) {
            return false;
        }

        if ($this->minor > $other->minor) {
            return true;
        }
        if ($this->minor < $other->minor) {
            return false;
        }

        return $this->release > $other->release;
    }

    /**
     * Check if this version is less than another
     */
    public function isLessThan(PhpVersion $other): bool
    {
        return !$this->isGreaterThan($other) && !$this->isEqual($other);
    }

    /**
     * Check if this version is greater than or equal to another
     */
    public function isGreaterThanOrEqual(PhpVersion $other): bool
    {
        return $this->isGreaterThan($other) || $this->isEqual($other);
    }

    /**
     * Check if this version equals another
     */
    public function isEqual(PhpVersion $other): bool
    {
        return $this->major === $other->major
            && $this->minor === $other->minor
            && $this->release === $other->release;
    }
}

