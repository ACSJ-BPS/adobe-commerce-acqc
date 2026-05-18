<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

use ACQC\Core\ProjectQuality\SprintMetrics;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Project Quality Report Generator
 * 
 * Generates Excel reports matching the Commerce_project_updates.xlsx template
 * for leadership reporting.
 */
class ProjectQualityReportGenerator
{
    private LoggerInterface $logger;
    private array $config;
    private array $colors;

    public function __construct(array $config = [], LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = $config;
        $this->initializeColors();
    }

    /**
     * Generate Excel report
     */
    public function generate(string $outputPath, array $data): bool
    {
        try {
            $this->logger->info('Generating project quality report', ['output' => $outputPath]);
            
            $spreadsheet = new Spreadsheet();
            
            // Create Summary sheet
            $this->createSummarySheet($spreadsheet, $data);
            
            // Create Project_template sheet
            $this->createProjectTemplateSheet($spreadsheet, $data);
            
            // Set active sheet to Project_template
            $spreadsheet->setActiveSheetIndex(1);
            
            // Write to file
            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);
            
            $this->logger->info('Report generated successfully', ['path' => $outputPath]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate report', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create Summary sheet
     */
    private function createSummarySheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        
        // Headers (Row 2)
        $sheet->setCellValue('A2', 'Project Name');
        $sheet->setCellValue('B2', 'Region');
        $sheet->setCellValue('C2', 'Status');
        $sheet->setCellValue('D2', 'Link');
        
        // Apply header formatting
        $this->applyHeaderStyle($sheet, 'A2:D2');
        
        // Data (Row 3)
        $projectInfo = $data['project_info'] ?? [];
        $summary = $data['summary'] ?? [];
        
        $sheet->setCellValue('A3', $projectInfo['name'] ?? 'Unknown');
        $sheet->setCellValue('B3', $projectInfo['region'] ?? 'EMEA');
        $sheet->setCellValue('C3', strtoupper($summary['overall_health'] ?? 'UNKNOWN'));
        $sheet->setCellValue('D3', 'Internal'); // Default link
        
        // Apply status color
        $status = $summary['overall_health'] ?? 'unknown';
        $this->applyStatusColor($sheet, 'C3', $status);
        
        // Auto-size columns
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Create Project_template sheet
     */
    private function createProjectTemplateSheet(Spreadsheet $spreadsheet, array $data): void
    {
        // Add new sheet
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Project_template');
        
        $projectInfo = $data['project_info'] ?? [];
        $discovery = $data['discovery_stage'] ?? [];
        $design = $data['design_stage'] ?? [];
        $sprints = $data['sprints'] ?? [];
        
        // Project Information Section (Rows 2-9)
        $this->populateProjectInfo($sheet, $projectInfo);
        
        // Empty row
        $row = 11;
        
        // Quality Stages Header (Row 12)
        $sheet->setCellValue('A12', 'Stages');
        $sheet->setCellValue('B12', 'Criteria');
        $sheet->setCellValue('C12', 'Actuals');
        $sheet->setCellValue('D12', 'Details');
        $this->applyHeaderStyle($sheet, 'A12:D12');
        
        $row = 13;
        
        // Discovery Stage
        $row = $this->populateStageRow($sheet, $row, 'Discovery', $discovery);
        
        // Design Stage
        $row = $this->populateStageRow($sheet, $row, 'Design', $design);
        
        // Sprint Metrics
        $row = $this->populateSprintMetrics($sheet, $row, $sprints);
        
        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(50);
    }

    /**
     * Populate project information section
     */
    private function populateProjectInfo(Worksheet $sheet, array $projectInfo): void
    {
        // Row 2: Project Name
        $sheet->setCellValue('A2', 'Project Name');
        $sheet->setCellValue('B2', $projectInfo['name'] ?? 'Unknown Project');
        $this->applyLabelStyle($sheet, 'A2');
        
        // Row 3: Solutions
        $sheet->setCellValue('A3', 'Solutions');
        $sheet->setCellValue('B3', $projectInfo['solutions'] ?? 'Adobe Commerce');
        $this->applyLabelStyle($sheet, 'A3');
        
        // Row 4: Description
        $sheet->setCellValue('A4', 'Brief description');
        $sheet->setCellValue('B4', $projectInfo['description'] ?? '');
        $this->applyLabelStyle($sheet, 'A4');
        
        // Row 5: Kind of project
        $sheet->setCellValue('A5', 'Kind of project');
        $sheet->setCellValue('B5', $projectInfo['kind'] ?? 'Implementation');
        $this->applyLabelStyle($sheet, 'A5');
        
        // Row 6: # of resources
        $resources = $projectInfo['resources'] ?? [];
        $resourceCount = $resources['count'] ?? 0;
        $resourceNames = !empty($resources['names']) ? ' [' . implode(', ', $resources['names']) . ']' : '';
        $sheet->setCellValue('A6', '# of resources');
        $sheet->setCellValue('B6', $resourceCount . $resourceNames);
        $this->applyLabelStyle($sheet, 'A6');
        
        // Row 7: # of hours / %QA
        $hours = $projectInfo['hours'] ?? 0;
        $qaPercentage = $projectInfo['qa_percentage'] ?? 20;
        $sheet->setCellValue('A7', '# of hours / %QA');
        $sheet->setCellValue('B7', sprintf('%d hours / %d%% QA', $hours, $qaPercentage));
        $this->applyLabelStyle($sheet, 'A7');
        
        // Row 8: # of Requirements
        $sheet->setCellValue('A8', '# of Requirements');
        $sheet->setCellValue('B8', $projectInfo['requirements'] ?? 0);
        $this->applyLabelStyle($sheet, 'A8');
        
        // Row 9: # of Test Cases
        $sheet->setCellValue('A9', '# of Test Cases');
        $sheet->setCellValue('B9', $projectInfo['test_cases'] ?? 0);
        $this->applyLabelStyle($sheet, 'A9');
    }

    /**
     * Populate a stage row (Discovery or Design)
     */
    private function populateStageRow(Worksheet $sheet, int $row, string $stage, array $stageData): int
    {
        $sheet->setCellValue("A{$row}", $stage);
        $sheet->setCellValue("B{$row}", $stageData['criteria'] ?? '');
        $sheet->setCellValue("C{$row}", sprintf('%.0f%%', $stageData['actual'] ?? 0));
        $sheet->setCellValue("D{$row}", $stageData['details'] ?? '');
        
        // Apply stage styling
        $this->applyStageStyle($sheet, "A{$row}:D{$row}");
        
        // Apply status color to Actuals column
        $status = $stageData['status'] ?? 'unknown';
        $this->applyStatusColor($sheet, "C{$row}", $status);
        
        return $row + 1;
    }

    /**
     * Populate sprint metrics rows
     */
    private function populateSprintMetrics(Worksheet $sheet, int $startRow, array $sprints): int
    {
        $row = $startRow;
        
        foreach ($sprints as $sprint) {
            if ($sprint instanceof SprintMetrics) {
                $sprintData = $sprint->toArray();
            } else {
                $sprintData = $sprint;
            }
            
            $sprintNum = $sprintData['sprint_number'] ?? 1;
            
            // Row 1: Unit test coverage
            $sheet->setCellValue("A{$row}", "Sprint {$sprintNum}");
            $sheet->setCellValue("B{$row}", 'Unit test coverage %');
            $sheet->setCellValue("C{$row}", sprintf('%.1f%%', $sprintData['test_coverage'] ?? 0));
            $sheet->setCellValue("D{$row}", sprintf(
                'Coverage: %.1f%% (%s)',
                $sprintData['test_coverage'] ?? 0,
                $sprintData['test_coverage_status'] ?? 'unknown'
            ));
            $this->applyStatusColor($sheet, "C{$row}", $sprintData['test_coverage_status'] ?? 'unknown');
            $row++;
            
            // Row 2: Code quality
            $sheet->setCellValue("A{$row}", '');
            $sheet->setCellValue("B{$row}", 'Code quality');
            $sheet->setCellValue("C{$row}", sprintf('%.1f/100', $sprintData['code_quality'] ?? 0));
            $sheet->setCellValue("D{$row}", sprintf(
                'Score: %.1f, Issues: %d (%d critical)',
                $sprintData['code_quality'] ?? 0,
                $sprintData['total_issues'] ?? 0,
                $sprintData['critical_issues'] ?? 0
            ));
            $this->applyStatusColor($sheet, "C{$row}", $sprintData['code_quality_status'] ?? 'unknown');
            $row++;
            
            // Row 3: Effort deviation
            $sheet->setCellValue("A{$row}", '');
            $sheet->setCellValue("B{$row}", 'Effort deviation');
            $deviation = $sprintData['effort_deviation'] ?? 0;
            $sheet->setCellValue("C{$row}", sprintf('%+.1f%%', $deviation));
            $sheet->setCellValue("D{$row}", sprintf(
                'Deviation: %+.1f%% (%s)',
                $deviation,
                $sprintData['effort_deviation_status'] ?? 'unknown'
            ));
            $this->applyStatusColor($sheet, "C{$row}", $sprintData['effort_deviation_status'] ?? 'unknown');
            $row++;
            
            // Row 4: Velocity/Rework
            $sheet->setCellValue("A{$row}", '');
            $sheet->setCellValue("B{$row}", 'Velocity/Rework');
            $sheet->setCellValue("C{$row}", sprintf('%.1f%%', $sprintData['velocity'] ?? 0));
            $sheet->setCellValue("D{$row}", sprintf(
                'Velocity: %.1f%% (%s)',
                $sprintData['velocity'] ?? 0,
                $sprintData['velocity_status'] ?? 'unknown'
            ));
            $this->applyStatusColor($sheet, "C{$row}", $sprintData['velocity_status'] ?? 'unknown');
            $row++;
            
            // Row 5: Defect density
            $sheet->setCellValue("A{$row}", '');
            $sheet->setCellValue("B{$row}", 'Defect density');
            $sheet->setCellValue("C{$row}", sprintf('%.2f', $sprintData['defect_density'] ?? 0));
            $sheet->setCellValue("D{$row}", sprintf(
                'Defects per 1K LOC: %.2f (%s)',
                $sprintData['defect_density'] ?? 0,
                $sprintData['defect_density_status'] ?? 'unknown'
            ));
            $this->applyStatusColor($sheet, "C{$row}", $sprintData['defect_density_status'] ?? 'unknown');
            $row++;
            
            // Apply sprint row styling
            $sprintStartRow = $row - 5;
            $this->applySprintStyle($sheet, $sprintStartRow, $row - 1);
        }
        
        return $row;
    }

    /**
     * Initialize color palette from config
     */
    private function initializeColors(): void
    {
        $configColors = $this->config['styling']['colors'] ?? [];
        
        $this->colors = [
            'header' => $configColors['header'] ?? '4472C4',
            'section' => $configColors['section'] ?? 'D9E2F3',
            'stage' => $configColors['stage'] ?? 'F2F2F2',
            'excellent' => $configColors['excellent'] ?? '00B050',
            'good' => $configColors['good'] ?? '92D050',
            'warning' => $configColors['warning'] ?? 'FFC000',
            'critical' => $configColors['critical'] ?? 'FF0000',
        ];
    }

    /**
     * Apply header style
     */
    private function applyHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $this->colors['header']]
            ],
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);
    }

    /**
     * Apply label style (for project info labels)
     */
    private function applyLabelStyle(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $this->colors['section']]
            ]
        ]);
    }

    /**
     * Apply stage style
     */
    private function applyStageStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $this->colors['stage']]
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC']
                ]
            ]
        ]);
    }

    /**
     * Apply sprint style
     */
    private function applySprintStyle(Worksheet $sheet, int $startRow, int $endRow): void
    {
        // Add subtle border around sprint block
        $range = "A{$startRow}:D{$endRow}";
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '999999']
                ],
                'inside' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'DDDDDD']
                ]
            ]
        ]);
        
        // Bold the sprint label
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);
    }

    /**
     * Apply status color based on metric status
     */
    private function applyStatusColor(Worksheet $sheet, string $cell, string $status): void
    {
        $colorMap = [
            'excellent' => $this->colors['excellent'],
            'good' => $this->colors['good'],
            'warning' => $this->colors['warning'],
            'critical' => $this->colors['critical'],
            'stable' => $this->colors['good'],
            'improving' => $this->colors['excellent'],
            'declining' => $this->colors['warning']
        ];
        
        $color = $colorMap[$status] ?? 'FFFFFF';
        
        $sheet->getStyle($cell)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $color]
            ],
            'font' => [
                'bold' => true,
                'color' => ['rgb' => $this->getFontColor($color)]
            ]
        ]);
    }

    /**
     * Get appropriate font color for background
     */
    private function getFontColor(string $bgColor): string
    {
        // Use white text for dark backgrounds
        $darkColors = ['4472C4', 'FF0000'];
        return in_array($bgColor, $darkColors) ? 'FFFFFF' : '000000';
    }

    /**
     * Generate report for multiple projects
     */
    public function generateMultiProject(string $outputPath, array $projects): bool
    {
        try {
            $spreadsheet = new Spreadsheet();
            
            // Create Summary sheet with all projects
            $this->createMultiProjectSummary($spreadsheet, $projects);
            
            // Create a sheet for each project
            foreach ($projects as $index => $project) {
                $sheet = $spreadsheet->createSheet($index + 1);
                $projectName = $project['project_info']['name'] ?? "Project_" . ($index + 1);
                $sheet->setTitle(substr($projectName, 0, 31)); // Excel limit
                
                $this->populateProjectTemplateSheet($sheet, $project);
            }
            
            // Set active sheet to Summary
            $spreadsheet->setActiveSheetIndex(0);
            
            // Write to file
            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate multi-project report', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create summary sheet for multiple projects
     */
    private function createMultiProjectSummary(Spreadsheet $spreadsheet, array $projects): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        
        // Headers
        $sheet->setCellValue('A2', 'Project Name');
        $sheet->setCellValue('B2', 'Region');
        $sheet->setCellValue('C2', 'Status');
        $sheet->setCellValue('D2', 'Link');
        $this->applyHeaderStyle($sheet, 'A2:D2');
        
        // Data rows
        $row = 3;
        foreach ($projects as $project) {
            $projectInfo = $project['project_info'] ?? [];
            $summary = $project['summary'] ?? [];
            
            $sheet->setCellValue("A{$row}", $projectInfo['name'] ?? 'Unknown');
            $sheet->setCellValue("B{$row}", $projectInfo['region'] ?? 'EMEA');
            $sheet->setCellValue("C{$row}", strtoupper($summary['overall_health'] ?? 'UNKNOWN'));
            $sheet->setCellValue("D{$row}", 'Internal');
            
            $status = $summary['overall_health'] ?? 'unknown';
            $this->applyStatusColor($sheet, "C{$row}", $status);
            
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Populate project template on existing sheet
     */
    private function populateProjectTemplateSheet(Worksheet $sheet, array $data): void
    {
        $projectInfo = $data['project_info'] ?? [];
        $discovery = $data['discovery_stage'] ?? [];
        $design = $data['design_stage'] ?? [];
        $sprints = $data['sprints'] ?? [];
        
        // Project Information Section
        $this->populateProjectInfo($sheet, $projectInfo);
        
        // Quality Stages Header (Row 12)
        $sheet->setCellValue('A12', 'Stages');
        $sheet->setCellValue('B12', 'Criteria');
        $sheet->setCellValue('C12', 'Actuals');
        $sheet->setCellValue('D12', 'Details');
        $this->applyHeaderStyle($sheet, 'A12:D12');
        
        $row = 13;
        
        // Discovery Stage
        $row = $this->populateStageRow($sheet, $row, 'Discovery', $discovery);
        
        // Design Stage
        $row = $this->populateStageRow($sheet, $row, 'Design', $design);
        
        // Sprint Metrics
        $this->populateSprintMetrics($sheet, $row, $sprints);
        
        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(50);
    }
}
