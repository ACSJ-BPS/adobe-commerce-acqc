<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix;

/**
 * Represents a single automated fix command
 * 
 * Contains all information needed to execute a fix, including the command itself,
 * safety checks, rollback procedures, and metadata.
 */
class FixCommand
{
    /**
     * @param string $command The shell command to execute
     * @param string $description Human-readable description of what this fix does
     * @param array<array{condition: string, message: string}> $safetyChecks Safety checks to run before executing
     * @param string|null $rollback Optional rollback command if the fix fails
     * @param bool $requiresConfirmation Whether to prompt user before executing
     * @param string|null $workingDirectory Optional working directory for the command
     * @param array<string> $dependencies Optional list of commands that must succeed first
     * @param int $priority Priority level (1-10, higher = more important)
     */
    public function __construct(
        public readonly string $command,
        public readonly string $description,
        public readonly array $safetyChecks = [],
        public readonly ?string $rollback = null,
        public readonly bool $requiresConfirmation = false,
        public readonly ?string $workingDirectory = null,
        public readonly array $dependencies = [],
        public readonly int $priority = 5
    ) {
        if ($priority < 1 || $priority > 10) {
            throw new \InvalidArgumentException('Priority must be between 1 and 10');
        }
    }
    
    /**
     * Convert this fix command to a shell script fragment
     * 
     * @return string Shell script code for this fix command
     */
    public function toShellScript(): string
    {
        $script = "# {$this->description}\n";
        $script .= "# Priority: {$this->priority}/10\n";
        
        // Add safety checks
        if (!empty($this->safetyChecks)) {
            $script .= "\n# Safety checks\n";
            foreach ($this->safetyChecks as $check) {
                $script .= "if ! {$check['condition']}; then\n";
                $script .= "  echo '❌ Safety check failed: {$check['message']}'\n";
                $script .= "  exit 1\n";
                $script .= "fi\n";
            }
        }
        
        // Add working directory change if specified
        if ($this->workingDirectory) {
            $script .= "\n# Change to working directory\n";
            $script .= "cd \"{$this->workingDirectory}\" || exit 1\n";
        }
        
        // Add the actual command
        $script .= "\n# Execute fix\n";
        if ($this->requiresConfirmation) {
            $script .= "read -p '🤔 Execute: {$this->command}? (y/n) ' -n 1 -r\n";
            $script .= "echo\n";
            $script .= "if [[ \$REPLY =~ ^[Yy]$ ]]; then\n";
            $script .= "  echo '⚙️  Executing: {$this->description}'\n";
            $script .= "  {$this->command}\n";
            if ($this->rollback) {
                $script .= "  if [ \$? -ne 0 ]; then\n";
                $script .= "    echo '⚠️  Fix failed, attempting rollback...'\n";
                $script .= "    {$this->rollback}\n";
                $script .= "    exit 1\n";
                $script .= "  fi\n";
            }
            $script .= "  echo '✅ Fix applied successfully'\n";
            $script .= "else\n";
            $script .= "  echo '⏭️  Skipped'\n";
            $script .= "fi\n";
        } else {
            $script .= "echo '⚙️  Executing: {$this->description}'\n";
            $script .= "{$this->command}\n";
            if ($this->rollback) {
                $script .= "if [ \$? -ne 0 ]; then\n";
                $script .= "  echo '⚠️  Fix failed, attempting rollback...'\n";
                $script .= "  {$this->rollback}\n";
                $script .= "  exit 1\n";
                $script .= "fi\n";
            } else {
                $script .= "if [ \$? -eq 0 ]; then\n";
                $script .= "  echo '✅ Fix applied successfully'\n";
                $script .= "else\n";
                $script .= "  echo '❌ Fix failed'\n";
                $script .= "  exit 1\n";
                $script .= "fi\n";
            }
        }
        
        return $script;
    }
    
    /**
     * Get a short summary of this fix command
     * 
     * @return string Summary string
     */
    public function getSummary(): string
    {
        return sprintf(
            '[Priority %d] %s: %s',
            $this->priority,
            $this->description,
            substr($this->command, 0, 60) . (strlen($this->command) > 60 ? '...' : '')
        );
    }
}
