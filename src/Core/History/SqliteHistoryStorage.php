<?php
declare(strict_types=1);
namespace ACQC\Core\History;

use \PDO;
use \PDOException;

class SqliteHistoryStorage implements HistoryStorage
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dbPath = '')
    {
        if ($dbPath === '') {
            $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? sys_get_temp_dir();
            $dir = $home . '/.acqc';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $dbPath = $dir . '/history.sqlite';
        }
        $this->dbPath = $dbPath;
        $this->pdo = new PDO('sqlite:' . $dbPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->initialize();
    }

    private function initialize(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS scan_history (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                project_path        TEXT NOT NULL,
                project_name        TEXT NOT NULL DEFAULT '',
                command             TEXT NOT NULL DEFAULT 'report',
                score               REAL NOT NULL DEFAULT 0,
                total_violations    INTEGER NOT NULL DEFAULT 0,
                critical_violations INTEGER NOT NULL DEFAULT 0,
                files_analyzed      INTEGER NOT NULL DEFAULT 0,
                line_coverage       REAL NOT NULL DEFAULT 0,
                metadata            TEXT NOT NULL DEFAULT '{}',
                created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_history_project ON scan_history(project_path);
            CREATE INDEX IF NOT EXISTS idx_history_created ON scan_history(created_at);
        SQL);
    }

    public function save(HistoryEntry $entry): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO scan_history
                (project_path, project_name, command, score, total_violations, critical_violations,
                 files_analyzed, line_coverage, metadata, created_at)
            VALUES
                (:project_path, :project_name, :command, :score, :total_violations, :critical_violations,
                 :files_analyzed, :line_coverage, :metadata, :created_at)
        SQL);
        $stmt->execute([
            ':project_path'        => $entry->projectPath,
            ':project_name'        => $entry->projectName,
            ':command'             => $entry->command,
            ':score'               => $entry->score,
            ':total_violations'    => $entry->totalViolations,
            ':critical_violations' => $entry->criticalViolations,
            ':files_analyzed'      => $entry->filesAnalyzed,
            ':line_coverage'       => $entry->lineCoverage,
            ':metadata'            => json_encode($entry->metadata),
            ':created_at'          => $entry->createdAt ?: date('Y-m-d H:i:s'),
        ]);
    }

    public function findAll(string $projectPath = '', int $limit = 50): array
    {
        if ($projectPath !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM scan_history WHERE project_path = :p ORDER BY created_at DESC LIMIT :l'
            );
            $stmt->bindValue(':p', $projectPath);
            $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM scan_history ORDER BY created_at DESC LIMIT :l'
            );
            $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return array_map([HistoryEntry::class, 'fromArray'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?HistoryEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scan_history WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? HistoryEntry::fromArray($row) : null;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM scan_history WHERE id = :id')->execute([':id' => $id]);
    }

    public function clear(string $projectPath = ''): void
    {
        if ($projectPath !== '') {
            $this->pdo->prepare('DELETE FROM scan_history WHERE project_path = :p')->execute([':p' => $projectPath]);
        } else {
            $this->pdo->exec('DELETE FROM scan_history');
        }
    }

    public function compare(int $idA, int $idB): array
    {
        $a = $this->findById($idA);
        $b = $this->findById($idB);
        if (!$a || !$b) {
            throw new \InvalidArgumentException("One or both history entries not found (ids: $idA, $idB)");
        }

        $scoreDelta      = round($b->score - $a->score, 2);
        $violationsDelta = $b->totalViolations - $a->totalViolations;
        $criticalDelta   = $b->criticalViolations - $a->criticalViolations;
        $coverageDelta   = round($b->lineCoverage - $a->lineCoverage, 2);

        return [
            'entry_a' => $a->toArray(),
            'entry_b' => $b->toArray(),
            'delta' => [
                'score'               => $scoreDelta,
                'total_violations'    => $violationsDelta,
                'critical_violations' => $criticalDelta,
                'line_coverage'       => $coverageDelta,
                'files_analyzed'      => $b->filesAnalyzed - $a->filesAnalyzed,
            ],
            'trend' => [
                'score'      => $scoreDelta > 0 ? 'improved' : ($scoreDelta < 0 ? 'degraded' : 'stable'),
                'violations' => $violationsDelta < 0 ? 'improved' : ($violationsDelta > 0 ? 'degraded' : 'stable'),
                'coverage'   => $coverageDelta > 0 ? 'improved' : ($coverageDelta < 0 ? 'degraded' : 'stable'),
            ],
        ];
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }
}
