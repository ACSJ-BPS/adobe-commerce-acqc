<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class SecurityAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-security-analyzer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return class_exists('PhpParser\Parser');
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'check_sql_injection' => true,
            'check_xss_vulnerabilities' => true,
            'check_hardcoded_secrets' => true,
            'check_file_operations' => true,
            'check_input_validation' => true,
            'check_output_escaping' => true,
            'check_csrf_protection' => true,
            'check_authentication' => true,
            'check_authorization' => true,
            'check_cryptography' => true,
            'severity_critical_security' => Violation::SEVERITY_CRITICAL,
            'severity_high_security' => Violation::SEVERITY_ERROR,
            'severity_medium_security' => Violation::SEVERITY_WARNING,
            'severity_low_security' => Violation::SEVERITY_INFO,
            'sql_injection_patterns' => [
                'rawQuery',
                'query(',
                'exec(',
                'prepare(',
                'fetchAll(',
                'fetchRow(',
                'fetchOne(',
                'WHERE.*\$',
                'ORDER BY.*\$',
                'LIMIT.*\$',
                'GROUP BY.*\$',
                'HAVING.*\$',
            ],
            'xss_vulnerable_functions' => [
                'echo',
                'print',
                'printf',
                'sprintf',
                'file_put_contents',
                'fwrite',
                'fputs',
            ],
            'file_operation_functions' => [
                'file_get_contents',
                'file_put_contents',
                'fopen',
                'fread',
                'fwrite',
                'include',
                'require',
                'include_once',
                'require_once',
                'readfile',
                'unlink',
                'copy',
                'move_uploaded_file',
            ],
            'secret_patterns' => [
                'password\s*=\s*["\'][^"\']+["\']',
                'secret\s*=\s*["\'][^"\']+["\']',
                'api_key\s*=\s*["\'][^"\']+["\']',
                'private_key\s*=\s*["\'][^"\']+["\']',
                'access_token\s*=\s*["\'][^"\']+["\']',
                'auth_token\s*=\s*["\'][^"\']+["\']',
                'database.*password',
                'db.*pass',
                '["\'][a-zA-Z0-9]{32,}["\']', // Potential API keys/secrets
            ],
            'unsafe_functions' => [
                'eval',
                'exec',
                'shell_exec',
                'system',
                'passthru',
                'proc_open',
                'popen',
                'assert',
                'create_function',
                'file_get_contents', // when used with URLs
                'curl_exec',
                'fsockopen',
                'unserialize',
            ],
            'magento_escaping_functions' => [
                'escapeHtml',
                'escapeHtmlAttr',
                'escapeUrl',
                'escapeJs',
                'escapeCss',
                'escapeQuote',
            ],
            'magento_validation_classes' => [
                'Zend_Validate',
                'Magento\\Framework\\Validator',
                'Laminas\\Validator',
            ],
            'exclude_paths' => [
                '*/Test/*',
                '*/tests/*',
                '*/dev/*',
                '*/Setup/*',
            ],
        ];
    }
    public function analyze(array $files, array $options = []): array
    {
        $filteredFiles = $this->filterSupportedFiles($files);
        
        if (empty($filteredFiles)) {
            return [];
        }
        
        $violations = $this->executeAnalysis($filteredFiles, $options);
        $this->lastAnalysisResults = $violations;
        
        return $violations;
    }


    protected function executeAnalysis(array $files, array $options = []): array
    {
        $this->logger->info('Starting Magento security analysis');
        
        $violations = [];
        
        foreach ($files as $file) {
            if (str_ends_with($file, '.php') && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzePhpFile($file));
            } elseif (str_ends_with($file, '.xml')) {
                $violations = array_merge($violations, $this->analyzeXmlFile($file));
            } elseif (str_ends_with($file, '.phtml')) {
                $violations = array_merge($violations, $this->analyzeTemplateFile($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' security violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function analyzePhpFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for hardcoded secrets in plain text
        $violations = array_merge($violations, $this->checkHardcodedSecrets($content, $filePath));
        
        try {
            $ast = $this->parser->parse($content);
            if (!$ast) {
                return $violations;
            }
            
            $visitor = new SecurityAnalysisVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse file: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function analyzeXmlFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for security configurations in XML files
        if (str_contains($filePath, 'acl.xml')) {
            $violations = array_merge($violations, $this->analyzeAclConfiguration($content, $filePath));
        }
        
        if (str_contains($filePath, 'system.xml')) {
            $violations = array_merge($violations, $this->analyzeSystemConfiguration($content, $filePath));
        }
        
        return $violations;
    }

    private function analyzeTemplateFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for XSS vulnerabilities in templates
        $violations = array_merge($violations, $this->checkTemplateXSS($content, $filePath));
        
        return $violations;
    }

    private function checkHardcodedSecrets(string $content, string $filePath): array
    {
        $violations = [];
        
        foreach ($this->configuration['secret_patterns'] as $pattern) {
            if (preg_match('/' . $pattern . '/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($content, 0, $matches[0][1]), "\n") + 1;
                
                $violations[] = new Violation(
                    'magento-security-hardcoded-secret',
                    'Potential hardcoded secret detected: ' . $matches[0][0],
                    $filePath,
                    $line,
                    0,
                    $this->configuration['severity_critical_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        return $violations;
    }

    private function analyzeAclConfiguration(string $content, string $filePath): array
    {
        $violations = [];
        
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($content)) {
            return $violations;
        }
        
        $xpath = new \DOMXPath($dom);
        $resources = $xpath->query('//resource');
        
        foreach ($resources as $resource) {
            $resourceId = $resource->getAttribute('id');
            
            // Check for overly permissive ACL rules
            if ($resourceId === 'Magento_Backend::all') {
                $violations[] = new Violation(
                    'magento-security-overly-permissive-acl',
                    'ACL rule grants access to all backend resources. Use specific permissions instead.',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
            
            // Check for missing ACL resource hierarchy
            if (!str_contains($resourceId, '::') && $resourceId !== 'Magento_Backend::admin') {
                $violations[] = new Violation(
                    'magento-security-malformed-acl-resource',
                    "ACL resource '{$resourceId}' should follow Module_Name::resource_name format",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        return $violations;
    }

    private function analyzeSystemConfiguration(string $content, string $filePath): array
    {
        $violations = [];
        
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($content)) {
            return $violations;
        }
        
        $xpath = new \DOMXPath($dom);
        $fields = $xpath->query('//field');
        
        foreach ($fields as $field) {
            $fieldType = $field->getAttribute('type');
            $fieldId = $field->getAttribute('id');
            
            // Check for password fields without proper encryption
            if (str_contains($fieldId, 'password') || str_contains($fieldId, 'secret')) {
                $backendModel = $xpath->query('.//backend_model', $field);
                if ($backendModel->length === 0) {
                    $violations[] = new Violation(
                        'magento-security-unencrypted-password-field',
                        "Password/secret field '{$fieldId}' should use encrypted backend model",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_high_security'],
                        Violation::TYPE_SECURITY
                    );
                }
            }
            
            // Check for obscured fields
            if ($fieldType === 'obscure') {
                $violations[] = new Violation(
                    'magento-security-obscured-field-deprecated',
                    "Obscured field type is deprecated. Use 'password' type with encrypted backend model instead.",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        return $violations;
    }

    private function checkTemplateXSS(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for unescaped output
        $patterns = [
            // Match variables not followed by escaping
            '/(?<!\$escaper->escape[A-Za-z]*\()\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]+\])*(?:\->[a-zA-Z_][a-zA-Z0-9_]*\(\))*(?!\s*\|)/',
            '/(?<!\$escaper->escape[A-Za-z]*\()echo\s+\$[a-zA-Z_][a-zA-Z0-9_]*(?!\s*;)/',
            '/(?<!\$escaper->escape[A-Za-z]*\()print\s+\$[a-zA-Z_][a-zA-Z0-9_]*(?!\s*;)/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    
                    // Extract context around the match
                    $beforeMatch = substr($content, max(0, $match[1] - 100), 100);
                    $afterMatch = substr($content, $match[1], 100);
                    
                    // Check if it's already escaped
                    $isEscaped = false;
                    
                    // Check for Magento escaping functions
                    foreach ($this->configuration['magento_escaping_functions'] as $escapeFunction) {
                        if (str_contains($beforeMatch, $escapeFunction) || str_contains($beforeMatch, '$escaper->')) {
                            $isEscaped = true;
                            break;
                        }
                    }
                    
                    // Check for safe patterns
                    $safePatterns = [
                        '/\(int\)/',           // Integer cast
                        '/\(float\)/',         // Float cast
                        '/\(bool\)/',          // Boolean cast
                        '/->getId\(\)/',       // ID getters
                        '/->getCount\(\)/',    // Count methods
                        '/->getSize\(\)/',     // Size methods
                        '/->format/',          // Format methods
                        '/json_encode/',       // JSON encoding
                        '/htmlspecialchars/',  // PHP escaping
                        '/htmlentities/',      // PHP escaping
                    ];
                    
                    foreach ($safePatterns as $safePattern) {
                        if (preg_match($safePattern, $beforeMatch) || preg_match($safePattern, $afterMatch)) {
                            $isEscaped = true;
                            break;
                        }
                    }
                    
                    // Skip if it's a translation call __()
                    if (str_contains($match[0], '__') || str_contains($beforeMatch, '__')) {
                        $isEscaped = true;
                    }
                    
                    if (!$isEscaped) {
                        $violations[] = new Violation(
                            'magento-security-unescaped-output',
                            'Potentially unescaped output detected: ' . trim(substr($match[0], 0, 50)),
                            $filePath,
                            $line,
                            0,
                            $this->configuration['severity_high_security'],
                            Violation::TYPE_SECURITY
                        );
                    }
                }
            }
        }
        
        return $violations;
    }

    private function shouldAnalyzeFile(string $filePath): bool
    {
        foreach ($this->configuration['exclude_paths'] as $excludePath) {
            if (fnmatch($excludePath, $filePath)) {
                return false;
            }
        }
        
        return true;
    }
}

class SecurityAnalysisVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];
    private array $variableTypes = [];
    private array $userInputs = [];

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Expr\FuncCall) {
            $this->analyzeFunctionCall($node);
        }
        
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
        
        if ($node instanceof Node\Scalar\String_) {
            $this->analyzeStringLiteral($node);
        }
        
        if ($node instanceof Node\Expr\Include_) {
            $this->analyzeInclude($node);
        }
        
        if ($node instanceof Node\Expr\Assign) {
            $this->analyzeAssignment($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeFunctionCall(Node\Expr\FuncCall $node): void
    {
        if ($node->name instanceof Node\Name) {
            $functionName = $node->name->toString();
            
            // Check for unsafe functions
            if (in_array($functionName, $this->configuration['unsafe_functions'])) {
                $this->violations[] = new Violation(
                    'magento-security-unsafe-function',
                    "Unsafe function '{$functionName}' detected",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_critical_security'],
                    Violation::TYPE_SECURITY
                );
            }
            
            // Check for SQL injection vulnerabilities
            if ($this->isSqlFunction($functionName)) {
                $this->checkSqlInjection($node, $functionName);
            }
            
            // Check for XSS vulnerabilities
            if (in_array($functionName, $this->configuration['xss_vulnerable_functions'])) {
                $this->checkXssVulnerability($node, $functionName);
            }
            
            // Check for unsafe file operations
            if (in_array($functionName, $this->configuration['file_operation_functions'])) {
                $this->checkUnsafeFileOperation($node, $functionName);
            }
            
            // Check for unserialize vulnerabilities
            if ($functionName === 'unserialize') {
                $this->violations[] = new Violation(
                    'magento-security-unsafe-unserialize',
                    'unserialize() can be exploited with malicious data. Use json_decode() or secure alternatives.',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for database methods with potential SQL injection
            if ($this->isDatabaseMethod($methodName)) {
                $this->checkSqlInjection($node, $methodName);
            }
            
            // Check for missing input validation
            if ($this->isUserInputMethod($methodName)) {
                $this->checkInputValidation($node, $methodName);
            }
            
            // Check for missing output escaping
            if ($this->isOutputMethod($methodName)) {
                $this->checkOutputEscaping($node, $methodName);
            }
            
            // Check for weak cryptographic methods
            if ($this->isWeakCryptoMethod($methodName)) {
                $this->violations[] = new Violation(
                    'magento-security-weak-crypto',
                    "Weak cryptographic method '{$methodName}' detected",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
    }

    private function analyzeStringLiteral(Node\Scalar\String_ $node): void
    {
        $value = $node->value;
        
        // Check for SQL keywords in strings (potential SQL injection)
        if ($this->containsSqlKeywords($value)) {
            $this->violations[] = new Violation(
                'magento-security-sql-in-string',
                'SQL keywords detected in string literal. Ensure proper parameterization.',
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_medium_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        // Check for JavaScript in strings (potential XSS)
        if ($this->containsJavaScript($value)) {
            $this->violations[] = new Violation(
                'magento-security-javascript-in-string',
                'JavaScript code detected in string literal. Ensure proper escaping.',
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_medium_security'],
                Violation::TYPE_SECURITY
            );
        }
    }

    private function analyzeInclude(Node\Expr\Include_ $node): void
    {
        if ($node->expr instanceof Node\Expr\Variable || 
            $this->containsUserInput($node->expr)) {
            $this->violations[] = new Violation(
                'magento-security-dynamic-include',
                'Dynamic file inclusion can lead to local/remote file inclusion vulnerabilities',
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_critical_security'],
                Violation::TYPE_SECURITY
            );
        }
    }

    private function analyzeAssignment(Node\Expr\Assign $node): void
    {
        // Track user inputs for further analysis
        if ($this->isUserInputAssignment($node)) {
            if ($node->var instanceof Node\Expr\Variable && 
                is_string($node->var->name)) {
                $this->userInputs[] = $node->var->name;
            }
        }
    }

    private function isSqlFunction(string $functionName): bool
    {
        $sqlFunctions = ['mysql_query', 'mysqli_query', 'pg_query', 'sqlite_query'];
        return in_array($functionName, $sqlFunctions);
    }

    private function isDatabaseMethod(string $methodName): bool
    {
        $dbMethods = ['query', 'rawQuery', 'exec', 'prepare', 'fetchAll', 'fetchRow', 'fetchOne'];
        return in_array($methodName, $dbMethods);
    }

    private function isUserInputMethod(string $methodName): bool
    {
        $inputMethods = ['getParam', 'getRequest', 'getPost', 'getCookie', 'getHeader', 'getData'];
        return in_array($methodName, $inputMethods);
    }

    private function isOutputMethod(string $methodName): bool
    {
        $outputMethods = ['echo', 'print', 'toHtml', 'render', 'output'];
        return in_array($methodName, $outputMethods);
    }

    private function isWeakCryptoMethod(string $methodName): bool
    {
        $weakMethods = ['md5', 'sha1', 'crc32', 'base64_encode'];
        return in_array($methodName, $weakMethods);
    }

    private function checkSqlInjection(Node $node, string $functionName): void
    {
        // Check if any arguments contain user input or string concatenation
        if (property_exists($node, 'args') && is_array($node->args)) {
            foreach ($node->args as $arg) {
                if ($this->containsUserInput($arg->value) || 
                    $this->containsStringConcatenation($arg->value)) {
                    $this->violations[] = new Violation(
                        'magento-security-sql-injection',
                        "Potential SQL injection in '{$functionName}'. Use parameterized queries.",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_critical_security'],
                        Violation::TYPE_SECURITY
                    );
                    break;
                }
            }
        }
    }

    private function checkXssVulnerability(Node $node, string $functionName): void
    {
        if (property_exists($node, 'args') && is_array($node->args)) {
            foreach ($node->args as $arg) {
                // Skip if value is already escaped or safe
                if ($this->isEscapedOrSafeOutput($arg->value)) {
                    continue;
                }
                
                // Skip if it's a safe type (int, float, bool)
                if ($this->isSafeType($arg->value)) {
                    continue;
                }
                
                // Skip if it's a safe Magento method call
                if ($this->isSafeMagentoMethod($arg->value)) {
                    continue;
                }
                
                if ($this->containsUserInput($arg->value)) {
                    $this->violations[] = new Violation(
                        'magento-security-xss-vulnerability',
                        "Potential XSS vulnerability in '{$functionName}'. Escape user input.",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_high_security'],
                        Violation::TYPE_SECURITY
                    );
                    break;
                }
            }
        }
    }

    private function checkUnsafeFileOperation(Node $node, string $functionName): void
    {
        if (property_exists($node, 'args') && is_array($node->args) && !empty($node->args)) {
            $firstArg = $node->args[0]->value;
            
            if ($this->containsUserInput($firstArg)) {
                $this->violations[] = new Violation(
                    'magento-security-unsafe-file-operation',
                    "Unsafe file operation '{$functionName}' with user input. Validate and sanitize file paths.",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
    }

    private function checkInputValidation(Node $node, string $methodName): void
    {
        // This is a simplified check - in practice you'd need more sophisticated analysis
        $this->violations[] = new Violation(
            'magento-security-missing-input-validation',
            "User input from '{$methodName}' should be validated before use",
            $this->filePath,
            $node->getStartLine(),
            0,
            $this->configuration['severity_medium_security'],
            Violation::TYPE_SECURITY
        );
    }

    private function checkOutputEscaping(Node $node, string $methodName): void
    {
        if (property_exists($node, 'args') && is_array($node->args)) {
            foreach ($node->args as $arg) {
                if ($this->containsUserInput($arg->value)) {
                    $this->violations[] = new Violation(
                        'magento-security-missing-output-escaping',
                        "Output in '{$methodName}' should be escaped to prevent XSS",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_high_security'],
                        Violation::TYPE_SECURITY
                    );
                    break;
                }
            }
        }
    }

    private function containsUserInput(Node $node): bool
    {
        if ($node instanceof Node\Expr\Variable && 
            is_string($node->name) && 
            in_array($node->name, $this->userInputs)) {
            return true;
        }
        
        // Check for common user input patterns
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            if ($node->var instanceof Node\Expr\Variable) {
                $varName = $node->var->name;
                if (in_array($varName, ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER'])) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function containsStringConcatenation(Node $node): bool
    {
        return $node instanceof Node\Expr\BinaryOp\Concat;
    }

    private function containsSqlKeywords(string $value): bool
    {
        $sqlKeywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'UNION'];
        $upperValue = strtoupper($value);
        
        foreach ($sqlKeywords as $keyword) {
            if (str_contains($upperValue, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    private function containsJavaScript(string $value): bool
    {
        $jsPatterns = ['<script', 'javascript:', 'onclick=', 'onerror=', 'onload='];
        $lowerValue = strtolower($value);
        
        foreach ($jsPatterns as $pattern) {
            if (str_contains($lowerValue, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    private function isUserInputAssignment(Node\Expr\Assign $node): bool
    {
        if ($node->expr instanceof Node\Expr\MethodCall) {
            if ($node->expr->name instanceof Node\Identifier) {
                return $this->isUserInputMethod($node->expr->name->name);
            }
        }
        
        return false;
    }

    /**
     * Check if the node represents a safe type that cannot contain XSS
     */
    private function isSafeType(Node $node): bool
    {
        // Literal numbers, booleans, null
        if ($node instanceof Node\Scalar\Int_ || 
            $node instanceof Node\Scalar\Float_ ||
            $node instanceof Node\Scalar\DNumber ||
            $node instanceof Node\Scalar\LNumber) {
            return true;
        }
        
        // Boolean values
        if ($node instanceof Node\Expr\ConstFetch) {
            $name = $node->name->toString();
            if (in_array(strtolower($name), ['true', 'false', 'null'])) {
                return true;
            }
        }
        
        // Type casts to int, float, bool
        if ($node instanceof Node\Expr\Cast\Int_ ||
            $node instanceof Node\Expr\Cast\Double ||
            $node instanceof Node\Expr\Cast\Bool_) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if the node is already escaped or uses safe output methods
     */
    private function isEscapedOrSafeOutput(Node $node): bool
    {
        // Check if it's a method call to an escaping function
        if ($node instanceof Node\Expr\MethodCall) {
            if ($node->name instanceof Node\Identifier) {
                $methodName = $node->name->name;
                $escapingMethods = [
                    'escapeHtml',
                    'escapeHtmlAttr',
                    'escapeUrl',
                    'escapeCss',
                    'escapeJs',
                    'escapeQuote',
                    'escapeXssInUrl'
                ];
                
                if (in_array($methodName, $escapingMethods)) {
                    return true;
                }
            }
        }
        
        // Check if it's a function call to an escaping function
        if ($node instanceof Node\Expr\FuncCall) {
            if ($node->name instanceof Node\Name) {
                $functionName = $node->name->toString();
                $escapingFunctions = [
                    'htmlspecialchars',
                    'htmlentities',
                    'json_encode',
                    'urlencode',
                    'rawurlencode',
                    'intval',
                    'floatval',
                    'boolval'
                ];
                
                if (in_array($functionName, $escapingFunctions)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if the node is a safe Magento framework method
     */
    private function isSafeMagentoMethod(Node $node): bool
    {
        if ($node instanceof Node\Expr\MethodCall) {
            if ($node->name instanceof Node\Identifier) {
                $methodName = $node->name->name;
                
                // Safe Magento methods that return safe output
                $safeMethods = [
                    'getUrl',              // Magento URL builder
                    'format',              // Currency/date formatting
                    'formatDate',          // Date formatting
                    'formatTime',          // Time formatting
                    'formatPrice',         // Price formatting
                    'getCurrencySymbol',   // Currency symbols
                    'getId',               // Entity IDs
                    'getEntityId',         // Entity IDs
                    'getIncrementId',      // Order/Invoice numbers
                    'getStoreId',          // Store IDs
                    'getCount',            // Counts
                    'getSize',             // Sizes
                    'getTotal',            // Totals
                ];
                
                if (in_array($methodName, $safeMethods)) {
                    return true;
                }
                
                // Check if variable is known safe (like $escaper)
                if ($node->var instanceof Node\Expr\Variable) {
                    $varName = $node->var->name;
                    if (is_string($varName) && in_array($varName, ['escaper', 'block'])) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
} 