<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix;

/**
 * Fixer Interface
 * 
 * Interface for all code fixers
 */
interface FixerInterface
{
    /**
     * Get fixer name
     */
    public function getName(): string;
    
    /**
     * Get fixer description
     */
    public function getDescription(): string;
    
    /**
     * Check if fixer can fix the given file/content
     */
    public function canFix(string $filePath, string $content): bool;
    
    /**
     * Apply fix to content
     * 
     * @return array{modified: bool, content: string, changes: array}
     */
    public function fix(string $content): array;
}

