<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Config\RoleConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Technical Specification Generator Command
 * 
 * Generates technical specifications based on role-based requirements:
 * - Code quality standards
 * - Performance requirements
 * - Security requirements
 * - Architecture patterns
 * - Tool configurations
 */
#[AsCommand(name: 'tech-spec', description: 'Generate technical specifications based on role requirements')]
class TechnicalSpecCommand extends Command
{
    protected static $defaultName = 'tech-spec';
    protected static $defaultDescription = 'Generate technical specifications based on role requirements';

    private RoleConfig $roleConfig;

    public function __construct(?RoleConfig $roleConfig = null)
    {
        parent::__construct();
        $this->roleConfig = $roleConfig ?? new RoleConfig();
    }

    protected function configure(): void
    {
        $this
            ->setName('tech-spec')
            ->setDescription('Generate technical specifications based on role requirements')
            ->addArgument(
                'role',
                InputArgument::REQUIRED,
                'Developer role (junior-developer, senior-developer, architect)'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format (markdown, json, yaml, html)',
                'markdown'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path'
            )
            ->addOption(
                'include-tools',
                't',
                InputOption::VALUE_NONE,
                'Include tool configurations'
            )
            ->addOption(
                'include-examples',
                'e',
                InputOption::VALUE_NONE,
                'Include code examples'
            )
            ->addOption(
                'include-metrics',
                'm',
                InputOption::VALUE_NONE,
                'Include quality metrics and thresholds'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = $input->getArgument('role');
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');
        $includeTools = $input->getOption('include-tools');
        $includeExamples = $input->getOption('include-examples');
        $includeMetrics = $input->getOption('include-metrics');

        // Validate role
        if (!$this->roleConfig->isValidRole($role)) {
            $io->error("Invalid role: {$role}");
            $io->note("Available roles: " . implode(', ', array_keys($this->roleConfig->getRoles())));
            return Command::FAILURE;
        }

        $roleInfo = $this->roleConfig->getRole($role);
        $checklist = $this->roleConfig->getQualityChecklist($role) ?? [];

        // Generate technical specification
        $spec = $this->generateTechnicalSpec($role, $roleInfo, $checklist, $includeTools, $includeExamples, $includeMetrics);

        // Output specification
        if ($outputFile) {
            $this->writeSpecification($spec, $format, $outputFile);
            $io->success("Technical specification written to: {$outputFile}");
        } else {
            $this->displaySpecification($io, $spec, $format);
        }

        return Command::SUCCESS;
    }

    private function generateTechnicalSpec(string $role, array $roleInfo, array $checklist, bool $includeTools, bool $includeExamples, bool $includeMetrics): array
    {
        $spec = [
            'metadata' => [
                'role' => $role,
                'role_name' => $roleInfo['name'],
                'role_level' => $roleInfo['level'],
                'description' => $roleInfo['description'],
                'generated_at' => date('Y-m-d H:i:s'),
                'version' => '1.0.0'
            ],
            'overview' => [
                'purpose' => $this->getRolePurpose($role),
                'responsibilities' => $this->getRoleResponsibilities($role),
                'key_competencies' => $this->getKeyCompetencies($role)
            ],
            'quality_standards' => $this->generateQualityStandards($checklist),
            'technical_requirements' => $this->generateTechnicalRequirements($role, $checklist),
            'best_practices' => $this->generateBestPractices($role),
            'code_standards' => $this->generateCodeStandards($role),
            'security_requirements' => $this->generateSecurityRequirements($role),
            'performance_requirements' => $this->generatePerformanceRequirements($role),
            'testing_requirements' => $this->generateTestingRequirements($role),
            'documentation_requirements' => $this->generateDocumentationRequirements($role)
        ];

        if ($includeTools) {
            $spec['tools_and_configurations'] = $this->generateToolConfigurations($role, $checklist);
        }

        if ($includeExamples) {
            $spec['code_examples'] = $this->generateCodeExamples($role);
        }

        if ($includeMetrics) {
            $spec['quality_metrics'] = $this->generateQualityMetrics($role);
        }

        return $spec;
    }

    private function getRolePurpose(string $role): string
    {
        return match($role) {
            'junior-developer' => 'To ensure code quality basics, proper Magento 2 fundamentals, and adherence to basic security and performance practices.',
            'senior-developer' => 'To implement advanced design patterns, optimize performance, ensure enterprise-level security, and mentor junior developers.',
            'architect' => 'To design scalable system architectures, manage technical debt, implement enterprise security, and establish architectural standards.',
            default => 'To maintain high code quality and follow best practices.'
        };
    }

    private function getRoleResponsibilities(string $role): array
    {
        return match($role) {
            'junior-developer' => [
                'Write clean, maintainable code following PSR-12 standards',
                'Implement basic Magento 2 module structure and functionality',
                'Apply basic security practices and input validation',
                'Write unit tests for new functionality',
                'Participate in code reviews and follow team guidelines'
            ],
            'senior-developer' => [
                'Design and implement complex features using advanced patterns',
                'Optimize application performance and database queries',
                'Implement comprehensive security measures',
                'Mentor junior developers and conduct code reviews',
                'Contribute to architectural decisions and technical planning',
                'Implement and maintain CI/CD pipelines'
            ],
            'architect' => [
                'Design scalable and maintainable system architectures',
                'Define technical standards and best practices',
                'Manage technical debt and plan upgrade strategies',
                'Design integration patterns and API architectures',
                'Implement enterprise security and compliance measures',
                'Establish monitoring and observability strategies'
            ],
            default => []
        };
    }

    private function getKeyCompetencies(string $role): array
    {
        return match($role) {
            'junior-developer' => [
                'PHP 8.x and Magento 2 development',
                'Basic understanding of design patterns',
                'HTML, CSS, and JavaScript fundamentals',
                'Git version control',
                'Basic testing and debugging skills'
            ],
            'senior-developer' => [
                'Advanced PHP and Magento 2 development',
                'Design patterns and SOLID principles',
                'Performance optimization and profiling',
                'Security best practices and threat modeling',
                'React/JavaScript advanced patterns',
                'Database design and optimization'
            ],
            'architect' => [
                'System architecture and design',
                'Scalability and performance engineering',
                'Enterprise security and compliance',
                'Microservices and distributed systems',
                'DevOps and infrastructure as code',
                'Technical leadership and mentoring'
            ],
            default => []
        };
    }

    private function generateQualityStandards(array $checklist): array
    {
        $standards = [];

        foreach ($checklist as $categoryKey => $category) {
            // Skip the 'tools' pseudo-category and any malformed (non-array) entry.
            if ($categoryKey === 'tools' || !is_array($category)) {
                continue;
            }

            $standards[$categoryKey] = [
                'name' => $category['name'] ?? $categoryKey,
                'requirements' => []
            ];

            foreach (($category['items'] ?? []) as $itemKey => $item) {
                // Skip the 'tools' pseudo-item and items with no checks to iterate.
                if ($itemKey === 'tools' || !is_array($item) || empty($item['checks']) || !is_array($item['checks'])) {
                    continue;
                }

                $standards[$categoryKey]['requirements'][$itemKey] = [
                    'name' => $item['name'] ?? $itemKey,
                    'checks' => []
                ];

                foreach ($item['checks'] as $checkKey => $check) {
                    if (!is_array($check)) {
                        continue;
                    }

                    // The checklist data has two shapes here: some roles list
                    // leaf checks directly (they carry a 'severity'), while others
                    // (e.g. junior-developer) group them one level deeper under a
                    // sub-group that nests its own 'checks' array. Normalise both
                    // to a flat list of leaf checks.
                    $leafChecks = (isset($check['checks']) && is_array($check['checks']))
                        ? $check['checks']
                        : [$checkKey => $check];

                    foreach ($leafChecks as $leafKey => $leaf) {
                        if (!is_array($leaf)) {
                            continue;
                        }

                        $standards[$categoryKey]['requirements'][$itemKey]['checks'][$leafKey] = [
                            'name' => $leaf['name'] ?? $leafKey,
                            'severity' => $leaf['severity'] ?? 'info',
                            'description' => $leaf['description'] ?? '',
                            'tools' => $leaf['tools'] ?? []
                        ];
                    }
                }
            }
        }

        return $standards;
    }

    private function generateTechnicalRequirements(string $role, array $checklist): array
    {
        $requirements = [
            'code_quality' => [
                'minimum_php_version' => '8.1',
                'coding_standards' => 'PSR-12',
                'type_hinting' => $role === 'junior-developer' ? 'recommended' : 'required',
                'documentation' => $role === 'junior-developer' ? 'basic' : 'comprehensive',
                'test_coverage' => $this->getTestCoverageRequirement($role)
            ],
            'magento2' => [
                'minimum_version' => '2.4.6',
                'module_structure' => 'required',
                'service_contracts' => $role !== 'junior-developer' ? 'required' : 'recommended',
                'dependency_injection' => 'required',
                'api_development' => $role !== 'junior-developer' ? 'required' : 'basic'
            ],
            'frontend' => [
                'responsive_design' => 'required',
                'accessibility' => 'required',
                'performance' => $role === 'junior-developer' ? 'basic' : 'optimized',
                'pwa_features' => $role !== 'junior-developer' ? 'recommended' : 'optional'
            ],
            'security' => [
                'input_validation' => 'required',
                'output_escaping' => 'required',
                'authentication' => 'required',
                'authorization' => 'required',
                'encryption' => $role !== 'junior-developer' ? 'required' : 'recommended',
                'security_headers' => $role !== 'junior-developer' ? 'required' : 'recommended'
            ],
            'performance' => [
                'page_load_time' => $this->getPerformanceRequirement($role, 'page_load'),
                'database_queries' => $this->getPerformanceRequirement($role, 'database'),
                'caching' => $role === 'junior-developer' ? 'basic' : 'comprehensive',
                'optimization' => $role !== 'junior-developer' ? 'required' : 'recommended'
            ]
        ];

        return $requirements;
    }

    private function generateBestPractices(string $role): array
    {
        $practices = [
            'general' => [
                'Write self-documenting code with clear variable and function names',
                'Follow the DRY (Don\'t Repeat Yourself) principle',
                'Use meaningful commit messages',
                'Write code for maintainability, not just functionality',
                'Keep functions and classes small and focused'
            ]
        ];

        if ($role !== 'junior-developer') {
            $practices['advanced'] = [
                'Apply SOLID principles consistently',
                'Use design patterns appropriately',
                'Implement proper error handling and logging',
                'Write comprehensive unit and integration tests',
                'Document architectural decisions (ADRs)'
            ];
        }

        if ($role === 'architect') {
            $practices['architectural'] = [
                'Design for scalability from the start',
                'Plan for failure and implement resilience patterns',
                'Consider security at every layer',
                'Implement proper monitoring and observability',
                'Document system architecture and integration patterns'
            ];
        }

        return $practices;
    }

    private function generateCodeStandards(string $role): array
    {
        $standards = [
            'php' => [
                'standard' => 'PSR-12',
                'type_hinting' => 'required',
                'return_types' => 'required',
                'docblocks' => $role === 'junior-developer' ? 'recommended' : 'required',
                'naming_conventions' => [
                    'classes' => 'PascalCase',
                    'methods' => 'camelCase',
                    'variables' => 'camelCase',
                    'constants' => 'UPPER_CASE'
                ]
            ],
            'magento2' => [
                'module_naming' => 'Vendor_Module',
                'namespace' => 'Vendor\\Module',
                'class_naming' => 'Follow Magento 2 conventions',
                'service_contracts' => 'Use interfaces for public APIs',
                'dependency_injection' => 'Prefer constructor injection'
            ],
            'frontend' => [
                'html' => 'Semantic HTML5',
                'css' => 'BEM methodology',
                'javascript' => 'ES6+ with proper error handling',
                'react' => 'Functional components with hooks'
            ]
        ];

        return $standards;
    }

    private function generateSecurityRequirements(string $role): array
    {
        $requirements = [
            'basic' => [
                'input_validation' => 'Validate all user inputs',
                'output_escaping' => 'Escape all output in templates',
                'authentication' => 'Implement proper authentication',
                'authorization' => 'Check permissions before actions',
                'csrf_protection' => 'Implement CSRF protection'
            ]
        ];

        if ($role !== 'junior-developer') {
            $requirements['advanced'] = [
                'sql_injection_prevention' => 'Use prepared statements',
                'xss_prevention' => 'Implement comprehensive XSS protection',
                'data_encryption' => 'Encrypt sensitive data',
                'security_headers' => 'Implement security headers',
                'threat_modeling' => 'Perform threat modeling'
            ];
        }

        if ($role === 'architect') {
            $requirements['enterprise'] = [
                'security_architecture' => 'Design secure architecture',
                'compliance' => 'Meet PCI, GDPR requirements',
                'security_testing' => 'Automated security testing',
                'incident_response' => 'Incident response planning',
                'security_monitoring' => 'Real-time security monitoring'
            ];
        }

        return $requirements;
    }

    private function generatePerformanceRequirements(string $role): array
    {
        $requirements = [
            'page_load' => $this->getPerformanceRequirement($role, 'page_load'),
            'database' => [
                'query_optimization' => 'Optimize database queries',
                'indexing' => 'Proper database indexing',
                'connection_pooling' => $role !== 'junior-developer' ? 'recommended' : 'optional'
            ],
            'caching' => [
                'application_cache' => 'required',
                'database_cache' => $role !== 'junior-developer' ? 'required' : 'recommended',
                'cdn' => $role === 'architect' ? 'required' : 'recommended'
            ],
            'frontend' => [
                'bundle_optimization' => $role !== 'junior-developer' ? 'required' : 'recommended',
                'image_optimization' => 'required',
                'lazy_loading' => $role !== 'junior-developer' ? 'required' : 'recommended'
            ]
        ];

        return $requirements;
    }

    private function generateTestingRequirements(string $role): array
    {
        $requirements = [
            'unit_tests' => [
                'coverage' => $this->getTestCoverageRequirement($role),
                'naming' => 'Test class and method naming conventions',
                'structure' => 'Arrange-Act-Assert pattern'
            ],
            'integration_tests' => [
                'required' => $role !== 'junior-developer',
                'coverage' => $role === 'junior-developer' ? 'basic' : 'comprehensive'
            ],
            'functional_tests' => [
                'required' => $role !== 'junior-developer',
                'automation' => $role !== 'junior-developer' ? 'required' : 'recommended'
            ]
        ];

        if ($role === 'architect') {
            $requirements['performance_tests'] = [
                'load_testing' => 'required',
                'stress_testing' => 'recommended',
                'security_testing' => 'required'
            ];
        }

        return $requirements;
    }

    private function generateDocumentationRequirements(string $role): array
    {
        $requirements = [
            'code_documentation' => [
                'inline_comments' => 'required',
                'docblocks' => $role === 'junior-developer' ? 'recommended' : 'required',
                'readme_files' => 'required'
            ],
            'api_documentation' => [
                'required' => $role !== 'junior-developer',
                'format' => 'OpenAPI/Swagger',
                'examples' => 'required'
            ]
        ];

        if ($role === 'architect') {
            $requirements['architectural_documentation'] = [
                'system_diagrams' => 'required',
                'integration_docs' => 'required',
                'deployment_guides' => 'required',
                'adrs' => 'required'
            ];
        }

        return $requirements;
    }

    private function generateToolConfigurations(string $role, array $checklist): array
    {
        $tools = $checklist['tools'] ?? [];
        $configurations = [];

        foreach ($tools as $tool => $description) {
            $configurations[$tool] = [
                'description' => $description,
                'configuration' => $this->getToolConfiguration($tool, $role)
            ];
        }

        return $configurations;
    }

    private function generateCodeExamples(string $role): array
    {
        $examples = [
            'php' => [
                'dependency_injection' => $this->getDependencyInjectionExample($role),
                'service_contract' => $this->getServiceContractExample($role),
                'error_handling' => $this->getErrorHandlingExample($role)
            ],
            'magento2' => [
                'module_structure' => $this->getModuleStructureExample(),
                'plugin_implementation' => $this->getPluginExample($role),
                'repository_pattern' => $this->getRepositoryExample($role)
            ]
        ];

        if ($role !== 'junior-developer') {
            $examples['advanced'] = [
                'design_patterns' => $this->getDesignPatternExample($role),
                'performance_optimization' => $this->getPerformanceExample($role)
            ];
        }

        return $examples;
    }

    private function generateQualityMetrics(string $role): array
    {
        return [
            'code_quality' => [
                'cyclomatic_complexity' => $this->getComplexityThreshold($role),
                'maintainability_index' => $this->getMaintainabilityThreshold($role),
                'technical_debt_ratio' => $this->getTechnicalDebtThreshold($role)
            ],
            'test_coverage' => [
                'unit_tests' => $this->getTestCoverageRequirement($role),
                'integration_tests' => $role === 'junior-developer' ? '60%' : '80%',
                'functional_tests' => $role === 'junior-developer' ? '40%' : '70%'
            ],
            'performance' => [
                'page_load_time' => $this->getPerformanceRequirement($role, 'page_load'),
                'database_query_count' => $this->getPerformanceRequirement($role, 'database'),
                'memory_usage' => $role === 'junior-developer' ? 'reasonable' : 'optimized'
            ]
        ];
    }

    private function getTestCoverageRequirement(string $role): string
    {
        return match($role) {
            'junior-developer' => '70%',
            'senior-developer' => '85%',
            'architect' => '90%',
            default => '80%'
        };
    }

    private function getPerformanceRequirement(string $role, string $type): string
    {
        return match($type) {
            'page_load' => match($role) {
                'junior-developer' => '< 3 seconds',
                'senior-developer' => '< 2 seconds',
                'architect' => '< 1.5 seconds',
                default => '< 2.5 seconds'
            },
            'database' => match($role) {
                'junior-developer' => '< 10 queries per page',
                'senior-developer' => '< 5 queries per page',
                'architect' => '< 3 queries per page',
                default => '< 7 queries per page'
            },
            default => 'reasonable'
        };
    }

    private function getComplexityThreshold(string $role): int
    {
        return match($role) {
            'junior-developer' => 10,
            'senior-developer' => 7,
            'architect' => 5,
            default => 8
        };
    }

    private function getMaintainabilityThreshold(string $role): int
    {
        return match($role) {
            'junior-developer' => 60,
            'senior-developer' => 70,
            'architect' => 80,
            default => 65
        };
    }

    private function getTechnicalDebtThreshold(string $role): string
    {
        return match($role) {
            'junior-developer' => '15%',
            'senior-developer' => '10%',
            'architect' => '5%',
            default => '12%'
        };
    }

    private function getToolConfiguration(string $tool, string $role): array
    {
        // This would contain actual tool configurations
        return [
            'enabled' => true,
            'configuration_file' => "config/{$tool}.{$role}.json",
            'rules' => 'role-specific rules'
        ];
    }

    private function getDependencyInjectionExample(string $role): string
    {
        return match($role) {
            'junior-developer' => 'Basic constructor injection example',
            'senior-developer' => 'Advanced DI with interfaces and factories',
            'architect' => 'Complex DI with custom providers and decorators',
            default => 'Standard DI example'
        };
    }

    private function getServiceContractExample(string $role): string
    {
        return match($role) {
            'junior-developer' => 'Basic service contract implementation',
            'senior-developer' => 'Advanced service contract with extension attributes',
            'architect' => 'Complex service contract with custom implementations',
            default => 'Standard service contract example'
        };
    }

    private function getErrorHandlingExample(string $role): string
    {
        return match($role) {
            'junior-developer' => 'Basic try-catch with logging',
            'senior-developer' => 'Custom exceptions with proper hierarchy',
            'architect' => 'Comprehensive error handling with recovery strategies',
            default => 'Standard error handling example'
        };
    }

    private function getModuleStructureExample(): string
    {
        return 'Standard Magento 2 module structure with registration.php, module.xml, etc.';
    }

    private function getPluginExample(string $role): string
    {
        return match($role) {
            'junior-developer' => 'Basic plugin with before/after methods',
            'senior-developer' => 'Advanced plugin with around method and proper error handling',
            'architect' => 'Complex plugin with multiple interceptors and performance considerations',
            default => 'Standard plugin example'
        };
    }

    private function getRepositoryExample(string $role): string
    {
        return match($role) {
            'junior-developer' => 'Basic repository implementation',
            'senior-developer' => 'Advanced repository with custom collections and filters',
            'architect' => 'Complex repository with caching, events, and custom queries',
            default => 'Standard repository example'
        };
    }

    private function getDesignPatternExample(string $role): string
    {
        return match($role) {
            'senior-developer' => 'Factory, Observer, and Strategy patterns',
            'architect' => 'Complex patterns like Repository, Unit of Work, and CQRS',
            default => 'Basic design pattern examples'
        };
    }

    private function getPerformanceExample(string $role): string
    {
        return match($role) {
            'senior-developer' => 'Query optimization and caching strategies',
            'architect' => 'Advanced caching, indexing, and performance monitoring',
            default => 'Basic performance optimization examples'
        };
    }

    private function writeSpecification(array $spec, string $format, string $outputFile): void
    {
        switch ($format) {
            case 'json':
                file_put_contents($outputFile, json_encode($spec, JSON_PRETTY_PRINT));
                break;
            case 'yaml':
                file_put_contents($outputFile, Yaml::dump($spec, 4, 2));
                break;
            case 'html':
                file_put_contents($outputFile, $this->generateHtmlSpec($spec));
                break;
            case 'markdown':
            default:
                file_put_contents($outputFile, $this->generateMarkdownSpec($spec));
                break;
        }
    }

    private function displaySpecification(SymfonyStyle $io, array $spec, string $format): void
    {
        switch ($format) {
            case 'json':
                $io->write(json_encode($spec, JSON_PRETTY_PRINT));
                break;
            case 'yaml':
                $io->write(Yaml::dump($spec, 4, 2));
                break;
            case 'markdown':
            default:
                $io->write($this->generateMarkdownSpec($spec));
                break;
        }
    }

    private function generateMarkdownSpec(array $spec): string
    {
        $markdown = "# Technical Specification: {$spec['metadata']['role_name']}\n\n";
        $markdown .= "**Generated:** {$spec['metadata']['generated_at']}\n";
        $markdown .= "**Version:** {$spec['metadata']['version']}\n\n";

        $markdown .= "## Overview\n\n";
        $markdown .= "**Purpose:** {$spec['overview']['purpose']}\n\n";

        $markdown .= "### Responsibilities\n\n";
        foreach ($spec['overview']['responsibilities'] as $responsibility) {
            $markdown .= "- {$responsibility}\n";
        }
        $markdown .= "\n";

        $markdown .= "### Key Competencies\n\n";
        foreach ($spec['overview']['key_competencies'] as $competency) {
            $markdown .= "- {$competency}\n";
        }
        $markdown .= "\n";

        $markdown .= "## Quality Standards\n\n";
        foreach ($spec['quality_standards'] as $categoryKey => $category) {
            $markdown .= "### {$category['name']}\n\n";
            foreach ($category['requirements'] as $itemKey => $item) {
                $markdown .= "#### {$item['name']}\n\n";
                foreach ($item['checks'] as $checkKey => $check) {
                    $markdown .= "- **{$check['name']}** ({$check['severity']}): {$check['description']}\n";
                }
                $markdown .= "\n";
            }
        }

        $markdown .= "## Technical Requirements\n\n";
        foreach ($spec['technical_requirements'] as $category => $requirements) {
            $markdown .= "### {$category}\n\n";
            foreach ($requirements as $key => $value) {
                if (is_array($value)) {
                    $markdown .= "#### {$key}\n\n";
                    foreach ($value as $subKey => $subValue) {
                        $markdown .= "- **{$subKey}:** {$subValue}\n";
                    }
                } else {
                    $markdown .= "- **{$key}:** {$value}\n";
                }
            }
            $markdown .= "\n";
        }

        return $markdown;
    }

    private function generateHtmlSpec(array $spec): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Technical Specification: ' . htmlspecialchars($spec['metadata']['role_name']) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1, h2, h3, h4 { color: #333; }
        .metadata { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .requirement { margin: 10px 0; padding: 10px; border-left: 4px solid #007cba; background: #f9f9f9; }
        .severity-critical { border-left-color: #dc3232; }
        .severity-error { border-left-color: #dc3232; }
        .severity-warning { border-left-color: #ffb900; }
        .severity-info { border-left-color: #007cba; }
    </style>
</head>
<body>
    <h1>Technical Specification: ' . htmlspecialchars($spec['metadata']['role_name']) . '</h1>
    
    <div class="metadata">
        <p><strong>Generated:</strong> ' . htmlspecialchars($spec['metadata']['generated_at']) . '</p>
        <p><strong>Version:</strong> ' . htmlspecialchars($spec['metadata']['version']) . '</p>
        <p><strong>Purpose:</strong> ' . htmlspecialchars($spec['overview']['purpose']) . '</p>
    </div>';

        // Add more HTML content based on the specification structure
        $html .= '</body></html>';

        return $html;
    }
} 