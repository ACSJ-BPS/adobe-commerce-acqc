<?php

declare(strict_types=1);

namespace ACQC\Reports\Exporters;

/**
 * Plain-text, one-line-per-issue output for git hooks.
 *
 * Only issues whose severity is in $failOn are listed (they are what blocks
 * the commit); other issues are summarised as a count so the hook stays short.
 * Output contains no ANSI codes so it renders in every git client / IDE.
 */
final class CompactTextExporter
{
    private const SEVERITY_RANK = ['blocker' => 0, 'critical' => 1, 'error' => 2, 'warning' => 3, 'minor' => 4, 'info' => 5];

    /**
     * @param array<int,array<string,mixed>> $issues
     * @param string[] $failOn
     */
    public function render(array $issues, array $failOn, string $scope, int $filesChecked, int $maxLines = 50): string
    {
        $blocking = array_values(array_filter(
            $issues,
            static fn (array $i): bool => in_array((string) ($i['severity'] ?? ''), $failOn, true)
        ));
        $nonBlocking = count($issues) - count($blocking);

        usort($blocking, static function (array $a, array $b): int {
            return [(string) ($a['file'] ?? ''), (int) ($a['line'] ?? 0), self::rank($a)]
                <=> [(string) ($b['file'] ?? ''), (int) ($b['line'] ?? 0), self::rank($b)];
        });

        $lines   = [];
        $lines[] = sprintf(
            'ACQC: %d file(s) checked (%s), fail on: %s',
            $filesChecked,
            $scope,
            $failOn === [] ? 'nothing' : implode(', ', $failOn)
        );

        foreach (array_slice($blocking, 0, $maxLines) as $issue) {
            $rule = (string) ($issue['rule_id'] ?? $issue['rule'] ?? $issue['type'] ?? '');
            $lines[] = sprintf(
                '  %s:%s  %s  %s%s',
                (string) ($issue['file'] ?? '?'),
                ($issue['line'] ?? null) !== null ? (string) $issue['line'] : '?',
                strtoupper((string) ($issue['severity'] ?? '')),
                $rule !== '' ? '[' . $rule . '] ' : '',
                self::oneLine((string) ($issue['message'] ?? ''))
            );
        }
        if (count($blocking) > $maxLines) {
            $lines[] = sprintf('  ... and %d more', count($blocking) - $maxLines);
        }

        $suffix = $nonBlocking > 0 ? sprintf(' (%d non-blocking issue(s) not shown)', $nonBlocking) : '';

        if ($blocking === []) {
            $lines[] = 'PASSED: no blocking issues' . $suffix . '.';
        } else {
            $lines[] = sprintf('BLOCKED: %s%s.', $this->countSummary($blocking), $suffix);
            $lines[] = 'Fix the issues above and re-stage, or bypass once with: git commit --no-verify';
        }

        return implode("\n", $lines);
    }

    public function renderNothingToCheck(string $scope): string
    {
        return sprintf('ACQC: no auditable files in scope (%s), nothing to check.', $scope);
    }

    public function renderToolError(string $message, int $exitCode): string
    {
        return sprintf('ACQC ERROR (exit %d): %s', $exitCode, self::oneLine($message));
    }

    /** @param array<int,array<string,mixed>> $blocking */
    private function countSummary(array $blocking): string
    {
        $counts = [];
        foreach ($blocking as $issue) {
            $sev = (string) ($issue['severity'] ?? 'unknown');
            $counts[$sev] = ($counts[$sev] ?? 0) + 1;
        }
        uksort($counts, static fn (string $a, string $b): int
            => (self::SEVERITY_RANK[$a] ?? 9) <=> (self::SEVERITY_RANK[$b] ?? 9));

        $parts = [];
        foreach ($counts as $sev => $n) {
            $parts[] = $n . ' ' . $sev;
        }
        return implode(', ', $parts);
    }

    private static function rank(array $issue): int
    {
        return self::SEVERITY_RANK[(string) ($issue['severity'] ?? '')] ?? 9;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
