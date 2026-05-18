<?php

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Models\Scan;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FindingController extends Controller
{
    private function authorizeAccess(Scan $scan, Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() ||
            $scan->project->members()->where('users.id', $user->id)->exists() ||
            $scan->project->created_by === $user->id,
            403,
            'You do not have access to this scan.'
        );
    }

    public function index(Request $request, Scan $scan)
    {
        $this->authorizeAccess($scan, $request);

        $profile  = $request->query('profile');
        $findings = $scan->findings()
            ->when($request->severity,  fn($q) => $q->where('severity', $request->severity))
            ->when($request->category,  fn($q) => $q->where('category', $request->category))
            ->when($request->resolved === 'true',  fn($q) => $q->resolved())
            ->when($request->resolved === 'false', fn($q) => $q->unresolved())
            ->when($profile === 'executive', fn($q) => $q->where('severity', 'error'))
            ->paginate(50);

        return response()->json($findings);
    }

    public function export(Request $request, Scan $scan): StreamedResponse
    {
        $this->authorizeAccess($scan, $request);
        $scan->loadMissing('project');

        $query = $scan->findings()
            ->when($request->severity,                fn($q) => $q->where('severity',  $request->severity))
            ->when($request->category,                fn($q) => $q->where('category',  $request->category))
            ->when($request->resolved === 'true',     fn($q) => $q->resolved())
            ->when($request->resolved === 'false',    fn($q) => $q->unresolved())
            ->orderByRaw("FIELD(severity,'blocker','critical','error','warning','info','minor')")
            ->orderBy('file_path')
            ->orderBy('line_number');

        $project  = $scan->project;
        $filename = sprintf(
            '%s-scan%s-%s.csv',
            str($project->dr_no)->lower()->slug('-'),
            $scan->id,
            now()->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($query, $scan, $project) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so Excel opens without encoding issues
            fwrite($out, "\xEF\xBB\xBF");

            // Metadata rows (prefixed with # so data tools can skip them)
            fwrite($out, "# Project: {$project->name} ({$project->dr_no})\n");
            fwrite($out, '# Scan date: ' . ($scan->completed_at?->format('Y-m-d H:i') ?? 'n/a') . "\n");
            fwrite($out, "# Score: {$scan->score}/100  Grade: {$scan->grade}\n");
            fwrite($out, "# Total: {$query->count()} finding(s)\n");

            fputcsv($out, ['Rule', 'Severity', 'Category', 'File', 'Line', 'Description', 'Recommendation', 'Status', 'Resolved At']);

            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $f) {
                    fputcsv($out, [
                        $this->csvSanitize($f->rule_name),
                        $f->severity,
                        $f->category ?? '',
                        $this->csvSanitize($f->file_path),
                        $f->line_number ?? '',
                        $this->csvSanitize($f->description),
                        $this->csvSanitize($f->recommendation ?? ''),
                        $f->resolved_at ? 'resolved' : 'open',
                        $f->resolved_at?->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-store',
        ]);
    }

    private function csvSanitize(?string $value): string
    {
        $value = (string) $value;
        // Prefix formula-triggering characters so spreadsheet apps treat the cell as text
        return preg_match('/^[=+\-@\t\r]/', $value) ? "\t{$value}" : $value;
    }

    public function resolve(Request $request, Finding $finding)
    {
        $finding->loadMissing('scan');
        $this->authorizeAccess($finding->scan, $request);
        $finding->update([
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json($finding);
    }

    public function unresolve(Request $request, Finding $finding)
    {
        $finding->loadMissing('scan');
        $this->authorizeAccess($finding->scan, $request);
        $finding->update(['resolved_by' => null, 'resolved_at' => null]);

        return response()->json($finding);
    }
}
