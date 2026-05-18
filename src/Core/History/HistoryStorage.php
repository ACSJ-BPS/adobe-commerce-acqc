<?php
declare(strict_types=1);
namespace ACQC\Core\History;

interface HistoryStorage
{
    public function save(HistoryEntry $entry): void;
    public function findAll(string $projectPath = '', int $limit = 50): array;
    public function findById(int $id): ?HistoryEntry;
    public function delete(int $id): void;
    public function clear(string $projectPath = ''): void;
    public function compare(int $idA, int $idB): array;
}
