<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Frontend;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class ReactAnalyzer extends AbstractAnalyzer
{
    private array $componentPatterns = [];
    private array $hookUsages = [];
    
    public function getName(): string
    {
        return 'react-analyzer';
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
            'check_component_naming' => true,
            'check_hook_usage' => true,
            'check_component_structure' => true,
            'check_performance_issues' => true,
            'check_security_issues' => true,
            'check_jsx_patterns' => true,
            'check_state_management' => true,
            'check_prop_types' => true,
            'severity_naming_issues' => Violation::SEVERITY_WARNING,
            'severity_performance_issues' => Violation::SEVERITY_WARNING,
            'severity_security_issues' => Violation::SEVERITY_ERROR,
            'severity_hook_issues' => Violation::SEVERITY_ERROR,
            'max_component_lines' => 200,
            'max_jsx_depth' => 10,
            'max_props_count' => 10,
            'supported_extensions' => ['.js', '.jsx', '.ts', '.tsx'],
            'react_hooks' => [
                'useState', 'useEffect', 'useContext', 'useReducer',
                'useCallback', 'useMemo', 'useRef', 'useImperativeHandle',
                'useLayoutEffect', 'useDebugValue', 'useDeferredValue',
                'useTransition', 'useId', 'useSyncExternalStore'
            ],
            'deprecated_patterns' => [
                'componentWillMount',
                'componentWillReceiveProps',
                'componentWillUpdate',
                'findDOMNode',
                'String refs',
                'Legacy Context API'
            ],
            'performance_antipatterns' => [
                'inline functions in JSX',
                'object creation in render',
                'array creation in render',
                'conditional rendering without keys',
                'unnecessary re-renders',
                'large component trees'
            ],
            'security_patterns' => [
                'dangerouslySetInnerHTML',
                'target="_blank" without rel',
                'eval() usage',
                'document.write',
                'innerHTML assignment',
                'unvalidated user input'
            ],
            'pwa_studio_patterns' => [
                '@magento/pwa-buildpack',
                '@magento/peregrine',
                '@magento/venia-ui',
                'useQuery',
                'useMutation',
                'useCartContext',
                'useUserContext'
            ],
            'exclude_paths' => [
                '*/node_modules/*',
                '*/dist/*',
                '*/build/*',
                '*/coverage/*',
                '*/test/*',
                '*/tests/*',
                '*/__tests__/*',
                '*.test.js',
                '*.test.jsx',
                '*.test.ts',
                '*.test.tsx',
                '*.spec.js',
                '*.spec.jsx',
                '*.spec.ts',
                '*.spec.tsx'
            ],
        ];
    }

    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->supportedFileExtensions = ['js', 'jsx', 'ts', 'tsx'];
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
        $this->logger->info('Starting React code analysis');
        
        $violations = [];
        
        foreach ($files as $file) {
            if (!$this->shouldAnalyzeFile($file)) {
                continue;
            }
            
            if ($this->isReactFile($file)) {
                $violations = array_merge($violations, $this->analyzeReactFile($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' React violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function isReactFile(string $filePath): bool
    {
        $extension = $this->getFileExtension($filePath);
        return in_array($extension, $this->configuration['supported_extensions']);
    }

    private function analyzeReactFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check if file contains React code
        if (!$this->containsReactCode($content)) {
            return $violations;
        }
        
        // Component structure analysis
        $violations = array_merge($violations, $this->analyzeComponentStructure($content, $filePath));
        
        // Hook usage analysis
        $violations = array_merge($violations, $this->analyzeHookUsage($content, $filePath));
        
        // JSX patterns analysis
        $violations = array_merge($violations, $this->analyzeJSXPatterns($content, $filePath));
        
        // Performance analysis
        $violations = array_merge($violations, $this->analyzePerformancePatterns($content, $filePath));
        
        // Security analysis
        $violations = array_merge($violations, $this->analyzeSecurityPatterns($content, $filePath));
        
        // PWA Studio specific analysis
        $violations = array_merge($violations, $this->analyzePWAStudioPatterns($content, $filePath));
        
        return $violations;
    }

    private function containsReactCode(string $content): bool
    {
        $reactIndicators = [
            'import React',
            'from \'react\'',
            'from "react"',
            'React.Component',
            'React.createElement',
            'jsx',
            'tsx',
            'useState',
            'useEffect',
            'export default function',
            'export const'
        ];
        
        foreach ($reactIndicators as $indicator) {
            if (str_contains($content, $indicator)) {
                return true;
            }
        }
        
        return false;
    }

    private function analyzeComponentStructure(string $content, string $filePath): array
    {
        $violations = [];
        $lines = explode("\n", $content);
        
        // Check component naming
        if (preg_match('/(?:function|const|class)\s+([a-z][a-zA-Z0-9]*)/m', $content, $matches)) {
            $componentName = $matches[1];
            $violations[] = new Violation(
                'react-component-naming-convention',
                "Component '{$componentName}' should start with uppercase letter (PascalCase)",
                $filePath,
                $this->findLineNumber($content, $matches[0]),
                0,
                $this->configuration['severity_naming_issues'],
                Violation::TYPE_STYLE
            );
        }
        
        // Check component size
        if (count($lines) > $this->configuration['max_component_lines']) {
            $violations[] = new Violation(
                'react-component-too-large',
                'Component exceeds maximum recommended size of ' . $this->configuration['max_component_lines'] . ' lines',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_issues'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        // Check for proper default export
        if (!preg_match('/export\s+default\s+/m', $content)) {
            $violations[] = new Violation(
                'react-missing-default-export',
                'React component should have a default export',
                $filePath,
                0,
                0,
                $this->configuration['severity_naming_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        // Check for deprecated lifecycle methods
        foreach ($this->configuration['deprecated_patterns'] as $pattern) {
            if (str_contains($content, $pattern)) {
                $line = $this->findLineNumber($content, $pattern);
                $violations[] = new Violation(
                    'react-deprecated-pattern',
                    "Deprecated React pattern '{$pattern}' detected",
                    $filePath,
                    $line,
                    0,
                    Violation::SEVERITY_ERROR,
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function analyzeHookUsage(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check hook rules
        foreach ($this->configuration['react_hooks'] as $hook) {
            if (preg_match_all('/\b' . preg_quote($hook) . '\s*\(/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    
                    // Check if hook is called inside a loop, condition, or nested function
                    $beforeHook = substr($content, max(0, $match[1] - 100), 100);
                    
                    if (preg_match('/\b(for|while|if|switch)\s*\(/m', $beforeHook)) {
                        $violations[] = new Violation(
                            'react-hook-conditional-call',
                            "Hook '{$hook}' should not be called inside loops, conditions, or nested functions",
                            $filePath,
                            $line,
                            0,
                            $this->configuration['severity_hook_issues'],
                            Violation::TYPE_LOGIC
                        );
                    }
                }
            }
        }
        
        // Check useEffect dependency arrays
        if (preg_match_all('/useEffect\s*\(\s*[^,]+,\s*\[([^\]]*)\]/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                $dependencies = trim($matches[1][$index][0]);
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                
                if (empty($dependencies)) {
                    $violations[] = new Violation(
                        'react-useeffect-empty-deps',
                        'useEffect with empty dependency array may cause performance issues',
                        $filePath,
                        $line,
                        0,
                        $this->configuration['severity_performance_issues'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
        }
        
        // Check for missing dependency in useCallback/useMemo
        $dependencyHooks = ['useCallback', 'useMemo'];
        foreach ($dependencyHooks as $hook) {
            if (preg_match_all('/' . $hook . '\s*\([^)]+\)/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    if (!str_contains($match[0], '[')) {
                        $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                        $violations[] = new Violation(
                            'react-hook-missing-deps',
                            "'{$hook}' is missing dependency array",
                            $filePath,
                            $line,
                            0,
                            $this->configuration['severity_hook_issues'],
                            Violation::TYPE_LOGIC
                        );
                    }
                }
            }
        }
        
        return $violations;
    }

    private function analyzeJSXPatterns(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for missing keys in lists
        if (preg_match_all('/\.map\s*\([^)]+\)\s*=>\s*<[^>]+>/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                if (!str_contains($match[0], 'key=')) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $violations[] = new Violation(
                        'react-missing-key-prop',
                        'Missing key prop in list rendering',
                        $filePath,
                        $line,
                        0,
                        $this->configuration['severity_performance_issues'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
        }
        
        // Check JSX depth
        $jsxDepth = $this->calculateJSXDepth($content);
        if ($jsxDepth > $this->configuration['max_jsx_depth']) {
            $violations[] = new Violation(
                'react-jsx-too-deep',
                "JSX nesting depth ({$jsxDepth}) exceeds recommended maximum of {$this->configuration['max_jsx_depth']}",
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_issues'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        // Check for inline styles (performance issue)
        if (preg_match_all('/style\s*=\s*\{[^}]+\}/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $violations[] = new Violation(
                    'react-inline-styles',
                    'Inline styles in JSX can cause performance issues. Consider using CSS classes.',
                    $filePath,
                    $line,
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        return $violations;
    }

    private function analyzePerformancePatterns(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for inline functions in JSX
        if (preg_match_all('/\s+\w+\s*=\s*\{[^}]*=>[^}]*\}/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $violations[] = new Violation(
                    'react-inline-function-jsx',
                    'Inline function in JSX can cause unnecessary re-renders. Consider useCallback.',
                    $filePath,
                    $line,
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        // Check for object creation in render
        if (preg_match_all('/\{\s*[a-zA-Z_][a-zA-Z0-9_]*\s*:\s*[^}]+\s*\}/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                // Check if it's inside JSX
                $beforeMatch = substr($content, max(0, $match[1] - 50), 50);
                $afterMatch = substr($content, $match[1], 100);
                
                if (str_contains($beforeMatch . $afterMatch, '<') && str_contains($beforeMatch . $afterMatch, '>')) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $violations[] = new Violation(
                        'react-object-creation-render',
                        'Object creation in render can cause performance issues. Consider useMemo.',
                        $filePath,
                        $line,
                        0,
                        $this->configuration['severity_performance_issues'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
        }
        
        // Check for large prop drilling
        $propCount = preg_match_all('/\s+[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*\{[^}]+\}/m', $content);
        if ($propCount > $this->configuration['max_props_count']) {
            $violations[] = new Violation(
                'react-too-many-props',
                "Component has {$propCount} props, consider using context or composition",
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_issues'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        return $violations;
    }

    private function analyzeSecurityPatterns(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for dangerouslySetInnerHTML
        if (preg_match_all('/dangerouslySetInnerHTML/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $violations[] = new Violation(
                    'react-dangerous-inner-html',
                    'dangerouslySetInnerHTML can lead to XSS vulnerabilities',
                    $filePath,
                    $line,
                    0,
                    $this->configuration['severity_security_issues'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for target="_blank" without rel="noopener"
        if (preg_match_all('/target\s*=\s*["\']_blank["\'][^>]*(?!.*rel\s*=\s*["\'][^"\']*noopener)/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $violations[] = new Violation(
                    'react-target-blank-security',
                    'target="_blank" without rel="noopener" is a security risk',
                    $filePath,
                    $line,
                    0,
                    $this->configuration['severity_security_issues'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for eval usage
        if (str_contains($content, 'eval(')) {
            $line = $this->findLineNumber($content, 'eval(');
            $violations[] = new Violation(
                'react-eval-usage',
                'eval() usage is a security risk and should be avoided',
                $filePath,
                $line,
                0,
                Violation::SEVERITY_CRITICAL,
                Violation::TYPE_SECURITY
            );
        }
        
        return $violations;
    }

    private function analyzePWAStudioPatterns(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for PWA Studio imports
        foreach ($this->configuration['pwa_studio_patterns'] as $pattern) {
            if (str_contains($content, $pattern)) {
                // PWA Studio specific checks
                
                // Check for proper error handling in GraphQL
                if (str_contains($pattern, 'useQuery') || str_contains($pattern, 'useMutation')) {
                    if (!str_contains($content, 'error') || !str_contains($content, 'loading')) {
                        $line = $this->findLineNumber($content, $pattern);
                        $violations[] = new Violation(
                            'pwa-studio-missing-error-handling',
                            'PWA Studio GraphQL hooks should handle error and loading states',
                            $filePath,
                            $line,
                            0,
                            $this->configuration['severity_hook_issues'],
                            Violation::TYPE_LOGIC
                        );
                    }
                }
                
                // Check for proper context usage
                if (str_contains($pattern, 'Context')) {
                    if (!str_contains($content, 'Provider')) {
                        $line = $this->findLineNumber($content, $pattern);
                        $violations[] = new Violation(
                            'pwa-studio-context-usage',
                            'PWA Studio context should be properly wrapped with Provider',
                            $filePath,
                            $line,
                            0,
                            $this->configuration['severity_hook_issues'],
                            Violation::TYPE_LOGIC
                        );
                    }
                }
            }
        }
        
        return $violations;
    }

    private function calculateJSXDepth(string $content): int
    {
        $maxDepth = 0;
        $currentDepth = 0;
        $inJSX = false;
        
        $chars = str_split($content);
        $i = 0;
        
        while ($i < count($chars)) {
            if ($chars[$i] === '<' && $i + 1 < count($chars) && $chars[$i + 1] !== '/') {
                $inJSX = true;
                $currentDepth++;
                $maxDepth = max($maxDepth, $currentDepth);
            } elseif ($chars[$i] === '<' && $i + 1 < count($chars) && $chars[$i + 1] === '/') {
                $currentDepth = max(0, $currentDepth - 1);
            } elseif ($chars[$i] === '/' && $i + 1 < count($chars) && $chars[$i + 1] === '>') {
                $currentDepth = max(0, $currentDepth - 1);
            }
            $i++;
        }
        
        return $maxDepth;
    }

    private function findLineNumber(string $content, string $search): int
    {
        $pos = strpos($content, $search);
        if ($pos === false) {
            return 0;
        }
        return substr_count(substr($content, 0, $pos), "\n") + 1;
    }

    private function getFileExtension(string $filePath): string
    {
        $pathInfo = pathinfo($filePath);
        return '.' . ($pathInfo['extension'] ?? '');
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