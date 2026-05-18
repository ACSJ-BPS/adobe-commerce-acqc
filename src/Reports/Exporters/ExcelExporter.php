<?php

declare(strict_types=1);

namespace ACQC\Reports\Exporters;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use ACQC\Analyzers\Version\ComposerAnalysisResult;
use ACQC\Analyzers\Version\DBSchemaAnalysisResult;
use ACQC\Analyzers\Version\UpgradeAnalysisResult;
use ACQC\Core\Upgrade\URIScore;

/**
 * Excel Report Exporter
 * 
 * Exports upgrade analysis results to Excel format with multiple sheets
 */
class ExcelExporter
{
    private const HEADER_BG    = '1e40af';
    private const ROW_CRITICAL = 'fee2e2';
    private const ROW_ERROR    = 'ffedd5';
    private const ROW_WARNING  = 'fefce8';

    /**
     * Export analysis result to Excel file
     */
    public function export(
        UpgradeAnalysisResult $result,
        string $outputPath,
        ?URIScore $uri = null,
        ?DBSchemaAnalysisResult $dbSchema = null,
        ?ComposerAnalysisResult $composer = null,
        ?array $recommendations = null,
    ): void {
        $spreadsheet = new Spreadsheet();

        // Remove default sheet
        $spreadsheet->removeSheetByIndex(0);

        // Sheet 1: Executive Summary (with URI)
        $this->createExecutiveSummarySheet($spreadsheet, $result, $uri);

        // Sheet 2: Sprint Plan (from URI)
        $this->createSprintPlanSheet($spreadsheet, $uri);

        // Sheet 3: Full Issue Register
        $this->createFullIssueRegisterSheet($spreadsheet, $result, $recommendations);

        // Sheet 4: DB Risk Register
        $this->createDbRiskRegisterSheet($spreadsheet, $dbSchema);

        // Sheet 5: Composer Audit
        $this->createComposerAuditSheet($spreadsheet, $composer);

        // Sheet 6: Effort Estimate (BRD §4.12)
        $this->createEffortEstimateSheet($spreadsheet, $uri);

        // Sheet 7: Timeline Projection (BRD §4.12)
        $this->createTimelineProjectionSheet($spreadsheet, $uri);

        // Sheet 8: Module Heatmap (BRD §4.12)
        $this->createModuleHeatmapSheet($spreadsheet, $result);

        // Legacy sheets kept for backwards compatibility
        $this->createIssuesByPrioritySheet($spreadsheet, $result);
        $this->createEffortSheet($spreadsheet, $result);

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }

    private function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HEADER_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
    }

    private function applyRowColor(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range, string $hexColor): void
    {
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF' . $hexColor);
    }

    private function createExecutiveSummarySheet(Spreadsheet $ss, UpgradeAnalysisResult $result, ?URIScore $uri): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Executive Summary');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'ACQC Upgrade Readiness — Executive Summary');
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setSize(16)->setBold(true);
        $row += 2;

        if ($uri !== null) {
            $sheet->setCellValue('A' . $row, 'Upgrade Risk Index (URI)');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13);
            $row++;

            $uriData = [
                'URI Score'            => $uri->score . ' / 100',
                'Grade'                => $uri->grade . ' — ' . $uri->gradeDescription,
                'Engineering Hours'    => $uri->engineeringHours . 'h',
                'QA Hours'             => $uri->qaHours . 'h',
                'Infra Hours'          => $uri->infraHours . 'h',
                'Total w/ Contingency' => $uri->totalHoursWithContingency . 'h',
                'Recommended Timeline' => $uri->recommendedTimeline,
                'Earliest Go-Live'     => $uri->goLiveRecommendation,
            ];

            foreach ($uriData as $label => $value) {
                $sheet->setCellValue('A' . $row, $label);
                $sheet->setCellValue('B' . $row, $value);
                $row++;
            }

            // Color URI score cell by grade
            $gradeColor = match ($uri->grade) {
                'A' => '22c55e', 'B' => '3b82f6', 'C' => 'eab308', 'D' => 'f97316', default => 'ef4444',
            };
            $this->applyRowColor($sheet, 'B' . ($row - 8), $gradeColor);
            $row++;
        }

        // Issue counts
        $sheet->setCellValue('A' . $row, 'Issue Summary');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13);
        $row++;

        $metrics = [
            'AC Version' => $result->getCurrentMagentoVersion()->toString() . ' → ' . $result->getTargetMagentoVersion()->toString(),
            'Total Issues'  => $result->getTotalIssueCount(),
            'Critical'      => $result->getIssueCountByPriority('critical'),
            'High'          => $result->getIssueCountByPriority('high'),
            'Medium'        => $result->getIssueCountByPriority('medium'),
            'Low'           => $result->getIssueCountByPriority('low'),
        ];

        foreach ($metrics as $label => $value) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(40);
        $this->applyHeaderStyle($sheet, 'A1:D1');
        $sheet->freezePane('A2');
    }

    private function createSprintPlanSheet(Spreadsheet $ss, ?URIScore $uri): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Sprint Plan');

        $headers = ['Sprint', 'Sprint Name', 'Finding ID', 'File', 'Message', 'Severity', 'Est Hours', 'Owner'];
        $cols    = range('A', 'H');

        foreach ($headers as $i => $h) {
            $sheet->setCellValue($cols[$i] . '1', $h);
        }
        $this->applyHeaderStyle($sheet, 'A1:H1');
        $sheet->freezePane('A2');

        if ($uri === null) {
            $sheet->setCellValue('A2', 'No URI data — run with all analyzers to generate sprint plan.');
            return;
        }

        $row = 2;
        $id  = 1;
        foreach ($uri->sprintBreakdown as $sprint) {
            if (empty($sprint['findings'])) {
                $sheet->setCellValue('A' . $row, $sprint['sprint']);
                $sheet->setCellValue('B' . $row, $sprint['name']);
                $sheet->setCellValue('C' . $row, '—');
                $sheet->setCellValue('G' . $row, $sprint['total_hours'] ?? 0);
                $row++;
                $id++;
                continue;
            }

            foreach ($sprint['findings'] as $f) {
                $sev  = $f['severity'] ?? 'info';
                $hrs  = isset($f['effort_hours_min'], $f['effort_hours_max'])
                    ? (int)ceil(($f['effort_hours_min'] + $f['effort_hours_max']) / 2)
                    : ($f['effort_hours_min'] ?? 0);

                $sheet->setCellValue('A' . $row, $sprint['sprint']);
                $sheet->setCellValue('B' . $row, $sprint['name']);
                $sheet->setCellValue('C' . $row, 'F-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
                $sheet->setCellValue('D' . $row, basename($f['file'] ?? ''));
                $sheet->setCellValue('E' . $row, $f['message'] ?? '');
                $sheet->setCellValue('F' . $row, ucfirst($sev));
                $sheet->setCellValue('G' . $row, $hrs);
                $sheet->setCellValue('H' . $row, '');

                $bg = match ($sev) { 'error' => self::ROW_ERROR, 'warning' => self::ROW_WARNING, default => '' };
                if ($bg) {
                    $this->applyRowColor($sheet, 'A' . $row . ':H' . $row, $bg);
                }

                $row++;
                $id++;
            }
        }

        foreach (['A' => 8, 'B' => 30, 'C' => 10, 'D' => 30, 'E' => 60, 'F' => 12, 'G' => 12, 'H' => 20] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function createFullIssueRegisterSheet(Spreadsheet $ss, UpgradeAnalysisResult $result, ?array $recommendations = null): void
    {
        $sheet   = $ss->createSheet();
        $sheet->setTitle('Full Issue Register');

        $hasRecs = $recommendations !== null && count($recommendations) > 0;
        $headers = ['ID', 'File', 'Line', 'Module', 'Severity', 'Type', 'Message', 'Est Hours', 'Owner'];
        if ($hasRecs) {
            $headers[] = 'Recommendation';
        }
        $cols = range('A', chr(64 + count($headers)));
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($cols[$i] . '1', $h);
        }
        $lastCol   = $cols[count($headers) - 1];
        $headerRng = 'A1:' . $lastCol . '1';
        $this->applyHeaderStyle($sheet, $headerRng);
        $sheet->freezePane('A2');

        $row = 2;
        $id  = 1;
        foreach ($result->getPrioritizedIssues() as $priority => $issues) {
            foreach ($issues as $issue) {
                $hrs = isset($issue['effort_hours_min']) ? (int)($issue['effort_hours_min']) : 0;

                $sheet->setCellValue('A' . $row, 'F-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
                $sheet->setCellValue('B' . $row, $issue['file'] ?? '');
                $sheet->setCellValue('C' . $row, $issue['line'] ?? '');
                $sheet->setCellValue('D' . $row, $this->extractModule($issue['file'] ?? ''));
                $sheet->setCellValue('E' . $row, ucfirst($priority));
                $sheet->setCellValue('F' . $row, $issue['type'] ?? '');
                $sheet->setCellValue('G' . $row, $issue['message'] ?? '');
                $sheet->setCellValue('H' . $row, $hrs > 0 ? $hrs : '—');
                $sheet->setCellValue('I' . $row, '');

                if ($hasRecs) {
                    $recKey  = $issue['id'] ?? ('acqc_rec_' . sha1(($issue['type'] ?? '') . ':' . ($issue['symbol'] ?? $issue['class'] ?? '') . ':' . ($issue['file'] ?? '')));
                    $recObj  = $recommendations[$recKey] ?? null;
                    if ($recObj instanceof \ACQC\Core\Upgrade\UpgradeRecommendation) {
                        $recText = $recObj->rootCause . "\n\n" . substr($recObj->stepByStep, 0, 200);
                    } else {
                        $recText = '';
                    }
                    $sheet->setCellValue('J' . $row, $recText);
                    $sheet->getStyle('J' . $row)->getAlignment()->setWrapText(true);
                }

                $rowRange = 'A' . $row . ':' . $lastCol . $row;
                if ($priority === 'critical') {
                    $this->applyRowColor($sheet, $rowRange, self::ROW_CRITICAL);
                } elseif ($priority === 'high') {
                    $this->applyRowColor($sheet, $rowRange, self::ROW_ERROR);
                } elseif ($priority === 'medium') {
                    $this->applyRowColor($sheet, $rowRange, self::ROW_WARNING);
                }

                $row++;
                $id++;
            }
        }

        $colWidths = ['A' => 10, 'B' => 50, 'C' => 8, 'D' => 25, 'E' => 10, 'F' => 25, 'G' => 70, 'H' => 10, 'I' => 20];
        if ($hasRecs) {
            $colWidths['J'] = 60;
        }
        foreach ($colWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function createDbRiskRegisterSheet(Spreadsheet $ss, ?DBSchemaAnalysisResult $dbSchema): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('DB Risk Register');

        $headers = ['Table / File', 'Change Type', 'Migration Risk', 'Message', 'Recommendation'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . '1', $h);
        }
        $this->applyHeaderStyle($sheet, 'A1:E1');
        $sheet->freezePane('A2');

        if ($dbSchema === null || empty($dbSchema->findings)) {
            $sheet->setCellValue('A2', 'No DB schema findings. Run with --include-database for full analysis.');
            return;
        }

        $row = 2;
        foreach ($dbSchema->findings as $f) {
            $sev = $f['severity'] ?? 'info';
            $sheet->setCellValue('A' . $row, $f['table'] ?? basename($f['file'] ?? '—'));
            $sheet->setCellValue('B' . $row, $f['type'] ?? 'schema');
            $sheet->setCellValue('C' . $row, ucfirst($sev));
            $sheet->setCellValue('D' . $row, $f['message'] ?? '');
            $sheet->setCellValue('E' . $row, $f['remediation'] ?? $f['fix'] ?? '—');

            if ($sev === 'error') {
                $this->applyRowColor($sheet, 'A' . $row . ':E' . $row, self::ROW_ERROR);
            } elseif ($sev === 'warning') {
                $this->applyRowColor($sheet, 'A' . $row . ':E' . $row, self::ROW_WARNING);
            }
            $row++;
        }

        foreach (['A' => 30, 'B' => 20, 'C' => 15, 'D' => 60, 'E' => 40] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function createComposerAuditSheet(Spreadsheet $ss, ?ComposerAnalysisResult $composer): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Composer Audit');

        $headers = ['Package', 'Issue Type', 'Installed Version', 'Fix / Replacement'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . '1', $h);
        }
        $this->applyHeaderStyle($sheet, 'A1:D1');
        $sheet->freezePane('A2');

        if ($composer === null || empty($composer->findings)) {
            $sheet->setCellValue('A2', 'No Composer findings. Run with --composer-deep to enable Composer audit.');
            return;
        }

        $row = 2;
        foreach ($composer->findings as $f) {
            $sheet->setCellValue('A' . $row, $f['package'] ?? '');
            $sheet->setCellValue('B' . $row, $f['type'] ?? '');
            $sheet->setCellValue('C' . $row, '—');
            $sheet->setCellValue('D' . $row, $f['message'] ?? '');

            if (($f['severity'] ?? '') === 'error') {
                $this->applyRowColor($sheet, 'A' . $row . ':D' . $row, self::ROW_ERROR);
            } elseif (($f['severity'] ?? '') === 'warning') {
                $this->applyRowColor($sheet, 'A' . $row . ':D' . $row, self::ROW_WARNING);
            }
            $row++;
        }

        foreach (['A' => 40, 'B' => 30, 'C' => 20, 'D' => 70] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function extractModule(string $filePath): string
    {
        if (preg_match('#app/code/([^/]+/[^/]+)#', $filePath, $m)) {
            return $m[1];
        }
        if (preg_match('#vendor/([^/]+/[^/]+)#', $filePath, $m)) {
            return $m[1];
        }
        return basename(dirname($filePath));
    }
    
    /**
     * Create summary sheet
     */
    private function createSummarySheet(Spreadsheet $spreadsheet, UpgradeAnalysisResult $result): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Summary');
        
        // Title
        $sheet->setCellValue('A1', 'Upgrade Readiness Report');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Version Info
        $row = 3;
        $sheet->setCellValue('A' . $row, 'Version Information');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Current Magento');
        $sheet->setCellValue('B' . $row, $result->getCurrentMagentoVersion()->toString());
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Target Magento');
        $sheet->setCellValue('B' . $row, $result->getTargetMagentoVersion()->toString());
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Current PHP');
        $sheet->setCellValue('B' . $row, $result->getCurrentPhpVersion()->toString());
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Target PHP');
        $sheet->setCellValue('B' . $row, $result->getTargetPhpVersion()->toString());
        $row += 2;
        
        // Summary Metrics
        $sheet->setCellValue('A' . $row, 'Summary Metrics');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $row++;
        
        $metrics = [
            'Readiness Score' => $result->getUpgradeReadinessScore() . '/100',
            'Risk Level' => $result->getUpgradeRiskLevel(),
            'Upgrade Possible' => $result->isUpgradePossible() ? 'Yes' : 'No',
            'Total Issues' => $result->getTotalIssueCount(),
            'Critical Issues' => $result->getIssueCountByPriority('critical'),
            'High Priority' => $result->getIssueCountByPriority('high'),
            'Medium Priority' => $result->getIssueCountByPriority('medium'),
            'Low Priority' => $result->getIssueCountByPriority('low'),
        ];
        
        foreach ($metrics as $label => $value) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            
            // Color code based on metric
            if ($label === 'Readiness Score') {
                $score = $result->getUpgradeReadinessScore();
                $color = $score >= 80 ? '00FF00' : ($score >= 60 ? 'FFFF00' : 'FF0000');
                $sheet->getStyle('B' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($color);
            }
            
            $row++;
        }
        
        // Effort Estimation
        $effort = $result->getEffortEstimation();
        if (!empty($effort)) {
            $row += 2;
            $sheet->setCellValue('A' . $row, 'Effort Estimation');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
            $row++;
            
            $effortMetrics = [
                'Total Hours' => $effort['total_hours'] ?? 0,
                'Development' => $effort['development_hours'] ?? 0,
                'Testing' => $effort['testing_hours'] ?? 0,
                'Review' => $effort['review_hours'] ?? 0,
                'Buffer' => $effort['buffer_hours'] ?? 0,
                'Total Cost' => '$' . number_format($effort['total_cost_usd'] ?? 0, 2),
                'Timeline (Days)' => $effort['timeline_days'] ?? 0,
                'Timeline (Weeks)' => $effort['timeline_weeks'] ?? 0,
            ];
            
            foreach ($effortMetrics as $label => $value) {
                $sheet->setCellValue('A' . $row, $label);
                $sheet->setCellValue('B' . $row, $value);
                $row++;
            }
        }
        
        // Column widths
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(20);
        
        // Borders
        $sheet->getStyle('A3:B' . ($row - 1))->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
    }
    
    /**
     * Create issues by priority sheet
     */
    private function createIssuesByPrioritySheet(Spreadsheet $spreadsheet, UpgradeAnalysisResult $result): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Issues by Priority');
        
        // Headers
        $headers = ['Priority', 'Count', 'Percentage'];
        $cols = ['A', 'B', 'C'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($cols[$index] . '1', $header);
            $sheet->getStyle($cols[$index] . '1')->getFont()->setBold(true);
        }
        
        // Data
        $priorities = ['critical', 'high', 'medium', 'low'];
        $total = $result->getTotalIssueCount();
        $row = 2;
        
        foreach ($priorities as $priority) {
            $count = $result->getIssueCountByPriority($priority);
            $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
            
            $sheet->setCellValue('A' . $row, ucfirst($priority));
            $sheet->setCellValue('B' . $row, $count);
            $sheet->setCellValue('C' . $row, $percentage . '%');
            
            // Color code
            $colors = [
                'critical' => 'FF0000',
                'high' => 'FFA500',
                'medium' => 'FFFF00',
                'low' => '00FF00',
            ];
            
            $sheet->getStyle('A' . $row)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($colors[$priority]);
            
            $row++;
        }
        
        // Auto-size columns
        foreach ($cols as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    /**
     * Create all issues sheet
     */
    private function createAllIssuesSheet(Spreadsheet $spreadsheet, UpgradeAnalysisResult $result): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('All Issues');
        
        // Headers
        $headers = ['Priority', 'Type', 'File', 'Line', 'Message', 'Fix'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($cols[$index] . '1', $header);
            $sheet->getStyle($cols[$index] . '1')->getFont()->setBold(true);
        }
        
        // Collect all issues
        $allIssues = [];
        
        foreach ($result->getPrioritizedIssues() as $priority => $issues) {
            foreach ($issues as $issue) {
                $allIssues[] = array_merge(['priority' => $priority], $issue);
            }
        }
        
        // Data
        $row = 2;
        foreach ($allIssues as $issue) {
            $sheet->setCellValue('A' . $row, ucfirst($issue['priority'] ?? 'unknown'));
            $sheet->setCellValue('B' . $row, $issue['type'] ?? '');
            $sheet->setCellValue('C' . $row, basename($issue['file'] ?? ''));
            $sheet->setCellValue('D' . $row, $issue['line'] ?? '');
            $sheet->setCellValue('E' . $row, $issue['message'] ?? '');
            $sheet->setCellValue('F' . $row, $issue['fix'] ?? '');
            
            $row++;
        }
        
        // Auto-size columns
        foreach ($cols as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Freeze first row
        $sheet->freezePane('A2');
    }
    
    /**
     * Create effort breakdown sheet
     */
    private function createEffortSheet(Spreadsheet $spreadsheet, UpgradeAnalysisResult $result): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Effort Breakdown');
        
        $effort = $result->getEffortEstimation();
        if (empty($effort)) {
            $sheet->setCellValue('A1', 'No effort estimation available');
            return;
        }
        
        // Headers
        $sheet->setCellValue('A1', 'Component');
        $sheet->setCellValue('B1', 'Hours');
        $sheet->setCellValue('C1', 'Percentage');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        
        // Data
        $components = [
            'Development' => $effort['development_hours'] ?? 0,
            'Testing' => $effort['testing_hours'] ?? 0,
            'Code Review' => $effort['review_hours'] ?? 0,
            'Buffer' => $effort['buffer_hours'] ?? 0,
        ];
        
        $total = $effort['total_hours'] ?? 1;
        $row = 2;
        
        foreach ($components as $component => $hours) {
            $percentage = round(($hours / $total) * 100, 1);
            
            $sheet->setCellValue('A' . $row, $component);
            $sheet->setCellValue('B' . $row, $hours);
            $sheet->setCellValue('C' . $row, $percentage . '%');
            
            $row++;
        }
        
        // Total
        $sheet->setCellValue('A' . $row, 'Total');
        $sheet->setCellValue('B' . $row, $total);
        $sheet->setCellValue('C' . $row, '100%');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        
        // Priority breakdown
        if (isset($effort['breakdown'])) {
            $row += 3;
            $sheet->setCellValue('A' . $row, 'Breakdown by Priority');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
            $row++;
            
            $sheet->setCellValue('A' . $row, 'Priority');
            $sheet->setCellValue('B' . $row, 'Count');
            $sheet->setCellValue('C' . $row, 'Hours');
            $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
            $row++;
            
            foreach ($effort['breakdown'] as $priority => $data) {
                $sheet->setCellValue('A' . $row, ucfirst($priority));
                $sheet->setCellValue('B' . $row, $data['count'] ?? 0);
                $sheet->setCellValue('C' . $row, $data['development_time'] ?? 0);
                $row++;
            }
        }
        
        // Auto-size columns
        for ($col = 1; $col <= 3; $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
    }

    private function createEffortEstimateSheet(Spreadsheet $ss, ?URIScore $uri): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Effort Estimate');

        $sheet->setCellValue('A1', 'Effort Estimate');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->applyHeaderStyle($sheet, 'A1:C1');
        $sheet->freezePane('A2');

        $row = 2;
        $headers = ['Category', 'Hours', 'Notes'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . $row, $h);
            $sheet->getStyle(chr(65 + $i) . $row)->getFont()->setBold(true);
        }
        $row++;

        if ($uri === null) {
            $sheet->setCellValue('A' . $row, 'Run with full analysis to generate effort estimate.');
            return;
        }

        $data = [
            ['Engineering', $uri->engineeringHours, 'Code remediation, refactoring, and migration'],
            ['QA / Testing', $uri->qaHours,         'Test plan execution, regression, and UAT'],
            ['Infrastructure', $uri->infraHours,    'Env setup, staging, pipeline updates'],
        ];

        $subtotal = $uri->engineeringHours + $uri->qaHours + $uri->infraHours;
        $contingency = $uri->totalHoursWithContingency - $subtotal;

        foreach ($data as [$cat, $hrs, $note]) {
            $sheet->setCellValue('A' . $row, $cat);
            $sheet->setCellValue('B' . $row, $hrs);
            $sheet->setCellValue('C' . $row, $note);
            $row++;
        }

        $sheet->setCellValue('A' . $row, 'Contingency (25%)');
        $sheet->setCellValue('B' . $row, $contingency);
        $sheet->setCellValue('C' . $row, 'Buffer for unknowns and re-work');
        $row++;

        $sheet->setCellValue('A' . $row, 'Total');
        $sheet->setCellValue('B' . $row, $uri->totalHoursWithContingency);
        $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);

        foreach (['A' => 25, 'B' => 12, 'C' => 50] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function createTimelineProjectionSheet(Spreadsheet $ss, ?URIScore $uri): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Timeline Projection');

        $sheet->setCellValue('A1', 'Timeline Projection');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->applyHeaderStyle($sheet, 'A1:C1');
        $sheet->freezePane('A2');

        $row = 2;

        if ($uri === null) {
            $sheet->setCellValue('A' . $row, 'Run with full analysis to generate timeline projection.');
            return;
        }

        // Summary block
        $summary = [
            'Recommended Timeline' => $uri->recommendedTimeline,
            'Earliest Go-Live'     => $uri->goLiveRecommendation,
            'URI Grade'            => $uri->grade . ' — ' . $uri->gradeDescription,
            'Total Hours (w/ contingency)' => $uri->totalHoursWithContingency . 'h',
        ];

        foreach ($summary as $label => $value) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            $row++;
        }

        $row++;

        // Sprint breakdown
        if (!empty($uri->sprintBreakdown)) {
            $sheet->setCellValue('A' . $row, 'Sprint Plan');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
            $row++;

            $headers = ['Sprint', 'Name', 'Findings', 'Hours'];
            foreach ($headers as $i => $h) {
                $sheet->setCellValue(chr(65 + $i) . $row, $h);
                $sheet->getStyle(chr(65 + $i) . $row)->getFont()->setBold(true);
            }
            $row++;

            foreach ($uri->sprintBreakdown as $sprint) {
                $sheet->setCellValue('A' . $row, $sprint['sprint']);
                $sheet->setCellValue('B' . $row, $sprint['name']);
                $sheet->setCellValue('C' . $row, count($sprint['findings'] ?? []));
                $sheet->setCellValue('D' . $row, $sprint['total_hours'] ?? 0);
                $row++;
            }
        }

        foreach (['A' => 30, 'B' => 30, 'C' => 12, 'D' => 12] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function createModuleHeatmapSheet(Spreadsheet $ss, UpgradeAnalysisResult $result): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Module Heatmap');

        $sheet->setCellValue('A1', 'Module Risk Heatmap');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->applyHeaderStyle($sheet, 'A1:E1');
        $sheet->freezePane('A2');

        $row = 2;
        $headers = ['Module', 'Total Findings', 'Errors', 'Warnings', 'Est. Hours'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . $row, $h);
            $sheet->getStyle(chr(65 + $i) . $row)->getFont()->setBold(true);
        }
        $row++;

        // Aggregate findings by module
        $moduleMap = [];
        foreach ($result->getPrioritizedIssues() as $priority => $issues) {
            foreach ($issues as $issue) {
                $module = $this->extractModule($issue['file'] ?? '');
                if (!isset($moduleMap[$module])) {
                    $moduleMap[$module] = ['total' => 0, 'errors' => 0, 'warnings' => 0, 'hours' => 0];
                }
                $moduleMap[$module]['total']++;
                $sev = $issue['severity'] ?? $priority;
                if (in_array($sev, ['error', 'critical'], true)) {
                    $moduleMap[$module]['errors']++;
                } elseif ($sev === 'warning') {
                    $moduleMap[$module]['warnings']++;
                }
                $moduleMap[$module]['hours'] += (int)(($issue['effort_hours_min'] ?? 0) + ($issue['effort_hours_max'] ?? 0)) / 2;
            }
        }

        // Sort by total findings descending
        arsort($moduleMap);

        foreach ($moduleMap as $module => $stats) {
            $sheet->setCellValue('A' . $row, $module);
            $sheet->setCellValue('B' . $row, $stats['total']);
            $sheet->setCellValue('C' . $row, $stats['errors']);
            $sheet->setCellValue('D' . $row, $stats['warnings']);
            $sheet->setCellValue('E' . $row, (int)$stats['hours']);

            // Color by risk
            $range = 'A' . $row . ':E' . $row;
            if ($stats['errors'] > 5) {
                $this->applyRowColor($sheet, $range, self::ROW_CRITICAL);
            } elseif ($stats['errors'] > 0) {
                $this->applyRowColor($sheet, $range, self::ROW_ERROR);
            } elseif ($stats['warnings'] > 0) {
                $this->applyRowColor($sheet, $range, self::ROW_WARNING);
            }

            $row++;
        }

        if ($row === 3) {
            $sheet->setCellValue('A' . $row, 'No module findings. Run a full upgrade analysis to populate this sheet.');
        }

        foreach (['A' => 35, 'B' => 15, 'C' => 12, 'D' => 12, 'E' => 12] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }
}

