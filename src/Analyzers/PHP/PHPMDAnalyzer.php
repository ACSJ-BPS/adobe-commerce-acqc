<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;
use Composer\InstalledVersions;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class PHPMDAnalyzer extends AbstractAnalyzer
{
    private string $phpmdCommand = 'phpmd';
    private bool $usesBundledPhpmd = false;
    private ?string $configFile = null;
    private array $analyzedFiles = [];
    private array $skippedFiles = [];

    public function analyze(array $files, array $options = []): array
    {
        $this->analyzedFiles = [];
        $this->skippedFiles = [];
        $completed = false;
        try {
            $violations = parent::analyze($files, $options);
            $completed = true;
            return $violations;
        } finally {
            $this->lastAnalysisResults['files'] = $completed ? array_values(array_unique($this->analyzedFiles)) : [];
            $this->lastAnalysisResults['skipped_files'] = array_values($this->skippedFiles);
        }
    }

    protected function filterChangedFiles(array $files): array
    {
        $retryFiles = array_column($this->lastAnalysisResults['skipped_files'] ?? [], 'file');
        return array_values(array_unique(array_merge(
            parent::filterChangedFiles($files),
            array_intersect($files, $retryFiles)
        )));
    }

    public function getName(): string
    {
        return 'phpmd';
    }

    public function getVersion(): string
    {
        if ($this->usesBundledPhpmd) {
            return $this->getToolVersion(PHP_BINARY, [$this->phpmdCommand, '--version']);
        }
        return $this->getToolVersion($this->phpmdCommand, ['--version']);
    }

    public function isAvailable(): bool
    {
        if ($this->configuration['phpmd'] !== [] && $this->phpmdCommand === 'phpmd') {
            $executable = (new ExecutableFinder())->find('phpmd');
            if ($executable === null && InstalledVersions::isInstalled('phpmd/phpmd')) {
                $packagePath = InstalledVersions::getInstallPath('phpmd/phpmd');
                $candidate = $packagePath !== null ? $packagePath . '/src/bin/phpmd' : '';
                if (is_file($candidate) && is_readable($candidate)) {
                    $executable = $candidate;
                    $this->usesBundledPhpmd = true;
                }
            }
            if ($executable === null) {
                return false;
            }
            $this->phpmdCommand = $executable;
        }
        if ($this->usesBundledPhpmd) {
            return is_file($this->phpmdCommand) && is_readable($this->phpmdCommand);
        }
        return is_file($this->phpmdCommand)
            ? is_executable($this->phpmdCommand)
            : $this->commandExists($this->phpmdCommand);
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'rulesets' => ['cleancode', 'codesize', 'controversial', 'design', 'naming', 'unusedcode'],
            'suffixes' => ['php'],
            'minimum_priority' => 1,
            'phpmd' => [],
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

        $this->configuration['phpmd'] = self::resolveProjectConfiguration(
            $this->configuration['phpmd'],
            $this->workingDirectory
        );
        $rulesets = $this->getPhpmdConfiguration('rulesets', []);
        if (empty($rulesets)) {
            throw new AnalyzerException("At least one ruleset must be specified");
        }

        foreach ($rulesets as $ruleset) {
            if (!$this->isValidRuleset($ruleset)) {
                $this->logger->warning("Ruleset may not exist: {$ruleset}");
            }
        }

        $minPriority = $this->getPhpmdConfiguration('minimum_priority');
        if (!is_int($minPriority) || $minPriority < 1 || $minPriority > 5) {
            throw new AnalyzerException("Minimum priority must be between 1 and 5");
        }
    }

    public static function resolveProjectConfiguration(mixed $configuration, string $projectRoot): array
    {
        if (!is_array($configuration)) {
            throw new \InvalidArgumentException('phpmd must be a mapping.');
        }
        foreach ($configuration as $key => $value) {
            if (!in_array($key, ['rulesets', 'minimum_priority', 'bootstrap', 'severity_mapping', 'rule_severity_overrides'], true)) {
                throw new \InvalidArgumentException("Unknown phpmd setting: {$key}.");
            }
        }
        if (array_key_exists('minimum_priority', $configuration)
            && (!is_int($configuration['minimum_priority'])
                || $configuration['minimum_priority'] < 1
                || $configuration['minimum_priority'] > 5)) {
            throw new \InvalidArgumentException('phpmd.minimum_priority must be an integer between 1 and 5.');
        }
        if (array_key_exists('severity_mapping', $configuration)) {
            if (!is_array($configuration['severity_mapping'])) {
                throw new \InvalidArgumentException('phpmd.severity_mapping must be a mapping.');
            }
            foreach ($configuration['severity_mapping'] as $priority => $severity) {
                if (!is_int($priority) || $priority < 1 || $priority > 5) {
                    throw new \InvalidArgumentException('phpmd.severity_mapping keys must be integer priorities between 1 and 5.');
                }
                if (!in_array($severity, Violation::getSeverityLevels(), true)) {
                    throw new \InvalidArgumentException("phpmd.severity_mapping.{$priority} must be a valid ACQC severity.");
                }
            }
        }
        if (array_key_exists('rule_severity_overrides', $configuration)) {
            if (!is_array($configuration['rule_severity_overrides'])) {
                throw new \InvalidArgumentException('phpmd.rule_severity_overrides must be a mapping.');
            }
            foreach ($configuration['rule_severity_overrides'] as $ruleId => $severity) {
                if (!is_string($ruleId) || trim($ruleId) !== $ruleId
                    || preg_match('/^phpmd\.[^\s.*?][^\r\n*?]*\.[^\s.*?]+$/D', $ruleId) !== 1) {
                    throw new \InvalidArgumentException('phpmd.rule_severity_overrides keys must be fully qualified PHPMD rule IDs, such as phpmd.Magento Specific Design Rules.AllPurposeAction.');
                }
                if (!in_array($severity, Violation::getSeverityLevels(), true)) {
                    throw new \InvalidArgumentException("phpmd.rule_severity_overrides.{$ruleId} must be a valid ACQC severity.");
                }
            }
        }
        if (array_key_exists('rulesets', $configuration)) {
            $rulesets = $configuration['rulesets'];
            if (!is_array($rulesets) || !array_is_list($rulesets) || $rulesets === []) {
                throw new \InvalidArgumentException('phpmd.rulesets must be a non-empty list.');
            }
            foreach ($rulesets as $index => $ruleset) {
                if (!is_string($ruleset) || trim($ruleset) === '') {
                    throw new \InvalidArgumentException("phpmd.rulesets.{$index} must be a non-empty string.");
                }
                if (!in_array($ruleset, ['cleancode', 'codesize', 'controversial', 'design', 'naming', 'unusedcode'], true)) {
                    $rulesets[$index] = self::resolveProjectFile($ruleset, $projectRoot, "phpmd.rulesets.{$index}");
                }
            }
            $configuration['rulesets'] = $rulesets;
        }
        if (array_key_exists('bootstrap', $configuration)) {
            if (!is_string($configuration['bootstrap']) || trim($configuration['bootstrap']) === '') {
                throw new \InvalidArgumentException('phpmd.bootstrap must be a non-empty file path.');
            }
            $configuration['bootstrap'] = self::resolveProjectFile(
                $configuration['bootstrap'],
                $projectRoot,
                'phpmd.bootstrap'
            );
        }
        return $configuration;
    }

    private static function resolveProjectFile(string $path, string $projectRoot, string $key): string
    {
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
        $resolved = realpath($absolute ? $path : $projectRoot . DIRECTORY_SEPARATOR . $path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \InvalidArgumentException("{$key} must reference a readable file: {$path}");
        }
        return $resolved;
    }

    private function getPhpmdConfiguration(string $key, mixed $default = null): mixed
    {
        return $this->configuration['phpmd'][$key] ?? $this->getConfiguration($key, $default);
    }

    private function requiresCompleteReport(): bool
    {
        return $this->configuration['phpmd'] !== [] || $this->getConfiguration('prefer_external_checks', false);
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        if ($this->getConfiguration('report_format') !== 'xml') {
            throw new AnalyzerException('PHPMD file recovery requires the supported XML report format.');
        }
        if (isset($this->configuration['phpmd']['bootstrap'])) {
            $process = $this->executeCommand([...$this->commandPrefix(), '--version']);
            if (!$process->isSuccessful()) {
                throw new AnalyzerException('PHPMD bootstrap preflight failed: ' . $process->getErrorOutput() . $process->getOutput());
            }
        }
        $results = [];
        $batchSize = $this->getConfiguration('batch_size', 25);
        foreach (array_chunk($files, $batchSize) as $batch) {
            $results = array_merge($results, $this->analyzeBatch($batch, $options));
        }
        if ($this->analyzedFiles === [] && $this->skippedFiles !== []) {
            $first = $this->skippedFiles[array_key_first($this->skippedFiles)];
            throw new AnalyzerException('PHPMD could not analyze any files. ' . $first['error'] . ' See skipped_files for individual failures.');
        }
        return $results;
    }

    private function analyzeBatch(array $files, array $options): array
    {
        try {
            $process = $this->executeCommand($this->buildCommand($files, $options));
        } catch (ProcessFailedException $e) {
            $process = $e->getProcess();
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            $bootstrap = $this->configuration['phpmd']['bootstrap'] ?? null;
            if ($process->getExitCode() !== 255 || preg_match('/(?:Fatal|Parse) error:/i', $error) !== 1
                || ($bootstrap !== null && str_contains($error, $bootstrap))) {
                throw $e;
            }
            return $this->retryOrSkip($files, $options, $error);
        } catch (ProcessTimedOutException $e) {
            return $this->retryOrSkip($files, $options, 'PHPMD timed out after ' . $e->getExceededTimeout() . ' seconds.');
        }

        $output = $process->getOutput();
        $doc = $this->loadXmlReport($output);
        $errors = (new \DOMXPath($doc))->query('//error');
        if ($errors->length > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $filename = $error->getAttribute('filename');
                if ($filename === '') {
                    throw new AnalyzerException('PHPMD returned a processing error without a filename: ' . $error->getAttribute('msg'));
                }
                $messages[] = $filename . ': ' . $error->getAttribute('msg');
            }
            return $this->retryOrSkip($files, $options, implode("\n", $messages));
        }
        if ($process->getErrorOutput() !== '') {
            $this->logger->warning('PHPMD stderr: ' . $process->getErrorOutput());
        }
        $this->analyzedFiles = array_merge($this->analyzedFiles, $files);
        return [$output];
    }

    private function retryOrSkip(array $files, array $options, string $error): array
    {
        if (count($files) > 1) {
            $this->logger->warning('Retrying failed PHPMD batch in smaller groups.', ['error' => $error]);
            $results = [];
            foreach (array_chunk($files, (int) ceil(count($files) / 2)) as $batch) {
                $results = array_merge($results, $this->analyzeBatch($batch, $options));
            }
            return $results;
        }
        $file = $files[0];
        $this->skippedFiles[$file] = ['file' => $file, 'error' => $error];
        $this->logger->error('Skipping file after PHPMD failure.', $this->skippedFiles[$file]);
        return [];
    }

    private function loadXmlReport(string $output): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            if (trim($output) === '' || !$doc->loadXML($output, LIBXML_NONET)) {
                $messages = array_map(static fn($error) => trim($error->message), libxml_get_errors());
                throw new AnalyzerException('PHPMD did not return a valid XML report. ' . implode('; ', $messages));
            }
            if ($doc->documentElement?->tagName !== 'pmd') {
                throw new AnalyzerException('PHPMD did not return a valid XML report root.');
            }
            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
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
            throw $e;
        }

        return $violations;
    }

    private function parseXmlOutput(string $xmlOutput): array
    {
        $violations = [];

        try {
            $doc = $this->loadXmlReport($xmlOutput);
            $xpath = new \DOMXPath($doc);
            if ($xpath->query('//error')->length > 0) {
                throw new AnalyzerException('PHPMD returned an invalid or incomplete analysis report.');
            }
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
        }

        return $violations;
    }

    private function parseJsonOutput(string $jsonOutput): array
    {
        if ($this->getConfiguration('prefer_external_checks', false)) {
            throw new AnalyzerException('Complete PHPMD coverage requires the supported XML report format.');
        }
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

        $ruleId = $ruleset . '.' . $rule;
        $severity = $this->configuration['phpmd']['rule_severity_overrides'][$this->getName() . '.' . $ruleId]
            ?? $this->mapPhpmdPriority($priority);
        $violationType = $this->mapRulesetToType($ruleset);

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

    private function commandPrefix(): array
    {
        $command = $this->usesBundledPhpmd ? [PHP_BINARY, $this->phpmdCommand] : [$this->phpmdCommand];
        $bootstrap = $this->configuration['phpmd']['bootstrap'] ?? null;
        if ($bootstrap !== null) {
            $executable = is_file($this->phpmdCommand)
                ? realpath($this->phpmdCommand)
                : (new ExecutableFinder())->find($this->phpmdCommand);
            if (!is_string($executable)) {
                throw new AnalyzerException('PHPMD executable was not found for the configured bootstrap.');
            }
            $command = [PHP_BINARY, '-d', 'auto_prepend_file=' . $bootstrap, $executable];
        }
        return $command;
    }

    private function buildCommand(array $files, array $options = []): array
    {
        $command = $this->commandPrefix();
        if (count($files) === 1 && is_dir($files[0])) {
            $command[] = $files[0];
        } else {
            $command[] = implode(',', $files);
        }

        $reportFormat = $this->getConfiguration('report_format', 'xml');
        $command[] = $reportFormat;

        $rulesets = $this->getPhpmdConfiguration('rulesets', []);
        if (!empty($rulesets)) {
            $command[] = implode(',', $rulesets);
        }

        $minPriority = $this->getPhpmdConfiguration('minimum_priority');
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
        if (isset($this->configuration['phpmd']['severity_mapping'][$priority])) {
            return $this->configuration['phpmd']['severity_mapping'][$priority];
        }
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
        $this->usesBundledPhpmd = false;
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
        $rulesets = $config['rulesets'] ?? $this->getPhpmdConfiguration('rulesets', ['cleancode', 'codesize']);
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
            } elseif (is_file($ruleset)) {
                $reference = htmlspecialchars($ruleset, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xmlContent .= "  <rule ref=\"{$reference}\"/>\n";
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

    protected function isExpectedFailure(Process $process): bool
    {
        return in_array($process->getExitCode(), [0, 2, 3], true);
    }
} 