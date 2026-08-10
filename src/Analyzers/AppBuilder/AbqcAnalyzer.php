<?php

declare(strict_types=1);

namespace ACQC\Analyzers\AppBuilder;

use ACQC\Analyzers\AbstractExternalToolAnalyzer;
use ACQC\Core\AcqcPaths;
use Symfony\Component\Process\Process;

/**
 * Wraps the vendored `abqc` Node/TypeScript engine (abqc/packages/cli) to surface
 * its ESLint + mechanical findings as ACQC issues.
 *
 * abqc is not installed as a project dependency or PATH binary — it is bundled
 * under `abqc/` at the ACQC resource root (installed via the root `npm install`,
 * which builds it through the `postinstall` hook). If it hasn't been built yet
 * (dist/ missing), this analyzer degrades gracefully to no issues, same as any
 * other external-tool analyzer whose tool isn't installed.
 */
class AbqcAnalyzer extends AbstractExternalToolAnalyzer
{
    public function getName(): string
    {
        return 'appbuilder-abqc';
    }

    public function getDescription(): string
    {
        return 'abqc — ESLint-backed App Builder / Node.js code-quality checks (mechanical + security rules)';
    }

    protected function toolBinary(): string
    {
        return 'node';
    }

    /** Project-level: abqc walks the tree itself, not ACQC's file list. */
    protected function supportedExtensions(): array
    {
        return [];
    }

    protected function buildProcess(string $binary, string $projectDir, array $files): ?Process
    {
        $abqcBin = AcqcPaths::resourceRoot() . '/abqc/packages/cli/dist/bin.js';
        if (!is_file($abqcBin)) {
            return null; // abqc not built — run `npm install` at the ACQC root
        }

        return new Process([$binary, $abqcBin, 'review', $projectDir, '--format=json'], $projectDir, ['CI' => '1']);
    }

    protected function parseOutput(string $stdout, string $stderr, ?int $exitCode): array
    {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return [];
        }

        $findings = json_decode($stdout, true);
        if (!is_array($findings)) {
            return [];
        }

        $issues = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $ruleId   = (string) ($finding['ruleId'] ?? 'unknown');
            $category = $this->categorize($ruleId);
            $source   = (string) ($finding['source'] ?? 'mechanical');

            $issues[] = [
                'rule_id'        => 'ABQC:' . $ruleId,
                'severity'       => $this->mapSeverity((string) ($finding['severity'] ?? 'warn')),
                'file'           => (string) ($finding['filePath'] ?? ''),
                'line'           => $finding['line'] ?? null,
                'message'        => (string) ($finding['message'] ?? ''),
                'recommendation' => "abqc rule '{$ruleId}' ({$source}) — see abqc/rules/ for remediation guidance.",
                'category'       => $category,
                'source'         => 'abqc',
                'confidence'     => (string) ($finding['confidence'] ?? 'high'),
                'roles'          => ['developer', 'tech_lead', 'ci'],
            ];
        }

        return $issues;
    }

    /**
     * Map an abqc rule id to an ACQC report category. Mostly the rule id's first
     * path segment (e.g. "security/..." -> "security"), except "node/complexity/*"
     * -> "complexity" so function-length/cyclomatic-complexity/max-params/etc.
     * join the same "maintainability" bucket the HTML report's Actionable
     * Recommendations section already aggregates (by_cat.maintainability +
     * by_cat.complexity + by_cat.documentation — see summary.html.twig). Without
     * this they'd land under an uncounted "node" category and never surface
     * there, even though they're squarely maintainability findings.
     */
    private function categorize(string $ruleId): string
    {
        $parts = explode('/', $ruleId, 3);
        if (($parts[0] ?? '') === 'node' && ($parts[1] ?? '') === 'complexity') {
            return 'complexity';
        }

        return $parts[0] !== '' ? $parts[0] : 'general';
    }

    /** Map abqc/ESLint severities ('error'|'warn'|'info') to ACQC's vocabulary. */
    private function mapSeverity(string $abqcSeverity): string
    {
        return match ($abqcSeverity) {
            'error' => 'error',
            'warn'  => 'warning',
            'info'  => 'info',
            default => 'warning',
        };
    }
}
