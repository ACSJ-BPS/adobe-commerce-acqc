<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class SonarAnalyzer extends AbstractAnalyzer
{
    private string $sonarScannerCommand = 'sonar-scanner';
    private ?string $projectKey = null;
    private ?string $serverUrl = null;
    private ?string $token = null;
    private ?string $projectDir = null;

    public function getName(): string
    {
        return 'sonar';
    }

    public function getVersion(): string
    {
        return $this->getToolVersion($this->sonarScannerCommand, ['-v']);
    }

    public function isAvailable(): bool
    {
        return $this->commandExists($this->sonarScannerCommand);
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'server_url' => 'http://localhost:9000',
            'project_key' => null,
            'project_name' => 'ACQC Analysis',
            'project_version' => '1.0',
            'sources' => ['.'],
            'exclusions' => [
                '**/vendor/**',
                '**/node_modules/**',
                '**/var/**',
                '**/generated/**',
                '**/pub/static/**',
                '**/tests/**',
                '**/*Test.php',
            ],
            'inclusions' => ['**/*.php'],
            'encoding' => 'UTF-8',
            'language' => 'php',
            'profile' => 'Sonar way',
            'timeout' => 600,
            'verbose' => false,
            'debug' => false,
            'working_dir' => null,
            'properties_file' => null,
            'additional_properties' => [],
            'quality_gate_wait' => false,
            'pull_request' => [
                'enabled' => false,
                'key' => null,
                'branch' => null,
                'base' => 'main',
            ],
            'coverage_reports' => [],
            'test_reports' => [],
            'rules' => [
                'enabled' => [],
                'disabled' => [],
                'parameters' => [],
            ],
        ];
    }

    protected function validateConfiguration(): void
    {
        parent::validateConfiguration();

        // Validate required configurations
        $projectKey = $this->getConfiguration('project_key');
        if (empty($projectKey)) {
            throw new AnalyzerException("Project key is required for SonarQube analysis");
        }

        $serverUrl = $this->getConfiguration('server_url');
        if (empty($serverUrl)) {
            throw new AnalyzerException("Server URL is required for SonarQube analysis");
        }

        // Validate sources
        $sources = $this->getConfiguration('sources', []);
        if (empty($sources)) {
            throw new AnalyzerException("At least one source directory must be specified");
        }

        foreach ($sources as $source) {
            if (!file_exists($source) && !is_dir($source)) {
                $this->logger->warning("Source path does not exist: {$source}");
            }
        }

        // Store key configurations
        $this->projectKey = $projectKey;
        $this->serverUrl = $serverUrl;
        $this->token = $this->getConfiguration('token');
        $this->projectDir = $this->getConfiguration('working_dir') ?: $this->workingDirectory;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $results = [];

        try {
            // Generate sonar-project.properties file
            $propertiesFile = $this->generatePropertiesFile($files);

            // Build and execute SonarScanner command
            $command = $this->buildCommand($files, $options, $propertiesFile);
            $process = $this->executeCommand($command);
            
            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            // SonarScanner outputs to both stdout and stderr
            if (!empty($output)) {
                $results[] = $output;
            }

            if (!empty($errorOutput)) {
                $this->logger->debug("SonarScanner stderr: " . $errorOutput);
            }

            // Get results from SonarQube API if analysis was successful
            if ($process->isSuccessful()) {
                $apiResults = $this->fetchResultsFromApi();
                if (!empty($apiResults)) {
                    $results[] = $apiResults;
                }
            }

            // Clean up temporary properties file
            if (file_exists($propertiesFile)) {
                unlink($propertiesFile);
            }

        } catch (\Exception $e) {
            $this->logger->error("SonarQube analysis failed: " . $e->getMessage());
            throw $e;
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
            // Try to parse as JSON (API response)
            if ($this->isJsonOutput($output)) {
                $violations = $this->parseJsonOutput($output);
            } else {
                // Parse console output for basic information
                $violations = $this->parseConsoleOutput($output, $files);
            }

        } catch (\Exception $e) {
            $this->logger->error("Failed to parse SonarQube output: " . $e->getMessage());
            $this->logger->debug("Raw output: " . substr($output, 0, 1000));
        }

        return $violations;
    }

    /**
     * Parse JSON output from SonarQube API
     */
    private function parseJsonOutput(string $jsonOutput): array
    {
        $violations = [];

        try {
            $data = json_decode($jsonOutput, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AnalyzerException("Invalid JSON output from SonarQube: " . json_last_error_msg());
            }

            // Handle issues response
            if (isset($data['issues']) && is_array($data['issues'])) {
                foreach ($data['issues'] as $issue) {
                    $violation = $this->createViolationFromIssue($issue);
                    if ($violation) {
                        $violations[] = $violation;
                    }
                }
            }

            // Handle components response
            if (isset($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $component) {
                    if (isset($component['issues'])) {
                        foreach ($component['issues'] as $issue) {
                            $violation = $this->createViolationFromIssue($issue);
                            if ($violation) {
                                $violations[] = $violation;
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            throw new AnalyzerException("Failed to parse SonarQube JSON output: " . $e->getMessage());
        }

        return $violations;
    }

    /**
     * Parse console output from SonarScanner
     */
    private function parseConsoleOutput(string $output, array $files): array
    {
        $violations = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Look for analysis results summary
            if (preg_match('/(\d+)\s+(bug|vulnerability|code smell|security hotspot)s?\s+found/i', $line, $matches)) {
                $count = (int) $matches[1];
                $type = strtolower($matches[2]);
                
                if ($count > 0) {
                    // Create a summary violation
                    $violation = $this->createViolation(
                        'summary.' . str_replace(' ', '_', $type),
                        "Found {$count} {$type}(s) in the codebase",
                        'project',
                        0,
                        0,
                        $this->mapSonarTypeToSeverity($type),
                        $this->mapSonarTypeToViolationType($type),
                        [
                            'count' => $count,
                            'sonar_type' => $type,
                            'is_summary' => true,
                        ]
                    );

                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    /**
     * Create violation from SonarQube issue
     */
    private function createViolationFromIssue(array $issue): ?Violation
    {
        if (!isset($issue['message']) || !isset($issue['rule'])) {
            return null;
        }

        $ruleKey = $issue['rule'];
        $message = $issue['message'];
        $component = $issue['component'] ?? '';
        $severity = strtolower($issue['severity'] ?? 'major');
        $type = strtolower($issue['type'] ?? 'code_smell');
        $line = (int) ($issue['line'] ?? 0);
        $effort = $issue['effort'] ?? null;
        $debt = $issue['debt'] ?? null;
        $status = $issue['status'] ?? 'open';
        $tags = $issue['tags'] ?? [];

        // Extract file path from component
        $filePath = $this->extractFilePathFromComponent($component);

        // Map SonarQube severity and type to our format
        $violationSeverity = $this->mapSonarSeverityToViolation($severity);
        $violationType = $this->mapSonarTypeToViolationType($type);

        $violation = $this->createViolation(
            $ruleKey,
            $message,
            $filePath,
            $line,
            0,
            $violationSeverity,
            $violationType,
            [
                'sonar_severity' => $severity,
                'sonar_type' => $type,
                'sonar_effort' => $effort,
                'sonar_debt' => $debt,
                'sonar_status' => $status,
                'sonar_tags' => $tags,
                'sonar_component' => $component,
            ]
        );

        // Add source context if available
        if ($line > 0 && !empty($filePath) && file_exists($filePath)) {
            $context = $this->extractSourceContext($filePath, $line, 2);
            if (!empty($context)) {
                $violation->setSource($context[$line] ?? '');
                $violation->addContext('source_context', $context);
            }
        }

        // Add suggestion based on rule type
        $suggestion = $this->getSuggestionForRule($ruleKey, $type);
        if ($suggestion) {
            $violation->setSuggestion($suggestion);
        }

        return $violation;
    }

    /**
     * Build SonarScanner command
     */
    private function buildCommand(array $files, array $options = [], ?string $propertiesFile = null): array
    {
        $command = [$this->sonarScannerCommand];

        // Add properties file if created
        if ($propertiesFile && file_exists($propertiesFile)) {
            $command[] = '-Dproject.settings=' . $propertiesFile;
        }

        // Add server URL
        $command[] = '-Dsonar.host.url=' . $this->serverUrl;

        // Add project key
        $command[] = '-Dsonar.projectKey=' . $this->projectKey;

        // Add authentication token if available
        if ($this->token) {
            $command[] = '-Dsonar.login=' . $this->token;
        }

        // Add verbose flag if needed
        if ($this->getConfiguration('verbose', false)) {
            $command[] = '-X';
        }

        // Add debug flag if needed
        if ($this->getConfiguration('debug', false)) {
            $command[] = '-Dsonar.verbose=true';
        }

        // Add working directory
        if ($this->projectDir) {
            $command[] = '-Dsonar.projectBaseDir=' . $this->projectDir;
        }

        // Add additional properties
        $additionalProperties = $this->getConfiguration('additional_properties', []);
        foreach ($additionalProperties as $key => $value) {
            $command[] = "-D{$key}={$value}";
        }

        return $command;
    }

    /**
     * Generate sonar-project.properties file
     */
    private function generatePropertiesFile(array $files): string
    {
        $propertiesFile = tempnam(sys_get_temp_dir(), 'sonar_project_') . '.properties';
        
        $properties = $this->buildProperties($files);
        file_put_contents($propertiesFile, $properties);
        
        return $propertiesFile;
    }

    /**
     * Build properties file content
     */
    private function buildProperties(array $files): string
    {
        $properties = [];

        // Basic project information
        $properties['sonar.projectKey'] = $this->projectKey;
        $properties['sonar.projectName'] = $this->getConfiguration('project_name', 'ACQC Analysis');
        $properties['sonar.projectVersion'] = $this->getConfiguration('project_version', '1.0');

        // Sources and language
        $sources = $this->getConfiguration('sources', ['.']);
        $properties['sonar.sources'] = implode(',', $sources);
        $properties['sonar.language'] = $this->getConfiguration('language', 'php');
        $properties['sonar.sourceEncoding'] = $this->getConfiguration('encoding', 'UTF-8');

        // Exclusions and inclusions
        $exclusions = $this->getConfiguration('exclusions', []);
        if (!empty($exclusions)) {
            $properties['sonar.exclusions'] = implode(',', $exclusions);
        }

        $inclusions = $this->getConfiguration('inclusions', []);
        if (!empty($inclusions)) {
            $properties['sonar.inclusions'] = implode(',', $inclusions);
        }

        // Coverage reports
        $coverageReports = $this->getConfiguration('coverage_reports', []);
        if (!empty($coverageReports)) {
            $properties['sonar.php.coverage.reportPaths'] = implode(',', $coverageReports);
        }

        // Test reports
        $testReports = $this->getConfiguration('test_reports', []);
        if (!empty($testReports)) {
            $properties['sonar.php.tests.reportPath'] = implode(',', $testReports);
        }

        // Quality profile
        $profile = $this->getConfiguration('profile');
        if ($profile) {
            $properties['sonar.profile'] = $profile;
        }

        // Pull request configuration
        $pullRequest = $this->getConfiguration('pull_request', []);
        if (!empty($pullRequest['enabled']) && !empty($pullRequest['key'])) {
            $properties['sonar.pullrequest.key'] = $pullRequest['key'];
            $properties['sonar.pullrequest.branch'] = $pullRequest['branch'] ?? 'feature';
            $properties['sonar.pullrequest.base'] = $pullRequest['base'] ?? 'main';
        }

        // Rule configurations
        $rules = $this->getConfiguration('rules', []);
        if (!empty($rules['disabled'])) {
            foreach ($rules['disabled'] as $rule) {
                $properties["sonar.issue.ignore.multicriteria.{$rule}.ruleKey"] = $rule;
                $properties["sonar.issue.ignore.multicriteria.{$rule}.resourceKey"] = '**/*';
            }
        }

        // Convert array to properties format
        $propertiesContent = '';
        foreach ($properties as $key => $value) {
            $propertiesContent .= "{$key}={$value}\n";
        }

        return $propertiesContent;
    }

    /**
     * Fetch results from SonarQube API
     */
    private function fetchResultsFromApi(): ?string
    {
        if (!$this->projectKey || !$this->serverUrl) {
            return null;
        }

        try {
            $apiUrl = rtrim($this->serverUrl, '/') . '/api/issues/search';
            $params = [
                'componentKeys' => $this->projectKey,
                'ps' => 500, // Page size
                'p' => 1,    // Page number
            ];

            $url = $apiUrl . '?' . http_build_query($params);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $this->buildApiHeaders(),
                    'timeout' => 30,
                ],
            ]);

            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                $this->logger->warning("Failed to fetch results from SonarQube API");
                return null;
            }

            return $response;

        } catch (\Exception $e) {
            $this->logger->warning("Error fetching SonarQube API results: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build API headers for authentication
     */
    private function buildApiHeaders(): string
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->token) {
            $auth = base64_encode($this->token . ':');
            $headers[] = 'Authorization: Basic ' . $auth;
        }

        return implode("\r\n", $headers);
    }

    /**
     * Map SonarQube severity to violation severity
     */
    private function mapSonarSeverityToViolation(string $severity): string
    {
        return match (strtolower($severity)) {
            'blocker' => Violation::SEVERITY_CRITICAL,
            'critical' => Violation::SEVERITY_CRITICAL,
            'major' => Violation::SEVERITY_ERROR,
            'minor' => Violation::SEVERITY_WARNING,
            'info' => Violation::SEVERITY_INFO,
            default => Violation::SEVERITY_WARNING,
        };
    }

    /**
     * Map SonarQube type to violation type
     */
    private function mapSonarTypeToViolationType(string $type): string
    {
        return match (strtolower($type)) {
            'bug' => Violation::TYPE_LOGIC,
            'vulnerability' => Violation::TYPE_SECURITY,
            'security_hotspot' => Violation::TYPE_SECURITY,
            'code_smell' => Violation::TYPE_MAINTAINABILITY,
            default => Violation::TYPE_STYLE,
        };
    }

    /**
     * Map SonarQube type to severity for summaries
     */
    private function mapSonarTypeToSeverity(string $type): string
    {
        return match (strtolower($type)) {
            'bug' => Violation::SEVERITY_ERROR,
            'vulnerability' => Violation::SEVERITY_CRITICAL,
            'security hotspot' => Violation::SEVERITY_ERROR,
            'code smell' => Violation::SEVERITY_WARNING,
            default => Violation::SEVERITY_INFO,
        };
    }

    /**
     * Extract file path from SonarQube component
     */
    private function extractFilePathFromComponent(string $component): string
    {
        // Component format is usually: projectKey:filePath
        if (strpos($component, ':') !== false) {
            return substr($component, strpos($component, ':') + 1);
        }
        return $component;
    }

    /**
     * Get suggestion for SonarQube rule
     */
    private function getSuggestionForRule(string $ruleKey, string $type): ?string
    {
        // Common SonarQube PHP rules and suggestions
        $suggestions = [
            'php:S1192' => 'Extract this duplicated string literal into a constant',
            'php:S1301' => 'Replace this switch statement with if-else statements',
            'php:S138' => 'This function has too many lines. Consider splitting it into smaller functions',
            'php:S104' => 'This file has too many lines. Consider splitting it into smaller files',
            'php:S1479' => 'Reduce the number of switch cases from current to maximum allowed',
            'php:S1541' => 'Reduce the cyclomatic complexity of this function',
            'php:S1151' => 'Reduce the depth of nested blocks in this function',
            'php:S1067' => 'Reduce the number of conditional operators in this expression',
            'php:S117' => 'Rename this variable to match the regular expression',
            'php:S101' => 'Rename this class to match the regular expression',
            'php:S100' => 'Rename this function to match the regular expression',
            'php:S1172' => 'Remove this unused function parameter',
            'php:S1481' => 'Remove this unused local variable',
            'php:S1068' => 'Remove this unused private field',
            'php:S1144' => 'Remove this unused private method',
        ];

        if (isset($suggestions[$ruleKey])) {
            return $suggestions[$ruleKey];
        }

        // Generic suggestions based on type
        return match (strtolower($type)) {
            'bug' => 'Review and fix this potential bug to prevent runtime errors',
            'vulnerability' => 'Address this security vulnerability to protect against attacks',
            'security_hotspot' => 'Review this security-sensitive code and ensure it\'s secure',
            'code_smell' => 'Refactor this code to improve maintainability and readability',
            default => 'Review and improve this code based on SonarQube recommendations',
        };
    }

    /**
     * Check if output is JSON format
     */
    private function isJsonOutput(string $output): bool
    {
        return strpos(trim($output), '{') === 0 || strpos(trim($output), '[') === 0;
    }

    /**
     * Set SonarScanner command path
     */
    public function setCommand(string $command): self
    {
        $this->sonarScannerCommand = $command;
        return $this;
    }

    /**
     * Set SonarQube project configuration
     */
    public function setProject(string $key, string $serverUrl, ?string $token = null): self
    {
        $this->projectKey = $key;
        $this->serverUrl = $serverUrl;
        $this->token = $token;
        return $this;
    }

    /**
     * Generate SonarQube properties file
     */
    public function generatePropertiesFileAt(string $outputPath, array $config = []): bool
    {
        $mergedConfig = array_merge($this->getConfiguration(), $config);
        $this->configuration = $mergedConfig;
        
        $properties = $this->buildProperties([]);

        try {
            file_put_contents($outputPath, $properties);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to generate SonarQube properties file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Override to handle SonarScanner-specific exit codes
     */
    protected function isExpectedFailure(\Symfony\Component\Process\Process $process): bool
    {
        // SonarScanner returns:
        // 0 = success
        // 1 = analysis failed (but may have results)
        // 2 = fatal error
        return in_array($process->getExitCode(), [0, 1]);
    }
} 