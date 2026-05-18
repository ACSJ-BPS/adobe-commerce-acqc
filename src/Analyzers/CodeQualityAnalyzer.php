<?php

declare(strict_types=1);

namespace ACQC\Analyzers;

use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class CodeQualityAnalyzer implements AnalyzerInterface
{
    private Parser $parser;
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function analyze(array $files, array $config = []): array
    {
        $issues = [];
        $metrics = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            try {
                $ast = $this->parser->parse($content);
                if ($ast) {
                    $fileIssues = $this->analyzeFileForQuality($file, $ast, $content);
                    $issues = array_merge($issues, $fileIssues);
                }
            } catch (\Exception $e) {
                $issues[] = [
                    'file' => $file,
                    'line' => 1,
                    'severity' => 'error',
                    'type' => 'parse_error',
                    'message' => 'Failed to parse file: ' . $e->getMessage(),
                ];
            }
        }

        $metrics['code_quality_score'] = $this->calculateQualityScore($issues);
        $metrics['total_issues'] = count($issues);

        return [
            'issues' => $issues,
            'metrics' => $metrics,
            'recommendations' => $this->generateQualityRecommendations($issues),
        ];
    }

    public function getName(): string
    {
        return 'Code Quality Analyzer';
    }

    public function getDescription(): string
    {
        return 'Analyzes general code quality, complexity, and maintainability';
    }

    public function getSupportedSeverities(): array
    {
        return ['error', 'warning', 'info'];
    }

    private function analyzeFileForQuality(string $file, array $ast, string $content): array
    {
        $issues = [];

        // Check for long methods
        $issues = array_merge($issues, $this->checkMethodLength($file, $ast));

        // Check for high cyclomatic complexity
        $issues = array_merge($issues, $this->checkComplexity($file, $ast));

        // Check for duplicate code
        $issues = array_merge($issues, $this->checkDuplicateCode($file, $content));

        // Check for proper documentation
        $issues = array_merge($issues, $this->checkDocumentation($file, $ast));

        // Check for naming conventions
        $issues = array_merge($issues, $this->checkNamingConventions($file, $ast));

        return $issues;
    }

    private function checkMethodLength(string $file, array $ast): array
    {
        $issues = [];
        $methods = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            $methodLength = $method->getEndLine() - $method->getStartLine();
            
            if ($methodLength > 50) {
                $issues[] = [
                    'file' => $file,
                    'line' => $method->getStartLine(),
                    'severity' => $methodLength > 100 ? 'error' : 'warning',
                    'type' => 'long_method',
                    'message' => sprintf('Method %s is too long (%d lines)', $method->name->toString(), $methodLength),
                ];
            }
        }

        return $issues;
    }

    private function checkComplexity(string $file, array $ast): array
    {
        $issues = [];
        $methods = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            $complexity = $this->calculateCyclomaticComplexity($method);
            
            if ($complexity > 10) {
                $issues[] = [
                    'file' => $file,
                    'line' => $method->getStartLine(),
                    'severity' => $complexity > 20 ? 'error' : 'warning',
                    'type' => 'high_complexity',
                    'message' => sprintf('Method %s has high cyclomatic complexity (%d)', $method->name->toString(), $complexity),
                ];
            }
        }

        return $issues;
    }

    private function checkDuplicateCode(string $file, string $content): array
    {
        $issues = [];
        $lines = explode("\n", $content);
        $duplicateThreshold = 5;

        for ($i = 0; $i < count($lines) - $duplicateThreshold; $i++) {
            $block = array_slice($lines, $i, $duplicateThreshold);
            $blockStr = implode("\n", array_map('trim', $block));
            
            if (strlen(trim($blockStr)) < 20) continue; // Skip short blocks
            
            for ($j = $i + $duplicateThreshold; $j < count($lines) - $duplicateThreshold; $j++) {
                $compareBlock = array_slice($lines, $j, $duplicateThreshold);
                $compareBlockStr = implode("\n", array_map('trim', $compareBlock));
                
                if ($blockStr === $compareBlockStr) {
                    $issues[] = [
                        'file' => $file,
                        'line' => $i + 1,
                        'severity' => 'warning',
                        'type' => 'duplicate_code',
                        'message' => sprintf('Duplicate code block found (lines %d-%d and %d-%d)', $i + 1, $i + $duplicateThreshold, $j + 1, $j + $duplicateThreshold),
                    ];
                    break;
                }
            }
        }

        return $issues;
    }

    private function checkDocumentation(string $file, array $ast): array
    {
        $issues = [];
        $classes = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\Class_::class);
        $methods = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\ClassMethod::class);

        // Check class documentation
        foreach ($classes as $class) {
            if (!$this->hasDocBlock($class)) {
                $issues[] = [
                    'file' => $file,
                    'line' => $class->getStartLine(),
                    'severity' => 'warning',
                    'type' => 'missing_documentation',
                    'message' => sprintf('Class %s is missing documentation', $class->name?->toString() ?? 'anonymous'),
                ];
            }
        }

        // Check public method documentation
        foreach ($methods as $method) {
            if ($method->isPublic() && !$this->hasDocBlock($method)) {
                $issues[] = [
                    'file' => $file,
                    'line' => $method->getStartLine(),
                    'severity' => 'info',
                    'type' => 'missing_documentation',
                    'message' => sprintf('Public method %s is missing documentation', $method->name->toString()),
                ];
            }
        }

        return $issues;
    }

    private function checkNamingConventions(string $file, array $ast): array
    {
        $issues = [];
        $classes = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\Class_::class);
        $methods = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\ClassMethod::class);

        // Check class naming (PascalCase)
        foreach ($classes as $class) {
            $className = $class->name?->toString() ?? '';
            if ($className !== '' && !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $className)) {
                $issues[] = [
                    'file' => $file,
                    'line' => $class->getStartLine(),
                    'severity' => 'warning',
                    'type' => 'naming_convention',
                    'message' => sprintf('Class name %s should follow PascalCase convention', $className),
                ];
            }
        }

        // Check method naming (camelCase)
        foreach ($methods as $method) {
            $methodName = $method->name->toString();
            if (!in_array($methodName, ['__construct', '__destruct']) && !preg_match('/^[a-z][a-zA-Z0-9]*$/', $methodName)) {
                $issues[] = [
                    'file' => $file,
                    'line' => $method->getStartLine(),
                    'severity' => 'info',
                    'type' => 'naming_convention',
                    'message' => sprintf('Method name %s should follow camelCase convention', $methodName),
                ];
            }
        }

        return $issues;
    }

    private function calculateCyclomaticComplexity($method): int
    {
        $complexity = 1; // Base complexity
        
        $complexityNodes = $this->nodeFinder->find($method->stmts, function($node) {
            return $node instanceof \PhpParser\Node\Stmt\If_ ||
                   $node instanceof \PhpParser\Node\Stmt\ElseIf_ ||
                   $node instanceof \PhpParser\Node\Stmt\For_ ||
                   $node instanceof \PhpParser\Node\Stmt\Foreach_ ||
                   $node instanceof \PhpParser\Node\Stmt\While_ ||
                   $node instanceof \PhpParser\Node\Stmt\Do_ ||
                   $node instanceof \PhpParser\Node\Stmt\Switch_ ||
                   $node instanceof \PhpParser\Node\Stmt\Case_ ||
                   $node instanceof \PhpParser\Node\Stmt\Catch_ ||
                   $node instanceof \PhpParser\Node\Expr\Ternary ||
                   $node instanceof \PhpParser\Node\Expr\BinaryOp\LogicalAnd ||
                   $node instanceof \PhpParser\Node\Expr\BinaryOp\LogicalOr;
        });

        return $complexity + count($complexityNodes);
    }

    private function hasDocBlock($node): bool
    {
        $comments = $node->getAttribute('comments');
        if (!$comments) {
            return false;
        }

        foreach ($comments as $comment) {
            if ($comment instanceof \PhpParser\Comment\Doc) {
                return true;
            }
        }

        return false;
    }

    private function calculateQualityScore(array $issues): int
    {
        $errorCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'error'));
        $warningCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'warning'));
        $infoCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'info'));
        
        $baseScore = 100;
        $penalty = ($errorCount * 10) + ($warningCount * 5) + ($infoCount * 1);
        
        return max(0, $baseScore - $penalty);
    }

    private function generateQualityRecommendations(array $issues): array
    {
        $recommendations = [];
        $issueTypes = array_count_values(array_column($issues, 'type'));

        if (($issueTypes['long_method'] ?? 0) > 0) {
            $recommendations[] = 'Break down long methods into smaller, more focused functions';
        }

        if (($issueTypes['high_complexity'] ?? 0) > 0) {
            $recommendations[] = 'Reduce cyclomatic complexity by simplifying conditional logic';
        }

        if (($issueTypes['duplicate_code'] ?? 0) > 0) {
            $recommendations[] = 'Extract duplicate code into reusable methods or classes';
        }

        if (($issueTypes['missing_documentation'] ?? 0) > 0) {
            $recommendations[] = 'Add proper documentation to improve code maintainability';
        }

        if (($issueTypes['naming_convention'] ?? 0) > 0) {
            $recommendations[] = 'Follow consistent naming conventions for better code readability';
        }

        return $recommendations;
    }
} 