<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

use Symfony\Component\Yaml\Yaml;

/**
 * Fallback used when ACQC_IMS_TOKEN is not set.
 * Loads static templates from config/upgrade/recommendation-templates/.
 */
class NullRecommendationClient implements UpgradeRecommendationClientInterface
{
    private static string $templateDir = '';

    public static function setTemplateDir(string $dir): void
    {
        self::$templateDir = $dir;
    }

    private function resolveTemplateDir(): string
    {
        if (self::$templateDir !== '') {
            return self::$templateDir;
        }

        return dirname(__DIR__, 3) . '/config/upgrade/recommendation-templates';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function recommend(string $findingType, array $context): ?array
    {
        $file = $this->resolveTemplateDir() . '/' . $findingType . '.yaml';

        if (!file_exists($file)) {
            return null;
        }

        $data = Yaml::parseFile($file);

        if (!is_array($data)) {
            return null;
        }

        return [
            'root_cause'       => $data['root_cause'] ?? '',
            'code_example'     => $data['code_example'] ?? '',
            'step_by_step'     => $data['step_by_step'] ?? '',
            'doc_links'        => $data['doc_links'] ?? [],
            'confidence_score' => 5,
        ];
    }
}
