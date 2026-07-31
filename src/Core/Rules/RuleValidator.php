<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

class RuleValidator
{
    /**
     * Validate a rule data array and return an immutable RuleDefinition.
     * Collects ALL errors before throwing — never fail-fast.
     *
     * @throws RuleValidationException with all collected errors in the message
     */
    public function validate(array $data, string $filePath): RuleDefinition
    {
        $errors = [];

        // 1. Required top-level fields
        foreach (RuleSchema::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors[] = "Missing required field: '{$field}'";
            }
        }

        // 2. Tier-dependent required fields (only check when tier resolves to 1 or 2)
        $tier = isset($data['tier']) ? (int) $data['tier'] : null;
        if ($tier !== null && in_array($tier, [1, 2], true)) {
            foreach (RuleSchema::REQUIRED_FIELDS_TIER_1_2 as $field) {
                if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                    $errors[] = "Tier {$tier} rule missing required field: '{$field}'";
                }
            }
        }

        // 3. Severity must be in the valid set
        if (isset($data['severity']) && !in_array($data['severity'], RuleSchema::SEVERITIES, true)) {
            $valid = implode(', ', RuleSchema::SEVERITIES);
            $errors[] = "Invalid severity '{$data['severity']}'. Must be one of: {$valid}";
        }

        // 4. Tier must be in [1, 2, 3]
        if (isset($data['tier']) && !in_array((int) $data['tier'], RuleSchema::TIERS, true)) {
            $errors[] = "Invalid tier '{$data['tier']}'. Must be one of: 1, 2, 3";
        }

        // 5. check.type must be valid (if check key is present)
        if (isset($data['check']) && is_array($data['check'])) {
            $checkType = $data['check']['type'] ?? null;
            if ($checkType === null) {
                $errors[] = "check.type is required when 'check' key is present";
            } elseif (!in_array($checkType, RuleSchema::CHECK_TYPES, true)) {
                $valid = implode(', ', RuleSchema::CHECK_TYPES);
                $errors[] = "Invalid check.type '{$checkType}'. Must be one of: {$valid}";
            }
        }

        // 6. ID format: /^[A-Z]{2,5}_[A-Z]{2,8}_\d{3}$/
        if (isset($data['id']) && $data['id'] !== '') {
            if (!preg_match(RuleSchema::ID_PATTERN, (string) $data['id'])) {
                $errors[] = "Invalid ID format '{$data['id']}'. Must match pattern: PREFIX_CATEGORY_NNN (e.g. AC_DI_001)";
            }
        }

        // 7. Roles must all be valid
        if (isset($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $role) {
                if (!is_string($role) || !in_array($role, RuleSchema::ROLES, true)) {
                    $valid   = implode(', ', RuleSchema::ROLES);
                    $display = is_scalar($role) ? (string) $role : gettype($role);
                    $errors[] = "Invalid role '{$display}'. Must be one of: {$valid}";
                }
            }
        }

        // 8. Platforms must all be valid
        if (isset($data['platforms']) && is_array($data['platforms'])) {
            foreach ($data['platforms'] as $platform) {
                if (!is_string($platform) || !in_array($platform, RuleSchema::PLATFORMS, true)) {
                    $valid   = implode(', ', RuleSchema::PLATFORMS);
                    $display = is_scalar($platform) ? (string) $platform : gettype($platform);
                    $errors[] = "Invalid platform '{$display}'. Must be one of: {$valid}";
                }
            }
        }

        if (!empty($errors)) {
            $list    = implode('; ', $errors);
            $fileRef = $filePath !== '' ? " in {$filePath}" : '';
            throw new RuleValidationException("Rule validation failed{$fileRef}: {$list}");
        }

        return RuleDefinition::fromArray($data, $filePath);
    }
}
