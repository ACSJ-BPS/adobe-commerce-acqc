<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;
use Symfony\Component\Finder\Finder;

class ModuleStructureAnalyzer extends AbstractAnalyzer
{
    private array $requiredModuleFiles = ['registration.php', 'etc/module.xml'];
    private array $standardDirectories = [
        'Api', 'Block', 'Controller', 'Cron', 'Helper', 'Model', 'Observer', 
        'Plugin', 'Setup', 'Test', 'Ui', 'view', 'etc', 'i18n'
    ];
    private array $deprecatedDirectories = ['sql'];
    
    public function getName(): string
    {
        return 'magento-module-structure';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return true; // Internal analyzer, always available
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'check_module_structure' => true,
            'check_naming_conventions' => true,
            'check_file_placement' => true,
            'check_composer_structure' => true,
            'check_deprecated_patterns' => true,
            'allow_custom_directories' => true,
            'enforce_psr4_structure' => true,
            'check_registration_file' => true,
            'check_module_xml' => true,
            'check_acl_structure' => true,
            'check_webapi_structure' => true,
            'check_di_structure' => true,
            'severity_missing_required' => Violation::SEVERITY_ERROR,
            'severity_wrong_placement' => Violation::SEVERITY_WARNING,
            'severity_naming_convention' => Violation::SEVERITY_WARNING,
            'severity_deprecated' => Violation::SEVERITY_ERROR,
            'exclude_test_modules' => false,
            'module_path_patterns' => [
                'app/code/*/*',
                'vendor/*/*',
            ],
            'required_composer_fields' => [
                'name', 'description', 'type', 'version', 'license', 'require'
            ],
        ];
    }

    public function analyze(array $files, array $options = []): array
    {
        // Filter files by supported extensions and patterns
        $filteredFiles = $this->filterSupportedFiles($files);
        
        // Skip analysis if no relevant files
        if (empty($filteredFiles)) {
            return [];
        }
        
        $startTime = microtime(true);
        $this->logger->info('Starting Magento module structure analysis');
        
        $modules = $this->discoverModules($filteredFiles);
        $violations = [];
        
        foreach ($modules as $modulePath => $moduleInfo) {
            $violations = array_merge($violations, $this->analyzeModule($modulePath, $moduleInfo));
        }
        
        $this->logger->info('Found ' . count($violations) . ' module structure violations');
        $this->lastAnalysisResults = $violations;
        
        return $violations;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        // This method is not used in this analyzer
        return [];
    }

    protected function parseOutput(string $output, array $files): array
    {
        // This analyzer doesn't use external command output
        return [];
    }

    private function discoverModules(array $files): array
    {
        $modules = [];
        
        foreach ($files as $file) {
            if (basename($file) === 'registration.php') {
                $modulePath = dirname($file);
                $modules[$modulePath] = $this->extractModuleInfo($file);
            }
        }
        
        return $modules;
    }

    private function extractModuleInfo(string $registrationFile): array
    {
        $content = file_get_contents($registrationFile);
        $moduleInfo = [
            'name' => null,
            'type' => null,
            'path' => dirname($registrationFile),
            'vendor' => null,
            'module' => null,
        ];
        
        // Extract module name from registration.php
        if (preg_match('/ComponentRegistrar::register\s*\(\s*ComponentRegistrar::MODULE\s*,\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $moduleInfo['name'] = $matches[1];
            $parts = explode('_', $moduleInfo['name']);
            if (count($parts) >= 2) {
                $moduleInfo['vendor'] = $parts[0];
                $moduleInfo['module'] = $parts[1];
            }
        }
        
        return $moduleInfo;
    }

    private function analyzeModule(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        
        // Check required files
        $violations = array_merge($violations, $this->checkRequiredFiles($modulePath, $moduleInfo));
        
        // Check directory structure
        $violations = array_merge($violations, $this->checkDirectoryStructure($modulePath, $moduleInfo));
        
        // Check naming conventions
        $violations = array_merge($violations, $this->checkNamingConventions($modulePath, $moduleInfo));
        
        // Check file placement
        $violations = array_merge($violations, $this->checkFilePlacement($modulePath, $moduleInfo));
        
        // Check deprecated patterns
        $violations = array_merge($violations, $this->checkDeprecatedPatterns($modulePath, $moduleInfo));
        
        // Check composer.json if present
        $violations = array_merge($violations, $this->checkComposerStructure($modulePath, $moduleInfo));
        
        // Check module.xml structure
        $violations = array_merge($violations, $this->checkModuleXml($modulePath, $moduleInfo));
        
        return $violations;
    }

    private function checkRequiredFiles(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        
        foreach ($this->requiredModuleFiles as $requiredFile) {
            $filePath = $modulePath . '/' . $requiredFile;
            
            if (!file_exists($filePath)) {
                $violations[] = new Violation(
                    'magento-missing-required-file',
                    "Missing required file: {$requiredFile}",
                    $modulePath,
                    0,
                    0,
                    $this->configuration['severity_missing_required'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function checkDirectoryStructure(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        
        if (!is_dir($modulePath)) {
            return $violations;
        }
        
        $finder = new Finder();
        $directories = [];
        
        try {
            $finder->directories()->in($modulePath)->depth(0);
            foreach ($finder as $dir) {
                $directories[] = $dir->getFilename();
            }
        } catch (\Exception $e) {
            $this->logger->warning("Could not scan directory: {$modulePath}");
            return $violations;
        }
        
        // Check for deprecated directories
        foreach ($this->deprecatedDirectories as $deprecatedDir) {
            if (in_array($deprecatedDir, $directories)) {
                $violations[] = new Violation(
                    'magento-deprecated-directory',
                    "Deprecated directory '{$deprecatedDir}' found. Use Setup/InstallSchema.php or db_schema.xml instead",
                    $modulePath . '/' . $deprecatedDir,
                    0,
                    0,
                    $this->configuration['severity_deprecated'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Check directory naming conventions
        foreach ($directories as $directory) {
            if (!$this->isValidDirectoryName($directory)) {
                $violations[] = new Violation(
                    'magento-invalid-directory-name',
                    "Directory name '{$directory}' doesn't follow Magento naming conventions (should be PascalCase)",
                    $modulePath . '/' . $directory,
                    0,
                    0,
                    $this->configuration['severity_naming_convention'],
                    Violation::TYPE_STYLE
                );
            }
        }
        
        return $violations;
    }

    private function checkNamingConventions(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        
        if (empty($moduleInfo['name'])) {
            return $violations;
        }
        
        // Check module name format (Vendor_Module)
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*_[A-Z][a-zA-Z0-9]*$/', $moduleInfo['name'])) {
            $violations[] = new Violation(
                'magento-invalid-module-name',
                "Module name '{$moduleInfo['name']}' doesn't follow Magento naming convention (Vendor_Module)",
                $modulePath . '/registration.php',
                0,
                0,
                $this->configuration['severity_naming_convention'],
                Violation::TYPE_STYLE
            );
        }
        
        // Check if module path matches module name
        $expectedPath = str_replace('_', '/', $moduleInfo['name']);
        if (!str_contains($modulePath, $expectedPath)) {
            $violations[] = new Violation(
                'magento-module-path-mismatch',
                "Module path doesn't match module name. Expected path containing '{$expectedPath}'",
                $modulePath,
                0,
                0,
                $this->configuration['severity_wrong_placement'],
                Violation::TYPE_LOGIC
            );
        }
        
        return $violations;
    }

    private function checkFilePlacement(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        
        $finder = new Finder();
        try {
            $finder->files()->in($modulePath)->name('*.php');
            
            foreach ($finder as $file) {
                $relativePath = str_replace($modulePath . '/', '', $file->getPathname());
                $violations = array_merge($violations, $this->validateFileLocation($file->getPathname(), $relativePath, $moduleInfo));
            }
        } catch (\Exception $e) {
            $this->logger->warning("Could not scan files in: {$modulePath}");
        }
        
        return $violations;
    }

    private function validateFileLocation(string $filePath, string $relativePath, array $moduleInfo): array
    {
        $violations = [];
        $pathParts = explode('/', $relativePath);
        
        if (count($pathParts) < 2) {
            return $violations;
        }
        
        $directory = $pathParts[0];
        $filename = basename($filePath);
        
        // Check specific file placement rules
        switch ($directory) {
            case 'Controller':
                if (!$this->isValidControllerPlacement($relativePath, $filename)) {
                    $violations[] = new Violation(
                        'magento-controller-wrong-placement',
                        'Controllers should be in Controller/{Area}/{Route}/{Action}.php structure',
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_wrong_placement'],
                        Violation::TYPE_LOGIC
                    );
                }
                break;
                
            case 'Block':
                if (!$this->isValidBlockPlacement($relativePath, $filename)) {
                    $violations[] = new Violation(
                        'magento-block-wrong-placement',
                        'Block files should follow proper namespace structure',
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_wrong_placement'],
                        Violation::TYPE_LOGIC
                    );
                }
                break;
                
            case 'Model':
                if (!$this->isValidModelPlacement($relativePath, $filename)) {
                    $violations[] = new Violation(
                        'magento-model-wrong-placement',
                        'Model files should follow proper structure (Model/, Model/ResourceModel/, Model/ResourceModel/Collection/)',
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_wrong_placement'],
                        Violation::TYPE_LOGIC
                    );
                }
                break;
        }
        
        return $violations;
    }

    private function checkDeprecatedPatterns(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        
        // Check for deprecated file patterns
        $deprecatedPatterns = [
            'sql/*.php' => 'Use Setup scripts or db_schema.xml instead',
            'etc/config.xml' => 'Use specific configuration files (system.xml, acl.xml, etc.)',
            '*/Mysql4/*' => 'Use ResourceModel instead of Mysql4',
        ];
        
        foreach ($deprecatedPatterns as $pattern => $suggestion) {
            $finder = new Finder();
            try {
                $finder->files()->in($modulePath)->path($pattern);
                foreach ($finder as $file) {
                    $violations[] = new Violation(
                        'magento-deprecated-pattern',
                        "Deprecated file pattern detected. {$suggestion}",
                        $file->getPathname(),
                        0,
                        0,
                        $this->configuration['severity_deprecated'],
                        Violation::TYPE_LOGIC
                    );
                }
            } catch (\Exception $e) {
                // Pattern not found, continue
            }
        }
        
        return $violations;
    }

    private function checkComposerStructure(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        $composerPath = $modulePath . '/composer.json';
        
        if (!file_exists($composerPath)) {
            if ($this->configuration['check_composer_structure']) {
                $violations[] = new Violation(
                    'magento-missing-composer',
                    'Missing composer.json file. Recommended for proper module management',
                    $modulePath,
                    0,
                    0,
                    Violation::SEVERITY_INFO,
                    Violation::TYPE_MAINTAINABILITY
                );
            }
            return $violations;
        }
        
        $composerContent = file_get_contents($composerPath);
        $composer = json_decode($composerContent, true);
        
        if (!$composer) {
            $violations[] = new Violation(
                'magento-invalid-composer-json',
                'Invalid JSON in composer.json',
                $composerPath,
                0,
                0,
                $this->configuration['severity_missing_required'],
                Violation::TYPE_SYNTAX
            );
            return $violations;
        }
        
        // Check required fields
        foreach ($this->configuration['required_composer_fields'] as $field) {
            if (!isset($composer[$field])) {
                $violations[] = new Violation(
                    'magento-composer-missing-field',
                    "Missing required field '{$field}' in composer.json",
                    $composerPath,
                    0,
                    0,
                    $this->configuration['severity_missing_required'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Check type field
        if (isset($composer['type']) && $composer['type'] !== 'magento2-module') {
            $violations[] = new Violation(
                'magento-composer-wrong-type',
                "Type should be 'magento2-module' for Magento modules",
                $composerPath,
                0,
                0,
                $this->configuration['severity_wrong_placement'],
                Violation::TYPE_LOGIC
            );
        }
        
        return $violations;
    }

    private function checkModuleXml(string $modulePath, array $moduleInfo): array
    {
        $violations = [];
        $moduleXmlPath = $modulePath . '/etc/module.xml';
        
        if (!file_exists($moduleXmlPath)) {
            return $violations; // Already checked in required files
        }
        
        $content = file_get_contents($moduleXmlPath);
        
        // Basic XML validation
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        if (!@$dom->loadXML($content)) {
            $violations[] = new Violation(
                'magento-invalid-module-xml',
                'Invalid XML in module.xml',
                $moduleXmlPath,
                0,
                0,
                $this->configuration['severity_missing_required'],
                Violation::TYPE_SYNTAX
            );
            return $violations;
        }
        
        // Check module.xml structure
        $xpath = new \DOMXPath($dom);
        
        // Check for module element
        $moduleElements = $xpath->query('//config/module');
        if ($moduleElements->length === 0) {
            $violations[] = new Violation(
                'magento-module-xml-no-module',
                'module.xml must contain a module element',
                $moduleXmlPath,
                0,
                0,
                $this->configuration['severity_missing_required'],
                Violation::TYPE_LOGIC
            );
        } else {
            $moduleElement = $moduleElements->item(0);
            $moduleName = $moduleElement->getAttribute('name');
            
            // Check if module name matches registration
            if (!empty($moduleInfo['name']) && $moduleName !== $moduleInfo['name']) {
                $violations[] = new Violation(
                    'magento-module-name-mismatch',
                    "Module name in module.xml ('{$moduleName}') doesn't match registration.php ('{$moduleInfo['name']}')",
                    $moduleXmlPath,
                    0,
                    0,
                    $this->configuration['severity_missing_required'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function isValidDirectoryName(string $directory): bool
    {
        // Allow standard directories and PascalCase custom directories
        if (in_array($directory, $this->standardDirectories)) {
            return true;
        }
        
        // Check for PascalCase
        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $directory) === 1;
    }

    private function isValidControllerPlacement(string $relativePath, string $filename): bool
    {
        // Controllers should be in Controller/{Area}/{Route}/{Action}.php
        // or Controller/{Route}/{Action}.php for frontend
        $parts = explode('/', $relativePath);
        
        if ($parts[0] !== 'Controller') {
            return false;
        }
        
        // Should have at least Controller/Action.php or Controller/Route/Action.php
        return count($parts) >= 2;
    }

    private function isValidBlockPlacement(string $relativePath, string $filename): bool
    {
        // Blocks should be in Block/ or Block/{Namespace}/
        $parts = explode('/', $relativePath);
        return $parts[0] === 'Block';
    }

    private function isValidModelPlacement(string $relativePath, string $filename): bool
    {
        // Models should be in Model/, Model/ResourceModel/, or Model/ResourceModel/{Entity}/Collection/
        $parts = explode('/', $relativePath);
        
        if ($parts[0] !== 'Model') {
            return false;
        }
        
        // Additional validation for ResourceModel structure
        if (count($parts) > 1 && $parts[1] === 'ResourceModel') {
            // ResourceModel files should end with .php
            return str_ends_with($filename, '.php');
        }
        
        return true;
    }
} 