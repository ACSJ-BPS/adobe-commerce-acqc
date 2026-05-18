<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix;

use ACQC\Core\Rules\Violation;

/**
 * Interface for analyzers that can generate automated fixes
 * 
 * Analyzers implementing this interface can provide automated fix commands
 * for violations they detect, enabling the --generate-fix feature.
 */
interface FixableInterface
{
    /**
     * Check if this analyzer can automatically fix a specific violation
     * 
     * @param Violation $violation The violation to check
     * @return bool True if the violation can be automatically fixed
     */
    public function canAutoFix(Violation $violation): bool;
    
    /**
     * Generate fix command for a specific violation
     * 
     * @param Violation $violation The violation to generate a fix for
     * @return FixCommand|null The fix command, or null if fix cannot be generated
     */
    public function generateFix(Violation $violation): ?FixCommand;
    
    /**
     * Get the list of violation rules that this analyzer can fix
     * 
     * @return array<string> Array of rule names that can be automatically fixed
     */
    public function getFixableRules(): array;
}
