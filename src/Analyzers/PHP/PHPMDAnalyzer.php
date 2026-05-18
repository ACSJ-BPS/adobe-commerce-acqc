<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class PHPMDAnalyzer extends AbstractAnalyzer
{
    private string $phpmdCommand = 'phpmd';
    private ?string $configFile = null;

    public function getName(): string
    {
        return 'phpmd';
    }

    public function getVersion(): string
    {
        return $this->getToolVersion($this->phpmdCommand, ['--version']);
    }

    public function isAvailable(): bool
    {
        return $this->commandExists($this->phpmdCommand);
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'rulesets' => ['cleancode', 'codesize', 'controversial', 'design', 'naming', 'unusedcode'],
            'suffixes' => ['php'],
            'minimum_priority' => 1,
            'report_format' => 'xml',
            'ignore_patterns' => [
                'vendor/*',
                'node_modules/*',
                'var/*',
                'generated/*',
                'pub/static/*',
                'tests/*',
                'Test/*',
            ],
            'strict' => false,
            'coverage_database' => null,
            'custom_rules' => [],
            'exclude_rules' => [],
            'rule_priorities' => [],
        ];
    }

    protected function validateConfiguration(): void
    {
        parent::validateConfiguration();

        $rulesets = $this->getConfiguration('rulesets', []);
        if (empty($rulesets)) {
            throw new AnalyzerException("At least one ruleset must be specified");
        }

        foreach ($rulesets as $ruleset) {
            if (!$this->isValidRuleset($ruleset)) {
                $this->logger->warning("Ruleset may not exist: {$ruleset}");
            }
        }

        $minPriority = $this->getConfiguration('minimum_priority');
        if (!is_int($minPriority) || $minPriority < 1 || $minPriority > 5) {
            throw new AnalyzerException("Minimum priority must be between 1 and 5");
        }
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $results = [];
        $batchSize = $this->getConfiguration('batch_size', 25);
        $fileBatches = array_chunk($files, $batchSize);

        foreach ($fileBatches as $batch) {
            try {
                $command = $this->buildCommand($batch, $options);
                $process = $this->executeCommand($command);
                
                $output = $process->getOutput();
                if (!empty($output)) {
                    $results[] = $output;
                }

                $errorOutput = $process->getErrorOutput();
                if (!empty($errorOutput)) {
                    $this->logger->warning("PHPMD stderr: " . $errorOutput);
                }

            } catch (\Exception $e) {
                $this->logger->error("PHPMD batch failed: " . $e->getMessage());
                
                if (count($batch) > 1) {
                    foreach ($batch as $file) {
                        try {
                            $singleCommand = $this->buildCommand([$file], $options);
                            $singleProcess = $this->executeCommand($singleCommand);
                            $singleOutput = $singleProcess->getOutput();
                            
                            if (!empty($singleOutput)) {
                                $results[] = $singleOutput;
                            }
                        } catch (\Exception $singleE) {
                            $this->logger->error("PHPMD failed for file {$file}: " . $singleE->getMessage());
                        }
                    }
                } else {
                    throw $e;
                }
            }
        }

        return $results;
    }

    protected function parseOutput(string $output, array $files): array
    {
        $violations = [];

        if (empty($output) || trim($output) === '') {
            return $violations;
        }

        try {
            if ($this->getConfiguration('report_format') === 'xml') {
                $violations = $this->parseXmlOutput($output);
            } else {
                $violations = $this->parseJsonOutput($output);
            }

        } catch (\Exception $e) {
            $this->logger->error("Failed to parse PHPMD output: " . $e->getMessage());
            $this->logger->debug("Raw output: " . substr($output, 0, 1000));
        }

        return $violations;
    }

    private function parseXmlOutput(string $xmlOutput): array
    {
        $violations = [];

        $xmlOutput = trim($xmlOutput);
        if (empty($xmlOutput) || strpos($xmlOutput, '<pmd') === false) {
            return $violations;
        }

        try {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            
            if (!$doc->loadXML($xmlOutput)) {
                $errors = libxml_get_errors();
                $errorMessages = array_map(fn($error) => $error->message, $errors);
                throw new AnalyzerException("XML parsing failed: " . implode(', ', $errorMessages));
            }

            $xpath = new \DOMXPath($doc);
            $fileNodes = $xpath->query('//file');

            foreach ($fileNodes as $fileNode) {
                $filePath = $fileNode->getAttribute('name');
                $violationNodes = $xpath->query('.//violation', $fileNode);

                foreach ($violationNodes as $violationNode) {
                    $violation = $this->createViolationFromXmlNode($violationNode, $filePath);
                    if ($violation) {
                        $violations[] = $violation;
                    }
                }
            }

        } catch (\Exception $e) {
            throw new AnalyzerException("Failed to parse PHPMD XML output: " . $e->getMessage());
        } finally {
            libxml_clear_errors();
        }

        return $violations;
    }

    private function parseJsonOutput(string $jsonOutput): array
    {
        $violations = [];

        try {
            $data = json_decode($jsonOutput, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AnalyzerException("Invalid JSON output from PHPMD: " . json_last_error_msg());
            }

        } catch (\Exception $e) {
            $this->logger->error("Failed to parse PHPMD JSON output: " . $e->getMessage());
        }

        return $violations;
    }

    private function createViolationFromXmlNode(\DOMElement $node, string $filePath): ?Violation
    {
        $rule = $node->getAttribute('rule') ?: 'unknown';
        $ruleset = $node->getAttribute('ruleset') ?: 'unknown';
        $priority = (int) ($node->getAttribute('priority') ?: 3);
        $beginLine = (int) ($node->getAttribute('beginline') ?: 0);
        $endLine = (int) ($node->getAttribute('endline') ?: 0);
        $method = $node->getAttribute('method') ?: null;
        $className = $node->getAttribute('class') ?: null;
        $package = $node->getAttribute('package') ?: null;
        $message = trim($node->textContent ?: '');

        if (empty($message) || empty($rule)) {
            return null;
        }

        $severity = $this->mapPhpmdPriority($priority);
        $violationType = $this->mapRulesetToType($ruleset);

        $ruleId = $ruleset . '.' . $rule;
        
        $violation = $this->createViolation(
            $ruleId,
            $message,
            $filePath,
            $beginLine,
            0,
            $severity,
            $violationType,
            [
                'phpmd_priority' => $priority,
                'phpmd_ruleset' => $ruleset,
                'phpmd_rule' => $rule,
                'end_line' => $endLine,
                'method' => $method,
                'class' => $className,
                'package' => $package,
            ]
        );

        if ($beginLine > 0) {
            $context = $this->extractSourceContext($filePath, $beginLine, 2);
            if (!empty($context)) {
                $violation->setSource($context[$beginLine] ?? '');
                $violation->addContext('source_context', $context);
            }
        }

        if ($method) {
            $violation->addContext('affected_method', $method);
        }
        
        if ($className) {
            $violation->addContext('affected_class', $className);
        }
        
        if ($endLine > $beginLine) {
            $violation->addContext('line_range', "{$beginLine}-{$endLine}");
        }

        $suggestion = $this->getSuggestionForRule($rule, $ruleset);
        if ($suggestion) {
            $violation->setSuggestion($suggestion);
        }

        return $violation;
    }

    private function buildCommand(array $files, array $options = []): array
    {
        $command = [$this->phpmdCommand];

        if (count($files) === 1 && is_dir($files[0])) {
            $command[] = $files[0];
        } else {
            $command[] = implode(',', $files);
        }

        $reportFormat = $this->getConfiguration('report_format', 'xml');
        $command[] = $reportFormat;

        $rulesets = $this->getConfiguration('rulesets', []);
        if (!empty($rulesets)) {
            $command[] = implode(',', $rulesets);
        }

        $minPriority = $this->getConfiguration('minimum_priority');
        if ($minPriority) {
            $command[] = '--minimumpriority';
            $command[] = (string) $minPriority;
        }

        $suffixes = $this->getConfiguration('suffixes', ['php']);
        if (!empty($suffixes)) {
            $command[] = '--suffixes';
            $command[] = implode(',', $suffixes);
        }

        $ignorePatterns = array_merge(
            $this->getConfiguration('ignore_patterns', []),
            $this->excludePatterns
        );
        if (!empty($ignorePatterns)) {
            $command[] = '--exclude';
            $command[] = implode(',', $ignorePatterns);
        }

        if ($this->getConfiguration('strict', false)) {
            $command[] = '--strict';
        }

        $coverageDb = $this->getConfiguration('coverage_database');
        if ($coverageDb && file_exists($coverageDb)) {
            $command[] = '--coveragedb';
            $command[] = $coverageDb;
        }

        return $command;
    }

    private function mapPhpmdPriority(int $priority): string
    {
        return match ($priority) {
            1 => Violation::SEVERITY_CRITICAL,
            2 => Violation::SEVERITY_ERROR,
            3 => Violation::SEVERITY_WARNING,
            4, 5 => Violation::SEVERITY_INFO,
            default => Violation::SEVERITY_WARNING,
        };
    }

    private function mapRulesetToType(string $ruleset): string
    {
        return match (strtolower($ruleset)) {
            'cleancode' => Violation::TYPE_MAINTAINABILITY,
            'codesize' => Violation::TYPE_MAINTAINABILITY,
            'controversial' => Violation::TYPE_STYLE,
            'design' => Violation::TYPE_LOGIC,
            'naming' => Violation::TYPE_STYLE,
            'unusedcode' => Violation::TYPE_MAINTAINABILITY,
            default => Violation::TYPE_MAINTAINABILITY,
        };
    }

    private function getSuggestionForRule(string $rule, string $ruleset): ?string
    {
        $suggestions = [
            'BooleanArgumentFlag' => 'Avoid boolean flags; consider using separate methods or enum parameters',
            'ElseExpression' => 'Consider refactoring to eliminate else expressions using early returns',
            'StaticAccess' => 'Consider dependency injection instead of static method calls',
            'CyclomaticComplexity' => 'Reduce cyclomatic complexity by extracting methods or simplifying conditions',
            'NPathComplexity' => 'Simplify the method by reducing nested conditions and loops',
            'ExcessiveMethodLength' => 'Break this method into smaller, more focused methods',
            'ExcessiveClassLength' => 'Consider splitting this class into smaller, more cohesive classes',
            'ExcessiveParameterList' => 'Reduce parameters by grouping them into objects or using builder pattern',
            'ExcessivePublicCount' => 'Reduce public methods by making some protected/private or splitting the class',
            'TooManyFields' => 'Consider splitting this class or using composition',
            'TooManyMethods' => 'Split this class into smaller, more focused classes',
            'CouplingBetweenObjects' => 'Reduce dependencies between classes using interfaces or dependency injection',
            'DepthOfInheritance' => 'Consider composition over inheritance to reduce hierarchy depth',
            'NumberOfChildren' => 'Consider using composition or interfaces instead of deep inheritance',
            'ShortVariable' => 'Use descriptive variable names that clearly indicate purpose',
            'LongVariable' => 'Consider shortening variable name while maintaining clarity',
            'ShortMethodName' => 'Use descriptive method names that clearly indicate functionality',
            'BooleanGetMethodName' => 'Boolean getter methods should start with "is", "has", or "can"',
            'UnusedPrivateField' => 'Remove unused private field or make it used',
            'UnusedLocalVariable' => 'Remove unused local variable',
            'UnusedPrivateMethod' => 'Remove unused private method or make it used',
            'UnusedFormalParameter' => 'Remove unused parameter or use it in the method'
        ];

        return $suggestions[$rule] ?? null;
    }

    private function isValidRuleset(string $ruleset): bool
    {
        $builtInRulesets = [
            'cleancode', 'codesize', 'controversial', 'design', 'naming', 'unusedcode'
        ];

        if (in_array(strtolower($ruleset), $builtInRulesets)) {
            return true;
        }

        return file_exists($ruleset);
    }

    public function setCommand(string $command): self
    {
        $this->phpmdCommand = $command;
        return $this;
    }

    public function setConfigFile(string $configFile): self
    {
        if (!file_exists($configFile)) {
            throw new \InvalidArgumentException("Config file does not exist: {$configFile}");
        }
        $this->configFile = $configFile;
        return $this;
    }

    public function getAvailableRulesets(): array
    {
        return ['cleancode', 'codesize', 'controversial', 'design', 'naming', 'unusedcode'];
    }

    public function generateConfigFile(string $outputPath, array $config = []): bool
    {
        $rulesets = $config['rulesets'] ?? $this->getConfiguration('rulesets', ['cleancode', 'codesize']);
        $customRules = $config['custom_rules'] ?? $this->getConfiguration('custom_rules', []);
        $excludeRules = $config['exclude_rules'] ?? $this->getConfiguration('exclude_rules', []);

        $xmlContent = '<?xml version="1.0"?>' . "\n";
        $xmlContent .= '<ruleset name="ACQC PHPMD Configuration"' . "\n";
        $xmlContent .= '         xmlns="http://pmd.sf.net/ruleset/1.0.0"' . "\n";
        $xmlContent .= '         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
        $xmlContent .= '         xsi:schemaLocation="http://pmd.sf.net/ruleset/1.0.0 http://pmd.sf.net/ruleset_xml_schema.xsd"' . "\n";
        $xmlContent .= '         xsi:noNamespaceSchemaLocation="http://pmd.sf.net/ruleset_xml_schema.xsd">' . "\n";
        $xmlContent .= '  <description>Custom PHPMD configuration for ACQC</description>' . "\n\n";

        foreach ($rulesets as $ruleset) {
            if (in_array($ruleset, $this->getAvailableRulesets())) {
                $xmlContent .= "  <rule ref=\"rulesets/{$ruleset}.xml\"";
                
                $rulesetExcludes = array_filter($excludeRules, function($rule) use ($ruleset) {
                    return strpos($rule, $ruleset . '.') === 0;
                });
                
                if (!empty($rulesetExcludes)) {
                    $xmlContent .= ">\n";
                    foreach ($rulesetExcludes as $excludeRule) {
                        $ruleName = substr($excludeRule, strlen($ruleset) + 1);
                        $xmlContent .= "    <exclude name=\"{$ruleName}\"/>\n";
                    }
                    $xmlContent .= "  </rule>\n\n";
                } else {
                    $xmlContent .= "/>\n\n";
                }
            }
        }

        foreach ($customRules as $customRule) {
            $xmlContent .= "  <rule ref=\"{$customRule}\"/>\n";
        }

        $xmlContent .= '</ruleset>' . "\n";

        try {
            file_put_contents($outputPath, $xmlContent);
            $this->setConfigFile($outputPath);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to generate PHPMD config file: " . $e->getMessage());
            return false;
        }
    }

    protected function isExpectedFailure(\Symfony\Component\Process\Process $process): bool
    {
        return in_array($process->getExitCode(), [0, 2]);
    }
} 