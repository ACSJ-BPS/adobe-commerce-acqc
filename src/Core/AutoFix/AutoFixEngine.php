<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix;

use ACQC\Utils\BackupManager;

/**
 * Auto-Fix Engine
 * 
 * Automatically fixes common upgrade issues
 */
class AutoFixEngine
{
    private array $fixers = [];
    private ?BackupManager $backupManager;
    private array $fixResults = [];
    
    public function __construct(?BackupManager $backupManager = null)
    {
        $this->backupManager = $backupManager ?? new BackupManager();
        $this->registerDefaultFixers();
    }
    
    /**
     * Register default fixers
     */
    private function registerDefaultFixers(): void
    {
        $this->registerFixer(new ZendToLaminasFixer());
        // Add more fixers here as they're implemented
    }
    
    /**
     * Register a custom fixer
     */
    public function registerFixer(FixerInterface $fixer): void
    {
        $this->fixers[$fixer->getName()] = $fixer;
    }
    
    /**
     * Get all registered fixers
     *
     * @return FixerInterface[]
     */
    public function getFixers(): array
    {
        return $this->fixers;
    }
    
    /**
     * Apply fixes to a file or directory
     * 
     * @param string $path Path to fix
     * @param array $options Fix options (fixers, dry-run, backup, etc.)
     * @return array Fix results
     */
    public function applyFixes(string $path, array $options = []): array
    {
        $dryRun = $options['dry-run'] ?? false;
        $createBackup = $options['backup'] ?? true;
        $selectedFixers = $options['fixers'] ?? array_keys($this->fixers);
        
        $this->fixResults = [
            'path' => $path,
            'dry_run' => $dryRun,
            'backup_created' => false,
            'backup_file' => null,
            'fixes_applied' => [],
            'total_changes' => 0,
            'files_modified' => 0,
            'errors' => []
        ];
        
        // Create backup if not dry-run and backup requested
        if (!$dryRun && $createBackup) {
            $backupResult = $this->backupManager->createBackup($path);
            if ($backupResult['success']) {
                $this->fixResults['backup_created'] = true;
                $this->fixResults['backup_file'] = $backupResult['backup_file'];
            } else {
                $this->fixResults['errors'][] = 'Failed to create backup: ' . $backupResult['error'];
                return $this->fixResults;
            }
        }
        
        // Get list of PHP files
        $files = $this->getPhpFiles($path);
        
        foreach ($files as $file) {
            $fileResults = $this->applyFixesToFile($file, $selectedFixers, $dryRun);
            if (!empty($fileResults)) {
                $this->fixResults['fixes_applied'][] = $fileResults;
                $this->fixResults['total_changes'] += $fileResults['changes_count'];
                if ($fileResults['changes_count'] > 0) {
                    $this->fixResults['files_modified']++;
                }
            }
        }
        
        return $this->fixResults;
    }
    
    /**
     * Apply fixes to a single file
     */
    private function applyFixesToFile(string $filePath, array $selectedFixers, bool $dryRun): array
    {
        $content = file_get_contents($filePath);
        $originalContent = $content;
        $fileChanges = [];
        
        foreach ($selectedFixers as $fixerName) {
            if (!isset($this->fixers[$fixerName])) {
                continue;
            }
            
            $fixer = $this->fixers[$fixerName];
            
            if (!$fixer->canFix($filePath, $content)) {
                continue;
            }
            
            $fixResult = $fixer->fix($content);
            
            if ($fixResult['modified']) {
                $content = $fixResult['content'];
                $fileChanges = array_merge($fileChanges, $fixResult['changes']);
            }
        }
        
        // If content changed and not dry-run, write the file
        if ($content !== $originalContent && !$dryRun) {
            file_put_contents($filePath, $content);
        }
        
        return [
            'file' => $filePath,
            'changes_count' => count($fileChanges),
            'changes' => $fileChanges
        ];
    }
    
    /**
     * Get all PHP files in path
     */
    private function getPhpFiles(string $path): array
    {
        $files = [];
        
        if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            return [$path];
        }
        
        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    
                    // Skip vendor, node_modules, etc.
                    if ($this->shouldSkipFile($filePath)) {
                        continue;
                    }
                    
                    $files[] = $filePath;
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Check if file should be skipped
     */
    private function shouldSkipFile(string $path): bool
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
     * Get fix results
     */
    public function getResults(): array
    {
        return $this->fixResults;
    }
}

