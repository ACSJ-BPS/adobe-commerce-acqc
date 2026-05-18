<?php

namespace App\Jobs;

use App\Models\Finding;
use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(public readonly Scan $scan) {}

    public function handle(): void
    {
        $scan    = $this->scan->fresh(['project']);
        $project = $scan->project;

        $scan->update(['status' => 'running', 'started_at' => now()]);

        try {
            $localPath = $project->local_path;

            if (!$localPath || !is_dir($localPath)) {
                throw new \RuntimeException(
                    "local_path '{$localPath}' is not a valid directory. " .
                    "Update the project's local_path to point to the checked-out repository."
                );
            }

            $bin = config('acqc.bin', base_path('../bin/acqc'));
            $cmd = sprintf(
                'php %s audit %s --output json --severity info 2>/dev/null',
                escapeshellarg($bin),
                escapeshellarg($localPath)
            );

            $rawOutput = shell_exec($cmd);

            if (!$rawOutput) {
                throw new \RuntimeException('CLI produced no output. Check that bin/acqc is executable and the path is valid.');
            }

            $data = json_decode($rawOutput, true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['error'])) {
                throw new \RuntimeException('CLI error: ' . $data['error']);
            }

            // ── Severity mapping CLI → DB enum ───────────────────────────
            $severityMap = [
                'error'   => 'error',
                'warning' => 'warning',
                'info'    => 'info',
                'notice'  => 'info',
                'minor'   => 'minor',
                'critical'=> 'critical',
                'blocker' => 'blocker',
            ];

            // ── Category inference from issue type ───────────────────────
            $categoryMap = [
                'n_plus_one_query'    => 'performance',
                'inefficient_loop'    => 'performance',
                'memory_leak'         => 'performance',
                'sql_injection'       => 'security',
                'xss'                 => 'security',
                'csrf'                => 'security',
                'object_manager'      => 'maintainability',
                'deprecated'          => 'maintainability',
                'coding_standard'     => 'standards',
            ];

            // ── Build severity counts ─────────────────────────────────────
            $issues = $data['issues'] ?? [];
            $counts = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
            $categoryBreakdown = [];

            $findings = [];
            foreach ($issues as $issue) {
                $sev      = $severityMap[$issue['severity'] ?? 'warning'] ?? 'warning';
                $cat      = $categoryMap[$issue['type'] ?? ''] ?? 'standards';
                $ruleId   = $issue['type']    ?? null;
                $ruleName = ucwords(str_replace('_', ' ', $ruleId ?? 'Unknown Rule'));
                $filePath = $issue['file']    ?? '';
                $line     = isset($issue['line']) ? (int)$issue['line'] : null;
                $desc     = $issue['message'] ?? '';

                // Count by bucket
                if (in_array($sev, ['critical', 'blocker']))   $counts['critical']++;
                elseif ($sev === 'error')                       $counts['error']++;
                elseif ($sev === 'warning')                     $counts['warning']++;
                else                                            $counts['info']++;

                $categoryBreakdown[$cat] = ($categoryBreakdown[$cat] ?? 0) + 1;

                $fingerprint = sha1("{$filePath}:{$line}:{$ruleId}");

                $findings[] = [
                    'scan_id'        => $scan->id,
                    'file_path'      => $filePath,
                    'line_number'    => $line,
                    'rule_id'        => $ruleId,
                    'rule_name'      => $ruleName,
                    'severity'       => $sev,
                    'category'       => $cat,
                    'description'    => $desc,
                    'recommendation' => null,
                    'fingerprint'    => $fingerprint,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }

            // Bulk insert findings in chunks to avoid memory issues
            foreach (array_chunk($findings, 500) as $chunk) {
                Finding::insert($chunk);
            }

            // ── Grade calculation ─────────────────────────────────────────
            $score = (int) ($data['score'] ?? 0);
            $grade = match(true) {
                $score >= 95 => 'A+',
                $score >= 85 => 'A',
                $score >= 75 => 'B',
                $score >= 65 => 'C',
                $score >= 50 => 'D',
                default      => 'F',
            };

            $scan->update([
                'status'             => 'complete',
                'score'              => $score,
                'grade'              => $grade,
                'total_files'        => $data['files_analyzed'] ?? 0,
                'total_lines'        => $data['total_lines']    ?? 0,
                'critical_count'     => $counts['critical'],
                'error_count'        => $counts['error'],
                'warning_count'      => $counts['warning'],
                'info_count'         => $counts['info'],
                'category_breakdown' => $categoryBreakdown,
                'completed_at'       => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error('ScanProject failed', ['scan_id' => $scan->id, 'error' => $e->getMessage()]);

            $scan->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }
    }
}
