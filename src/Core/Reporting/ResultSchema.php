<?php

declare(strict_types=1);

namespace ACQC\Core\Reporting;

/**
 * Versioned, stable machine-readable contract for ACQC results — the shape that
 * dashboards, CI, and BI consume. Bump VERSION only on breaking changes to the
 * envelope; additive fields keep the same major.
 *
 * This describes the *envelope* (schema_version, tool, platforms, summary,
 * issues). It is built from an existing audit result — no analysis logic here.
 */
class ResultSchema
{
    public const VERSION = '1.0';

    /**
     * Build a stable single-project result envelope from an AuditEngine result.
     *
     * @param array<string,mixed> $auditResult raw result from AuditEngine::runAudit
     * @param array<string,mixed> $meta        optional {name, generated_at}
     * @return array<string,mixed>
     */
    public static function fromAuditResult(array $auditResult, array $meta = []): array
    {
        $issues = is_array($auditResult['issues'] ?? null) ? $auditResult['issues'] : [];

        return [
            'schema_version' => self::VERSION,
            'tool'           => 'acqc',
            'generated_at'   => $meta['generated_at'] ?? date('c'),
            'project'        => [
                'path'      => $auditResult['project_path'] ?? ($meta['path'] ?? ''),
                'name'      => $meta['name'] ?? basename((string) ($auditResult['project_path'] ?? '')),
                'platforms' => array_values((array) ($auditResult['platforms'] ?? [])),
            ],
            'summary' => [
                'score'       => $auditResult['score'] ?? 0,
                'grade'       => $meta['grade'] ?? null,
                'issues'      => $auditResult['issues_found'] ?? count($issues),
                'by_severity' => self::countBy($issues, 'severity'),
                'by_category' => self::countBy($issues, 'category'),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return array<string,int>
     */
    public static function countBy(array $issues, string $key): array
    {
        $counts = [];
        foreach ($issues as $issue) {
            $value = (string) ($issue[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }
}
