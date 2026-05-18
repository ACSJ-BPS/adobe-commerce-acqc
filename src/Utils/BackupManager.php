<?php

declare(strict_types=1);

namespace ACQC\Utils;

use ZipArchive;

/**
 * Backup Manager
 * 
 * Manages code backups before applying auto-fixes or upgrades
 */
class BackupManager
{
    private string $backupDir;
    
    public function __construct(?string $backupDir = null)
    {
        $this->backupDir = $backupDir ?? getcwd() . '/backups';
        
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }
    
    /**
     * Create a backup of specified path
     * 
     * @param string $sourcePath Path to backup
     * @param string|null $backupName Optional custom backup name
     * @return array{success: bool, backup_file?: string, message?: string, error?: string}
     */
    public function createBackup(string $sourcePath, ?string $backupName = null): array
    {
        if (!file_exists($sourcePath)) {
            return [
                'success' => false,
                'error' => 'Source path does not exist: ' . $sourcePath
            ];
        }
        
        $backupName = $backupName ?? $this->generateBackupName(basename($sourcePath));
        $backupFile = $this->backupDir . '/' . $backupName . '.zip';
        
        try {
            $zip = new ZipArchive();
            
            if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return [
                    'success' => false,
                    'error' => 'Failed to create backup archive'
                ];
            }
            
            if (is_file($sourcePath)) {
                $zip->addFile($sourcePath, basename($sourcePath));
            } else {
                $this->addDirectoryToZip($zip, $sourcePath, $sourcePath);
            }
            
            $zip->close();
            
            $fileSize = filesize($backupFile);
            
            return [
                'success' => true,
                'backup_file' => $backupFile,
                'message' => sprintf(
                    'Backup created successfully: %s (%.2f MB)',
                    $backupFile,
                    $fileSize / 1024 / 1024
                )
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Backup failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Restore a backup
     * 
     * @param string $backupFile Path to backup file
     * @param string|null $targetPath Optional target path (defaults to original location)
     * @return array{success: bool, message?: string, error?: string}
     */
    public function restoreBackup(string $backupFile, ?string $targetPath = null): array
    {
        if (!file_exists($backupFile)) {
            return [
                'success' => false,
                'error' => 'Backup file does not exist: ' . $backupFile
            ];
        }
        
        try {
            $zip = new ZipArchive();
            
            if ($zip->open($backupFile) !== true) {
                return [
                    'success' => false,
                    'error' => 'Failed to open backup archive'
                ];
            }
            
            $extractPath = $targetPath ?? dirname($backupFile);
            
            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                return [
                    'success' => false,
                    'error' => 'Failed to extract backup'
                ];
            }
            
            $zip->close();
            
            return [
                'success' => true,
                'message' => sprintf('Backup restored successfully to: %s', $extractPath)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Restore failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * List all available backups
     * 
     * @return array List of backup files with metadata
     */
    public function listBackups(): array
    {
        $backups = [];
        $files = glob($this->backupDir . '/*.zip');
        
        foreach ($files as $file) {
            $backups[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'size_formatted' => $this->formatFileSize(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'age' => $this->getAge(filemtime($file))
            ];
        }
        
        // Sort by creation time (newest first)
        usort($backups, function($a, $b) {
            return filemtime($b['path']) - filemtime($a['path']);
        });
        
        return $backups;
    }
    
    /**
     * Delete a backup
     * 
     * @param string $backupFile Path to backup file or just the filename
     * @return array{success: bool, message?: string, error?: string}
     */
    public function deleteBackup(string $backupFile): array
    {
        // If only filename provided, prepend backup directory
        if (basename($backupFile) === $backupFile) {
            $backupFile = $this->backupDir . '/' . $backupFile;
        }
        
        if (!file_exists($backupFile)) {
            return [
                'success' => false,
                'error' => 'Backup file does not exist: ' . $backupFile
            ];
        }
        
        if (unlink($backupFile)) {
            return [
                'success' => true,
                'message' => 'Backup deleted successfully: ' . basename($backupFile)
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Failed to delete backup'
        ];
    }
    
    /**
     * Clean up old backups (older than specified days)
     * 
     * @param int $olderThanDays Delete backups older than this many days
     * @return array{success: bool, deleted_count: int, message: string}
     */
    public function cleanupOldBackups(int $olderThanDays = 30): array
    {
        $deletedCount = 0;
        $cutoffTime = time() - ($olderThanDays * 24 * 60 * 60);
        
        $files = glob($this->backupDir . '/*.zip');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }
        
        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'message' => sprintf('Deleted %d backup(s) older than %d days', $deletedCount, $olderThanDays)
        ];
    }
    
    /**
     * Get backup directory path
     */
    public function getBackupDir(): string
    {
        return $this->backupDir;
    }
    
    /**
     * Generate unique backup name
     */
    private function generateBackupName(string $baseName): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
        return sprintf('%s_backup_%s', $cleanName, $timestamp);
    }
    
    /**
     * Add directory to ZIP archive recursively
     */
    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $baseDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($baseDir) + 1);
            
            // Skip vendor, node_modules, and other common directories
            if ($this->shouldSkipPath($relativePath)) {
                continue;
            }
            
            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }
    }
    
    /**
     * Check if path should be skipped in backup
     */
    private function shouldSkipPath(string $path): bool
    {
        $skipPatterns = [
            '/vendor/',
            '/node_modules/',
            '/.git/',
            '/var/cache/',
            '/var/log/',
            '/generated/',
            '/pub/static/',
        ];
        
        foreach ($skipPatterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Format file size for display
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Get human-readable age of file
     */
    private function getAge(int $timestamp): string
    {
        $diff = time() - $timestamp;
        
        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours !== 1 ? 's' : '') . ' ago';
        } else {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days !== 1 ? 's' : '') . ' ago';
        }
    }
}

