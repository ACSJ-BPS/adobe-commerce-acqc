<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix;

use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main fix script generator
 * 
 * Orchestrates fix generators to create executable shell scripts that
 * automatically fix detected violations.
 */
class FixScriptGenerator
{
    /** @var array<string, FixGeneratorInterface> */
    private array $generators = [];
    
    private LoggerInterface $logger;
    
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }
    
    /**
     * Register a fix generator
     * 
     * @param string $name Generator name (e.g., "magento_config")
     * @param FixGeneratorInterface $generator The generator instance
     * @return self For method chaining
     */
    public function registerGenerator(string $name, FixGeneratorInterface $generator): self
    {
        $this->generators[$name] = $generator;
        $this->logger->info("Registered fix generator: {$name}", [
            'supported_rules' => $generator->getSupportedRules()
        ]);
        return $this;
    }
    
    /**
     * Generate a fix script for the given violations
     * 
     * @param array<Violation> $violations Violations to generate fixes for
     * @param string $outputPath Path where to save the script
     * @param array{
     *   dry_run?: bool,
     *   require_confirmation?: bool,
     *   include_rollback?: bool,
     *   magento_root?: string
     * } $options Generation options
     * @return array{
     *   script_path: string,
     *   fixable_count: int,
     *   unfixable_count: int,
     *   commands: array<FixCommand>
     * } Generation result
     */
    public function generateFixScript(
        array $violations,
        string $outputPath,
        array $options = []
    ): array {
        $dryRun = $options['dry_run'] ?? false;
        $requireConfirmation = $options['require_confirmation'] ?? false;
        $includeRollback = $options['include_rollback'] ?? true;
        $magentoRoot = $options['magento_root'] ?? getcwd();
        
        $commands = [];
        $unfixableViolations = [];
        
        // Generate fix commands for each violation
        foreach ($violations as $violation) {
            $fixCommand = $this->findFix($violation);
            if ($fixCommand) {
                $commands[] = $fixCommand;
                $this->logger->debug("Generated fix for violation", [
                    'rule' => $violation->getRuleId(),
                    'command' => $fixCommand->getSummary()
                ]);
            } else {
                $unfixableViolations[] = $violation;
                $this->logger->debug("No fix available for violation", [
                    'rule' => $violation->getRuleId()
                ]);
            }
        }
        
        // Sort commands by priority (highest first)
        usort($commands, fn($a, $b) => $b->priority <=> $a->priority);
        
        // Build the shell script
        $script = $this->buildScript($commands, [
            'dry_run' => $dryRun,
            'require_confirmation' => $requireConfirmation,
            'magento_root' => $magentoRoot,
            'include_rollback' => $includeRollback
        ]);
        
        // Write the script to file
        if (!$dryRun) {
            $written = file_put_contents($outputPath, $script);
            if ($written === false) {
                throw new \RuntimeException("Failed to write fix script to: {$outputPath}");
            }
            chmod($outputPath, 0755);
            $this->logger->info("Fix script generated", [
                'path' => $outputPath,
                'size' => $written,
                'fixable_count' => count($commands),
                'unfixable_count' => count($unfixableViolations)
            ]);
        }
        
        return [
            'script_path' => $outputPath,
            'fixable_count' => count($commands),
            'unfixable_count' => count($unfixableViolations),
            'commands' => $commands
        ];
    }
    
    /**
     * Find a fix for the given violation
     * 
     * @param Violation $violation The violation to fix
     * @return FixCommand|null The fix command, or null if no fix available
     */
    private function findFix(Violation $violation): ?FixCommand
    {
        foreach ($this->generators as $generator) {
            if ($generator->supports($violation)) {
                try {
                    return $generator->generate($violation);
                } catch (\Exception $e) {
                    $this->logger->error("Fix generator failed", [
                        'generator' => $generator->getName(),
                        'rule' => $violation->getRuleId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        return null;
    }
    
    /**
     * Build the complete shell script
     * 
     * @param array<FixCommand> $commands Fix commands to include
     * @param array{
     *   dry_run: bool,
     *   require_confirmation: bool,
     *   magento_root: string,
     *   include_rollback: bool
     * } $options Script options
     * @return string The complete shell script
     */
    private function buildScript(array $commands, array $options): string
    {
        $script = "#!/bin/bash\n\n";
        $script .= "# ACQC Auto-generated Fix Script\n";
        $script .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
        $script .= "# Total fixes: " . count($commands) . "\n";
        $script .= "#\n";
        $script .= "# ⚠️  WARNING: Review this script before executing!\n";
        $script .= "# This script will make changes to your Magento installation.\n";
        $script .= "#\n";
        $script .= "# Usage:\n";
        $script .= "#   chmod +x " . basename($options['script_path'] ?? 'fix_script.sh') . "\n";
        $script .= "#   ./" . basename($options['script_path'] ?? 'fix_script.sh') . "\n";
        $script .= "#\n\n";
        
        // Add script configuration
        $script .= "# Script configuration\n";
        $script .= "set -e  # Exit on first error\n";
        $script .= "set -u  # Exit on undefined variable\n";
        $script .= "set -o pipefail  # Exit on pipe failure\n\n";
        
        // Add Magento root directory
        $script .= "# Magento root directory\n";
        $script .= "MAGENTO_ROOT=\"{$options['magento_root']}\"\n";
        $script .= "cd \"\$MAGENTO_ROOT\" || exit 1\n\n";
        
        // Add color codes for pretty output
        $script .= "# Color codes for output\n";
        $script .= "RED='\\033[0;31m'\n";
        $script .= "GREEN='\\033[0;32m'\n";
        $script .= "YELLOW='\\033[0;33m'\n";
        $script .= "BLUE='\\033[0;34m'\n";
        $script .= "NC='\\033[0m' # No Color\n\n";
        
        // Add helper functions
        $script .= $this->buildHelperFunctions();
        
        // Add pre-flight checks
        $script .= "# Pre-flight checks\n";
        $script .= "echo -e \"\${BLUE}🔍 Running pre-flight checks...\${NC}\"\n";
        $script .= "check_magento_root\n";
        $script .= "check_magento_cli\n";
        $script .= "echo -e \"\${GREEN}✅ Pre-flight checks passed\${NC}\"\n";
        $script .= "echo\n\n";
        
        // Add confirmation prompt if required
        if ($options['require_confirmation']) {
            $script .= "# Confirmation\n";
            $script .= "echo -e \"\${YELLOW}⚠️  This script will apply " . count($commands) . " fixes to your Magento installation.\${NC}\"\n";
            $script .= "read -p \"Do you want to continue? (y/n) \" -n 1 -r\n";
            $script .= "echo\n";
            $script .= "if [[ ! \$REPLY =~ ^[Yy]$ ]]; then\n";
            $script .= "    echo -e \"\${YELLOW}Aborted by user\${NC}\"\n";
            $script .= "    exit 0\n";
            $script .= "fi\n";
            $script .= "echo\n\n";
        }
        
        // Add backup creation
        $script .= "# Create backup\n";
        $script .= "echo -e \"\${BLUE}💾 Creating backup...\${NC}\"\n";
        $script .= "BACKUP_DIR=\"var/backups/acqc_" . date('Ymd_His') . "\"\n";
        $script .= "mkdir -p \"\$BACKUP_DIR\"\n";
        $script .= "if [ -f \"app/etc/env.php\" ]; then\n";
        $script .= "    cp \"app/etc/env.php\" \"\$BACKUP_DIR/env.php.backup\"\n";
        $script .= "fi\n";
        $script .= "if [ -f \"app/etc/config.php\" ]; then\n";
        $script .= "    cp \"app/etc/config.php\" \"\$BACKUP_DIR/config.php.backup\"\n";
        $script .= "fi\n";
        $script .= "echo -e \"\${GREEN}✅ Backup created: \$BACKUP_DIR\${NC}\"\n";
        $script .= "echo\n\n";
        
        // Add each fix command
        $script .= "# Execute fixes\n";
        $script .= "echo -e \"\${BLUE}🔧 Applying fixes...\${NC}\"\n";
        $script .= "echo\n\n";
        
        $fixNumber = 1;
        foreach ($commands as $command) {
            $script .= "# Fix #{$fixNumber}/{" . count($commands) . "}\n";
            $script .= "echo -e \"\${BLUE}[{$fixNumber}/" . count($commands) . "] {$command->description}\${NC}\"\n";
            $script .= $command->toShellScript();
            $script .= "\necho\n\n";
            $fixNumber++;
        }
        
        // Add completion message
        $script .= "# Completion\n";
        $script .= "echo -e \"\${GREEN}════════════════════════════════════════════════════════════\${NC}\"\n";
        $script .= "echo -e \"\${GREEN}✅ All fixes applied successfully!\${NC}\"\n";
        $script .= "echo -e \"\${GREEN}════════════════════════════════════════════════════════════\${NC}\"\n";
        $script .= "echo\n";
        $script .= "echo -e \"\${YELLOW}📝 Next steps:\${NC}\"\n";
        $script .= "echo \"  1. Test your Magento installation\"\n";
        $script .= "echo \"  2. Clear cache: bin/magento cache:flush\"\n";
        $script .= "echo \"  3. Run acqc again to verify fixes\"\n";
        $script .= "echo\n";
        $script .= "echo -e \"\${BLUE}💾 Backup location: \$BACKUP_DIR\${NC}\"\n";
        
        return $script;
    }
    
    /**
     * Build helper functions for the script
     * 
     * @return string Shell script helper functions
     */
    private function buildHelperFunctions(): string
    {
        return <<<'BASH'
# Helper functions

check_magento_root() {
    if [ ! -f "bin/magento" ]; then
        echo -e "${RED}❌ Error: Magento CLI not found. Are you in the Magento root directory?${NC}"
        exit 1
    fi
}

check_magento_cli() {
    if [ ! -x "bin/magento" ]; then
        echo -e "${YELLOW}⚠️  Warning: bin/magento is not executable, fixing permissions...${NC}"
        chmod +x bin/magento
    fi
}

run_command() {
    local cmd="$1"
    local description="$2"
    
    echo -e "${BLUE}⚙️  $description${NC}"
    if eval "$cmd"; then
        echo -e "${GREEN}✅ Success${NC}"
        return 0
    else
        echo -e "${RED}❌ Failed${NC}"
        return 1
    fi
}


BASH;
    }
    
    /**
     * Get statistics about fixable violations
     * 
     * @param array<Violation> $violations Violations to analyze
     * @return array{
     *   total: int,
     *   fixable: int,
     *   unfixable: int,
     *   by_category: array<string, int>,
     *   by_generator: array<string, int>
     * } Statistics
     */
    public function getFixableStatistics(array $violations): array
    {
        $stats = [
            'total' => count($violations),
            'fixable' => 0,
            'unfixable' => 0,
            'by_category' => [],
            'by_generator' => []
        ];
        
        foreach ($violations as $violation) {
            $category = $violation->getCategory() ?? 'unknown';
            $stats['by_category'][$category] = ($stats['by_category'][$category] ?? 0) + 1;
            
            $fixCommand = $this->findFix($violation);
            if ($fixCommand) {
                $stats['fixable']++;
                // Find which generator provided the fix
                foreach ($this->generators as $name => $generator) {
                    if ($generator->supports($violation)) {
                        $stats['by_generator'][$name] = ($stats['by_generator'][$name] ?? 0) + 1;
                        break;
                    }
                }
            } else {
                $stats['unfixable']++;
            }
        }
        
        return $stats;
    }
}
