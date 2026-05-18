<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix;

use ACQC\Core\Rules\Violation;

/**
 * Interface for fix generators
 * 
 * Fix generators are specialized classes that can generate fix commands
 * for specific types of violations (e.g., Magento config, database, cache).
 */
interface FixGeneratorInterface
{
    /**
     * Check if this generator supports fixing the given violation
     * 
     * @param Violation $violation The violation to check
     * @return bool True if this generator can generate a fix for this violation
     */
    public function supports(Violation $violation): bool;
    
    /**
     * Generate a fix command for the given violation
     * 
     * @param Violation $violation The violation to generate a fix for
     * @return FixCommand|null The fix command, or null if fix cannot be generated
     */
    public function generate(Violation $violation): ?FixCommand;
    
    /**
     * Get the name of this fix generator
     * 
     * @return string Generator name (e.g., "magento_config", "database")
     */
    public function getName(): string;
    
    /**
     * Get the list of violation rules this generator can fix
     * 
     * @return array<string> Array of rule names
     */
    public function getSupportedRules(): array;
}
