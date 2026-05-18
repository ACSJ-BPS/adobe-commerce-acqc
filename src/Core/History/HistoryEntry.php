<?php
declare(strict_types=1);
namespace ACQC\Core\History;

class HistoryEntry
{
    public function __construct(
        public readonly ?int    $id,
        public readonly string  $projectPath,
        public readonly string  $projectName,
        public readonly string  $command,
        public readonly float   $score,
        public readonly int     $totalViolations,
        public readonly int     $criticalViolations,
        public readonly int     $filesAnalyzed,
        public readonly float   $lineCoverage,
        public readonly array   $metadata,
        public readonly string  $createdAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:                 (int)$row['id'],
            projectPath:        $row['project_path'],
            projectName:        $row['project_name'],
            command:            $row['command'],
            score:              (float)$row['score'],
            totalViolations:    (int)$row['total_violations'],
            criticalViolations: (int)$row['critical_violations'],
            filesAnalyzed:      (int)$row['files_analyzed'],
            lineCoverage:       (float)$row['line_coverage'],
            metadata:           json_decode($row['metadata'] ?? '{}', true) ?? [],
            createdAt:          $row['created_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'project_path'        => $this->projectPath,
            'project_name'        => $this->projectName,
            'command'             => $this->command,
            'score'               => $this->score,
            'total_violations'    => $this->totalViolations,
            'critical_violations' => $this->criticalViolations,
            'files_analyzed'      => $this->filesAnalyzed,
            'line_coverage'       => $this->lineCoverage,
            'metadata'            => $this->metadata,
            'created_at'          => $this->createdAt,
        ];
    }
}
