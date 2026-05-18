<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

use Symfony\Component\Yaml\Yaml;

class SuppressionStore
{
    public function __construct(private readonly string $projectConfigPath) {}

    /**
     * Append a suppression entry to .acqc/project.yaml.
     */
    public function addSuppression(string $ruleId, string $reason, ?string $reviewBy = null): void
    {
        $config = $this->loadConfig();

        if (!isset($config['suppressions'])) {
            $config['suppressions'] = [];
        }

        // Remove any existing suppression for same rule ID
        $config['suppressions'] = array_values(array_filter(
            $config['suppressions'],
            fn($s) => ($s['rule_id'] ?? null) !== $ruleId
        ));

        $entry = [
            'rule_id'        => $ruleId,
            'reason'         => $reason,
            'suppressed_by'  => $this->resolveCurrentUser(),
            'suppressed_at'  => date('Y-m-d'),
        ];

        if ($reviewBy !== null) {
            $entry['review_by'] = $reviewBy;
        }

        $config['suppressions'][] = $entry;
        $this->saveConfig($config);
    }

    /**
     * Remove a suppression by rule ID. Returns true if it existed.
     */
    public function removeSuppression(string $ruleId): bool
    {
        $config = $this->loadConfig();

        $before = count($config['suppressions'] ?? []);
        $config['suppressions'] = array_values(array_filter(
            $config['suppressions'] ?? [],
            fn($s) => ($s['rule_id'] ?? null) !== $ruleId
        ));
        $after = count($config['suppressions']);

        if ($before !== $after) {
            $this->saveConfig($config);
            return true;
        }

        return false;
    }

    /**
     * Return suppressions whose review_by date is in the past.
     */
    public function getExpiredSuppressions(): array
    {
        $today  = date('Y-m-d');
        $config = $this->loadConfig();

        return array_values(array_filter(
            $config['suppressions'] ?? [],
            function (array $s) use ($today): bool {
                $reviewBy = $s['review_by'] ?? null;
                return $reviewBy !== null && $reviewBy < $today;
            }
        ));
    }

    public function loadConfig(): array
    {
        if (!file_exists($this->projectConfigPath)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($this->projectConfigPath);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function saveConfig(array $config): void
    {
        $dir = dirname($this->projectConfigPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->projectConfigPath,
            Yaml::dump($config, 4, 2)
        );
    }

    private function resolveCurrentUser(): string
    {
        $email = trim((string) shell_exec('git config user.email 2>/dev/null'));
        return $email !== '' ? $email : (get_current_user() ?: 'unknown');
    }
}
