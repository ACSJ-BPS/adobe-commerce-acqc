# Extending M2-Quality

Guide for creating custom analyzers, rules, report generators, and integrations.

## 🏗️ Architecture Overview

M2-Quality is built with extensibility in mind:

```
Extension Points:
├── Analyzers/          # Custom code analysis engines
├── Rules/              # Custom quality rules
├── Reports/            # Custom report generators
├── Commands/           # Custom CLI commands
├── AI/                 # Custom AI providers
└── Integrations/       # External service integrations
```

## 🔍 Custom Analyzers

### Creating a Basic Analyzer

```php
<?php

namespace MyCompany\M2Quality\Analyzers;

use M2Quality\Analyzers\AbstractAnalyzer;
use M2Quality\Core\Scanner\ScanContext;
use M2Quality\Core\Scanner\ScanResult;
use M2Quality\Core\Rules\Violation;

class CustomSecurityAnalyzer extends AbstractAnalyzer
{
    public function getName(): string
    {
        return 'custom-security';
    }

    public function getDescription(): string
    {
        return 'Custom security analyzer for company-specific rules';
    }

    public function supports(string $fileExtension): bool
    {
        return in_array($fileExtension, ['.php', '.phtml']);
    }

    public function analyze(ScanContext $context): ScanResult
    {
        $violations = [];
        $metrics = [];

        foreach ($context->getFiles() as $file) {
            $fileViolations = $this->analyzeFile($file);
            $violations = array_merge($violations, $fileViolations);
        }

        return new ScanResult($violations, true, $metrics);
    }

    private function analyzeFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            // Check for hardcoded passwords
            if (preg_match('/password\s*=\s*[\'"][^\'"]{1,}[\'"]/', $line)) {
                $violations[] = new Violation(
                    'hardcoded-password',
                    'Hardcoded password detected',
                    $filePath,
                    $lineNumber + 1,
                    'critical'
                );
            }

            // Check for SQL injection patterns
            if (preg_match('/\$.*query.*\$.*/', $line)) {
                $violations[] = new Violation(
                    'sql-injection-risk',
                    'Potential SQL injection risk',
                    $filePath,
                    $lineNumber + 1,
                    'error'
                );
            }
        }

        return $violations;
    }
}
```

### Advanced Analyzer with Configuration

```php
class AdvancedCustomAnalyzer extends AbstractAnalyzer
{
    private array $config;
    private array $patterns;

    public function configure(array $config): void
    {
        $this->config = array_merge([
            'severity_mapping' => [
                'hardcoded_secret' => 'critical',
                'weak_crypto' => 'error',
                'deprecated_function' => 'warning'
            ],
            'custom_patterns' => [],
            'exclude_patterns' => []
        ], $config);

        $this->loadPatterns();
    }

    public function analyze(ScanContext $context): ScanResult
    {
        $violations = [];
        $metrics = [
            'files_analyzed' => 0,
            'patterns_matched' => 0,
            'security_score' => 10.0
        ];

        foreach ($context->getFiles() as $file) {
            if ($this->shouldSkipFile($file)) {
                continue;
            }

            $fileViolations = $this->analyzeFileWithPatterns($file);
            $violations = array_merge($violations, $fileViolations);
            $metrics['files_analyzed']++;
        }

        $metrics['security_score'] = $this->calculateSecurityScore($violations);

        return new ScanResult($violations, true, $metrics);
    }

    private function loadPatterns(): void
    {
        $this->patterns = [
            'hardcoded_secret' => [
                '/api[_-]?key\s*=\s*[\'"][^\'"]{10,}[\'"]/',
                '/secret[_-]?key\s*=\s*[\'"][^\'"]{10,}[\'"]/',
                '/password\s*=\s*[\'"][^\'"]{3,}[\'"]/'
            ],
            'weak_crypto' => [
                '/md5\s*\(/',
                '/sha1\s*\(/',
                '/base64_encode\s*\(.*password/'
            ],
            'deprecated_function' => [
                '/mysql_query\s*\(/',
                '/split\s*\(/',
                '/ereg\s*\(/'
            ]
        ];

        // Add custom patterns from config
        foreach ($this->config['custom_patterns'] as $type => $patterns) {
            $this->patterns[$type] = array_merge($this->patterns[$type] ?? [], $patterns);
        }
    }

    private function analyzeFileWithPatterns(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($this->patterns as $violationType => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($lines as $lineNumber => $line) {
                    if (preg_match($pattern, $line)) {
                        $violations[] = new Violation(
                            $violationType,
                            $this->getViolationMessage($violationType),
                            $filePath,
                            $lineNumber + 1,
                            $this->config['severity_mapping'][$violationType] ?? 'warning'
                        );
                    }
                }
            }
        }

        return $violations;
    }
}
```

## 📋 Custom Rules

### Simple Rule Implementation

```php
<?php

namespace MyCompany\M2Quality\Rules;

use M2Quality\Core\Rules\AbstractRule;
use M2Quality\Core\Rules\Violation;

class NoObjectManagerRule extends AbstractRule
{
    public function getName(): string
    {
        return 'no-object-manager';
    }

    public function getDescription(): string
    {
        return 'Prohibits direct usage of ObjectManager';
    }

    public function check(string $filePath, string $content): array
    {
        $violations = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            if (strpos($line, 'ObjectManager::getInstance()') !== false) {
                $violations[] = new Violation(
                    $this->getName(),
                    'Direct ObjectManager usage is prohibited. Use dependency injection instead.',
                    $filePath,
                    $lineNumber + 1,
                    'error'
                );
            }
        }

        return $violations;
    }
}
```

### Complex Rule with AST Analysis

```php
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class ComplexMagentoRule extends AbstractRule
{
    private $parser;
    private $traverser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor(new MagentoRuleVisitor());
    }

    public function check(string $filePath, string $content): array
    {
        try {
            $ast = $this->parser->parse($content);
            $visitor = new MagentoRuleVisitor($filePath);
            
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            return $visitor->getViolations();
        } catch (\Exception $e) {
            return [];
        }
    }
}

class MagentoRuleVisitor extends NodeVisitorAbstract
{
    private array $violations = [];
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function enterNode(\PhpParser\Node $node)
    {
        // Check for direct ObjectManager usage
        if ($node instanceof \PhpParser\Node\Expr\StaticCall) {
            if ($node->class instanceof \PhpParser\Node\Name 
                && $node->class->toString() === 'ObjectManager'
                && $node->name instanceof \PhpParser\Node\Identifier
                && $node->name->toString() === 'getInstance') {
                
                $this->violations[] = new Violation(
                    'object-manager-usage',
                    'Direct ObjectManager usage detected',
                    $this->filePath,
                    $node->getLine(),
                    'error'
                );
            }
        }

        // Check for missing type hints
        if ($node instanceof \PhpParser\Node\Param && $node->type === null) {
            $this->violations[] = new Violation(
                'missing-type-hint',
                'Parameter missing type hint',
                $this->filePath,
                $node->getLine(),
                'warning'
            );
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }
}
```

## 📊 Custom Report Generators

### Basic Report Generator

```php
<?php

namespace MyCompany\M2Quality\Reports;

use M2Quality\Reports\Generators\AbstractReportGenerator;

class CustomHTMLReportGenerator extends AbstractReportGenerator
{
    public function generate(array $violations, array $options = []): string
    {
        $template = $this->loadTemplate($options['template'] ?? 'custom');
        
        $data = [
            'violations' => $violations,
            'summary' => $this->generateSummary($violations),
            'charts' => $this->generateCharts($violations),
            'recommendations' => $this->generateRecommendations($violations),
            'generated_at' => date('Y-m-d H:i:s')
        ];

        return $this->renderTemplate($template, $data);
    }

    private function generateSummary(array $violations): array
    {
        $summary = [
            'total' => count($violations),
            'critical' => 0,
            'error' => 0,
            'warning' => 0,
            'info' => 0
        ];

        foreach ($violations as $violation) {
            $severity = $violation->getSeverity();
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }

        return $summary;
    }

    private function generateCharts(array $violations): array
    {
        // Group violations by file, severity, rule type
        $charts = [
            'by_severity' => [],
            'by_file' => [],
            'by_rule' => []
        ];

        foreach ($violations as $violation) {
            // By severity
            $severity = $violation->getSeverity();
            $charts['by_severity'][$severity] = ($charts['by_severity'][$severity] ?? 0) + 1;

            // By file
            $file = basename($violation->getFile());
            $charts['by_file'][$file] = ($charts['by_file'][$file] ?? 0) + 1;

            // By rule
            $rule = $violation->getRule();
            $charts['by_rule'][$rule] = ($charts['by_rule'][$rule] ?? 0) + 1;
        }

        return $charts;
    }

    private function renderTemplate(string $template, array $data): string
    {
        ob_start();
        extract($data);
        include $template;
        return ob_get_clean();
    }
}
```

### Excel Report Generator

```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelReportGenerator extends AbstractReportGenerator
{
    public function generate(array $violations, array $options = []): string
    {
        $spreadsheet = new Spreadsheet();
        
        // Summary sheet
        $this->createSummarySheet($spreadsheet, $violations);
        
        // Violations sheet
        $this->createViolationsSheet($spreadsheet, $violations);
        
        // Charts sheet
        $this->createChartsSheet($spreadsheet, $violations);

        // Save to temporary file and return content
        $tempFile = tempnam(sys_get_temp_dir(), 'quality_report_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
    }

    private function createSummarySheet(Spreadsheet $spreadsheet, array $violations): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');

        // Headers
        $sheet->setCellValue('A1', 'Quality Report Summary');
        $sheet->setCellValue('A3', 'Total Violations');
        $sheet->setCellValue('B3', count($violations));

        // Severity breakdown
        $severityCounts = [];
        foreach ($violations as $violation) {
            $severity = $violation->getSeverity();
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;
        }

        $row = 5;
        foreach ($severityCounts as $severity => $count) {
            $sheet->setCellValue("A$row", ucfirst($severity));
            $sheet->setCellValue("B$row", $count);
            $row++;
        }
    }

    private function createViolationsSheet(Spreadsheet $spreadsheet, array $violations): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Violations');

        // Headers
        $headers = ['File', 'Line', 'Rule', 'Message', 'Severity'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
        }

        // Data
        $row = 2;
        foreach ($violations as $violation) {
            $sheet->setCellValueByColumnAndRow(1, $row, $violation->getFile());
            $sheet->setCellValueByColumnAndRow(2, $row, $violation->getLine());
            $sheet->setCellValueByColumnAndRow(3, $row, $violation->getRule());
            $sheet->setCellValueByColumnAndRow(4, $row, $violation->getMessage());
            $sheet->setCellValueByColumnAndRow(5, $row, $violation->getSeverity());
            $row++;
        }
    }
}
```

## 🤖 Custom AI Providers

### Custom AI Provider Implementation

```php
<?php

namespace MyCompany\M2Quality\AI;

use M2Quality\Core\AI\AIProviderInterface;

class CustomAIProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $endpoint;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->apiKey = $config['api_key'];
        $this->endpoint = $config['endpoint'];
    }

    public function generateResponse(string $prompt, array $context = []): array
    {
        $payload = [
            'prompt' => $this->buildPrompt($prompt, $context),
            'max_tokens' => $this->config['max_tokens'] ?? 1000,
            'temperature' => $this->config['temperature'] ?? 0.3
        ];

        $response = $this->makeRequest($this->endpoint, $payload);
        
        return [
            'content' => $response['choices'][0]['text'] ?? '',
            'usage' => $response['usage'] ?? [],
            'provider' => 'custom'
        ];
    }

    public function explainCode(string $code, array $options = []): array
    {
        $prompt = "Explain this code and identify potential issues:\n\n" . $code;
        return $this->generateResponse($prompt, $options);
    }

    public function suggestFixes(string $code, array $violations = []): array
    {
        $prompt = "Suggest fixes for the following code issues:\n\n";
        $prompt .= "Code:\n" . $code . "\n\n";
        $prompt .= "Issues:\n";
        
        foreach ($violations as $violation) {
            $prompt .= "- " . $violation->getMessage() . "\n";
        }

        return $this->generateResponse($prompt);
    }

    private function buildPrompt(string $prompt, array $context): string
    {
        $fullPrompt = "You are a Magento 2 code quality expert. ";
        
        if (isset($context['role'])) {
            $fullPrompt .= "Respond from the perspective of a " . $context['role'] . ". ";
        }
        
        $fullPrompt .= "\n\n" . $prompt;
        
        if (isset($context['code'])) {
            $fullPrompt .= "\n\nCode context:\n" . $context['code'];
        }

        return $fullPrompt;
    }

    private function makeRequest(string $url, array $data): array
    {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 200) {
            throw new \Exception("AI API request failed with code: $httpCode");
        }

        return json_decode($response, true);
    }
}
```

## 🔧 Custom Commands

### Creating Custom CLI Commands

```php
<?php

namespace MyCompany\M2Quality\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CustomAnalysisCommand extends Command
{
    protected static $defaultName = 'custom:analyze';
    protected static $defaultDescription = 'Run custom company-specific analysis';

    protected function configure(): void
    {
        $this
            ->setDescription('Custom analysis for company standards')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to analyze')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format', 'console')
            ->addOption('severity', 's', InputOption::VALUE_REQUIRED, 'Minimum severity', 'warning')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $path = $input->getArgument('path');
        $format = $input->getOption('format');
        $severity = $input->getOption('severity');

        $io->title('Custom Company Analysis');

        // Initialize custom analyzers
        $analyzer = new CustomSecurityAnalyzer();
        $ruleEngine = new CustomRuleEngine();

        // Run analysis
        $io->text('Running custom security analysis...');
        $results = $analyzer->analyze($path);

        // Apply custom rules
        $io->text('Applying company-specific rules...');
        $filteredResults = $ruleEngine->filterBySeverity($results, $severity);

        // Generate output
        $this->generateOutput($filteredResults, $format, $input->getOption('output'), $io);

        $io->success('Custom analysis completed!');
        
        return Command::SUCCESS;
    }

    private function generateOutput(array $results, string $format, ?string $outputFile, SymfonyStyle $io): void
    {
        switch ($format) {
            case 'json':
                $output = json_encode($results, JSON_PRETTY_PRINT);
                break;
            case 'csv':
                $output = $this->generateCSV($results);
                break;
            default:
                $this->displayConsoleOutput($results, $io);
                return;
        }

        if ($outputFile) {
            file_put_contents($outputFile, $output);
            $io->info("Results saved to: $outputFile");
        } else {
            $io->writeln($output);
        }
    }
}
```

## 🔌 Plugin System

### Plugin Interface

```php
<?php

namespace MyCompany\M2Quality\Plugins;

interface PluginInterface
{
    public function getName(): string;
    public function getVersion(): string;
    public function initialize(array $config): void;
    public function beforeScan(ScanContext $context): void;
    public function afterScan(ScanResult $result): void;
}
```

### Sample Plugin Implementation

```php
class SlackNotificationPlugin implements PluginInterface
{
    private array $config;
    private string $webhookUrl;

    public function getName(): string
    {
        return 'slack-notifications';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function initialize(array $config): void
    {
        $this->config = $config;
        $this->webhookUrl = $config['webhook_url'];
    }

    public function beforeScan(ScanContext $context): void
    {
        if ($this->config['notify_on_start'] ?? false) {
            $this->sendSlackMessage("🔍 Starting code quality scan for: " . $context->getPath());
        }
    }

    public function afterScan(ScanResult $result): void
    {
        $violations = $result->getViolations();
        $criticalCount = count(array_filter($violations, fn($v) => $v->getSeverity() === 'critical'));

        if ($criticalCount > 0 || ($this->config['always_notify'] ?? false)) {
            $message = $this->formatSlackMessage($result);
            $this->sendSlackMessage($message);
        }
    }

    private function formatSlackMessage(ScanResult $result): string
    {
        $violations = $result->getViolations();
        $total = count($violations);
        $critical = count(array_filter($violations, fn($v) => $v->getSeverity() === 'critical'));
        $errors = count(array_filter($violations, fn($v) => $v->getSeverity() === 'error'));

        $emoji = $critical > 0 ? '🚨' : ($errors > 0 ? '⚠️' : '✅');
        
        return "$emoji Code Quality Scan Complete\n" .
               "Total Issues: $total\n" .
               "Critical: $critical | Errors: $errors";
    }

    private function sendSlackMessage(string $message): void
    {
        $payload = json_encode(['text' => $message]);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->webhookUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        
        curl_exec($curl);
        curl_close($curl);
    }
}
```

## 📦 Creating Extension Packages

### Package Structure

```
my-m2quality-extension/
├── composer.json
├── README.md
├── src/
│   ├── Analyzers/
│   ├── Rules/
│   ├── Reports/
│   └── Plugins/
├── config/
│   └── services.yaml
├── templates/
└── tests/
```

### Composer Configuration

```json
{
    "name": "mycompany/m2quality-custom-extension",
    "description": "Custom M2-Quality extensions for company standards",
    "type": "m2quality-extension",
    "require": {
        "php": ">=8.1",
        "m2quality/m2-quality": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "MyCompany\\M2Quality\\": "src/"
        }
    },
    "extra": {
        "m2quality": {
            "analyzers": [
                "MyCompany\\M2Quality\\Analyzers\\CustomSecurityAnalyzer",
                "MyCompany\\M2Quality\\Analyzers\\CompanyStandardsAnalyzer"
            ],
            "rules": [
                "MyCompany\\M2Quality\\Rules\\NoObjectManagerRule",
                "MyCompany\\M2Quality\\Rules\\CompanyNamingRule"
            ],
            "reports": [
                "MyCompany\\M2Quality\\Reports\\CompanyReportGenerator"
            ],
            "plugins": [
                "MyCompany\\M2Quality\\Plugins\\SlackNotificationPlugin"
            ]
        }
    }
}
```

### Extension Registration

```php
<?php

namespace MyCompany\M2Quality;

use M2Quality\Core\ExtensionInterface;
use M2Quality\Core\ExtensionRegistry;

class CustomExtension implements ExtensionInterface
{
    public function register(ExtensionRegistry $registry): void
    {
        // Register analyzers
        $registry->addAnalyzer('custom-security', CustomSecurityAnalyzer::class);
        $registry->addAnalyzer('company-standards', CompanyStandardsAnalyzer::class);

        // Register rules
        $registry->addRule('no-object-manager', NoObjectManagerRule::class);
        $registry->addRule('company-naming', CompanyNamingRule::class);

        // Register report generators
        $registry->addReportGenerator('company-html', CompanyReportGenerator::class);

        // Register plugins
        $registry->addPlugin('slack-notifications', SlackNotificationPlugin::class);
    }

    public function boot(): void
    {
        // Extension initialization logic
    }
}
```

## 🧪 Testing Extensions

### Unit Tests for Custom Analyzer

```php
<?php

namespace MyCompany\M2Quality\Tests;

use PHPUnit\Framework\TestCase;
use MyCompany\M2Quality\Analyzers\CustomSecurityAnalyzer;
use M2Quality\Core\Scanner\ScanContext;

class CustomSecurityAnalyzerTest extends TestCase
{
    private CustomSecurityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new CustomSecurityAnalyzer();
    }

    public function testDetectsHardcodedPasswords(): void
    {
        $code = '<?php $password = "secret123";';
        $tempFile = $this->createTempFile($code);
        
        $context = new ScanContext($tempFile);
        $result = $this->analyzer->analyze($context);
        
        $violations = $result->getViolations();
        $this->assertCount(1, $violations);
        $this->assertEquals('hardcoded-password', $violations[0]->getRule());
        
        unlink($tempFile);
    }

    public function testIgnoresValidCode(): void
    {
        $code = '<?php $password = $this->getConfigValue("password");';
        $tempFile = $this->createTempFile($code);
        
        $context = new ScanContext($tempFile);
        $result = $this->analyzer->analyze($context);
        
        $this->assertEmpty($result->getViolations());
        
        unlink($tempFile);
    }

    private function createTempFile(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, $content);
        return $tempFile;
    }
}
```

## 📖 Best Practices

### 1. Extension Design Principles

- **Single Responsibility**: Each extension should have a clear, single purpose
- **Configurable**: Make extensions configurable through options
- **Testable**: Write comprehensive unit tests
- **Documentation**: Provide clear documentation and examples
- **Performance**: Consider performance impact on large codebases

### 2. Error Handling

```php
public function analyze(ScanContext $context): ScanResult
{
    try {
        // Analysis logic
        return new ScanResult($violations, true, $metrics);
    } catch (\Exception $e) {
        $this->logger->error('Analysis failed', [
            'analyzer' => $this->getName(),
            'error' => $e->getMessage()
        ]);
        
        return new ScanResult([], false, [], $e->getMessage());
    }
}
```

### 3. Configuration Validation

```php
public function configure(array $config): void
{
    $this->validateConfig($config);
    $this->config = array_merge($this->getDefaultConfig(), $config);
}

private function validateConfig(array $config): void
{
    $required = ['api_key', 'endpoint'];
    foreach ($required as $key) {
        if (!isset($config[$key])) {
            throw new \InvalidArgumentException("Missing required config: $key");
        }
    }
}
```

---

**Next**: See [Troubleshooting](troubleshooting.md) for common issues and solutions. 