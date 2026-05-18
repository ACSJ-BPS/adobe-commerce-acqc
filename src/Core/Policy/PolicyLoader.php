<?php
declare(strict_types=1);
namespace ACQC\Core\Policy;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Loads policy rules from YAML files.
 *
 * Supported file locations (in priority order):
 *   1. Explicit paths passed to load()
 *   2. .acqc/policies/*.yaml  (project-level)
 *   3. ~/.acqc/policies/*.yaml (user-level)
 */
class PolicyLoader
{
    /** @return PolicyRule[] */
    public function loadFromFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("Policy file not found or not readable: {$path}");
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException("Failed to parse policy file {$path}: " . $e->getMessage());
        }

        return $this->parseRules($data ?? []);
    }

    /** @return PolicyRule[] */
    public function loadFromDirectory(string $dir): array
    {
        if (!is_dir($dir)) return [];

        $rules = [];
        foreach (glob(rtrim($dir, '/') . '/*.yaml') ?: [] as $file) {
            try {
                $rules = array_merge($rules, $this->loadFromFile($file));
            } catch (\Exception $e) {
                // Skip invalid files silently
            }
        }
        foreach (glob(rtrim($dir, '/') . '/*.yml') ?: [] as $file) {
            try {
                $rules = array_merge($rules, $this->loadFromFile($file));
            } catch (\Exception $e) {
                // skip
            }
        }
        return $rules;
    }

    /** @return PolicyRule[] */
    public function loadForProject(string $projectRoot): array
    {
        $rules = [];

        // Project-level policies
        $projectPolicyDir = rtrim($projectRoot, '/') . '/.acqc/policies';
        $rules = array_merge($rules, $this->loadFromDirectory($projectPolicyDir));

        // User-level policies
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';
        if ($home) {
            $userPolicyDir = rtrim($home, '/') . '/.acqc/policies';
            $rules = array_merge($rules, $this->loadFromDirectory($userPolicyDir));
        }

        return $rules;
    }

    /** @return PolicyRule[] */
    private function parseRules(array $data): array
    {
        $rules = [];

        // Support two formats:
        // Format A: top-level 'rules' key  →  rules: { rule-id: {...}, ... }
        // Format B: flat map               →  rule-id: { type: ..., ... }
        $rulesData = $data['rules'] ?? $data;

        foreach ($rulesData as $id => $config) {
            if (!is_array($config)) continue;
            if (!isset($config['type']) && !isset($config['name'])) continue; // skip metadata keys
            $rules[] = PolicyRule::fromArray((string)$id, $config);
        }

        return $rules;
    }
}
