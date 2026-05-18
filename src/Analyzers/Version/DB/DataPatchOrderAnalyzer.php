<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\DB;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

class DataPatchOrderAnalyzer
{
    public function __construct(private readonly string $projectRoot) {}

    public function analyze(): array
    {
        $appCodeDir = $this->projectRoot . '/app/code';
        if (!is_dir($appCodeDir)) {
            return [];
        }

        $parser   = (new ParserFactory())->createForNewestSupportedVersion();
        $patches  = [];
        $findings = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appCodeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Only scan Setup/Patch/Data/*.php
            $path = $file->getPathname();
            if (!str_contains(str_replace('\\', '/', $path), '/Setup/Patch/Data/')) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            try {
                $ast = $parser->parse($content);
            } catch (Error) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $info = $this->extractPatchInfo($ast, $path, $content);
            if ($info !== null) {
                $patches[$info['class']] = $info;
            }
        }

        // Detect circular dependencies via DFS
        $circularFindings = $this->detectCircularDependencies($patches);
        foreach ($circularFindings as $f) {
            $findings[] = $f;
        }

        // Detect hardcoded table names in apply()
        foreach ($patches as $info) {
            if ($info['hardcoded_tables']) {
                $findings[] = [
                    'type'        => 'data_patch_hardcoded_table',
                    'severity'    => 'warning',
                    'file'        => $info['file'],
                    'class'       => $info['class'],
                    'message'     => "DataPatch {$info['class']} uses hardcoded table name. "
                        . 'Use ResourceModel abstraction or $this->moduleDataSetup->getTable().',
                    'remediation' => 'Replace hardcoded table names with: '
                        . '$tableName = $this->moduleDataSetup->getTable(\'table_name\');',
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array{class:string, file:string, dependencies:string[], hardcoded_tables:bool}|null
     */
    private function extractPatchInfo(array $ast, string $filepath, string $content): ?array
    {
        $visitor = new class extends NodeVisitorAbstract {
            public ?string $namespace  = null;
            public ?string $className  = null;
            public array   $deps       = [];
            public bool    $hasApply   = false;
            public bool    $applyHardcoded = false;

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                }

                if ($node instanceof Node\Stmt\Class_) {
                    $this->className = $node->name?->toString();
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    if ($node->name->toString() === 'getDependencies') {
                        $this->deps = $this->extractReturnStrings($node);
                    }
                    if ($node->name->toString() === 'apply') {
                        $this->hasApply = true;
                        $this->applyHardcoded = $this->detectHardcodedTable($node);
                    }
                }

                return null;
            }

            private function extractReturnStrings(Node\Stmt\ClassMethod $method): array
            {
                $result = [];
                foreach ($method->stmts ?? [] as $stmt) {
                    if (!$stmt instanceof Node\Stmt\Return_) {
                        continue;
                    }
                    $expr = $stmt->expr;
                    if (!$expr instanceof Node\Expr\Array_) {
                        continue;
                    }
                    foreach ($expr->items as $item) {
                        if ($item === null) {
                            continue;
                        }
                        $val = $item->value;
                        if ($val instanceof Node\Expr\ClassConstFetch
                            && $val->name instanceof Node\Identifier
                            && $val->name->toString() === 'class'
                            && $val->class instanceof Node\Name
                        ) {
                            $result[] = $val->class->toString();
                        } elseif ($val instanceof Node\Scalar\String_) {
                            $result[] = $val->value;
                        }
                    }
                }
                return $result;
            }

            private function detectHardcodedTable(Node\Stmt\ClassMethod $method): bool
            {
                // Walk all nodes in the method looking for ->query("...") or ->query('...')
                // with a non-getTable() string literal
                $traverser = new NodeTraverser();
                $found     = false;

                $inner = new class ($found) extends NodeVisitorAbstract {
                    public function __construct(private bool &$flag) {}

                    public function enterNode(Node $node): null
                    {
                        if (!$node instanceof Node\Expr\MethodCall) {
                            return null;
                        }
                        $name = $node->name instanceof Node\Identifier
                            ? $node->name->toString()
                            : '';
                        if (!in_array($name, ['query', 'exec', 'execute'], true)) {
                            return null;
                        }
                        foreach ($node->args as $arg) {
                            $val = $arg->value;
                            if ($val instanceof Node\Scalar\String_
                                && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|INTO|JOIN)\b/i', $val->value)
                            ) {
                                $this->flag = true;
                            }
                        }
                        return null;
                    }
                };

                $traverser->addVisitor($inner);
                $traverser->traverse($method->stmts ?? []);
                return $found;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if ($visitor->className === null) {
            return null;
        }

        $fqcn = ($visitor->namespace !== null)
            ? $visitor->namespace . '\\' . $visitor->className
            : $visitor->className;

        return [
            'class'           => $fqcn,
            'file'            => $filepath,
            'dependencies'    => $visitor->deps,
            'hardcoded_tables' => $visitor->applyHardcoded,
        ];
    }

    private function detectCircularDependencies(array $patches): array
    {
        $findings = [];
        $visited  = [];
        $stack    = [];

        foreach (array_keys($patches) as $class) {
            if (!isset($visited[$class])) {
                $cycle = $this->dfs($class, $patches, $visited, $stack);
                if ($cycle !== null) {
                    $findings[] = [
                        'type'        => 'data_patch_circular_dependency',
                        'severity'    => 'error',
                        'class'       => $class,
                        'file'        => $patches[$class]['file'] ?? '',
                        'cycle'       => $cycle,
                        'message'     => "DataPatch circular dependency detected: " . implode(' → ', $cycle),
                        'remediation' => 'Refactor getDependencies() to remove the cycle. '
                            . 'Extract shared logic into a separate non-patch class.',
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string,bool> $visited
     * @param array<string,bool> $stack
     * @return string[]|null  The cycle path, or null if none found
     */
    private function dfs(string $class, array $patches, array &$visited, array &$stack): ?array
    {
        $visited[$class] = true;
        $stack[$class]   = true;

        $deps = $patches[$class]['dependencies'] ?? [];
        foreach ($deps as $dep) {
            if (!isset($patches[$dep])) {
                continue; // external/core patch — skip
            }
            if (!isset($visited[$dep])) {
                $result = $this->dfs($dep, $patches, $visited, $stack);
                if ($result !== null) {
                    return $result;
                }
            } elseif (isset($stack[$dep])) {
                // Cycle found — reconstruct path
                $path   = array_keys($stack);
                $start  = array_search($dep, $path, true);
                $cycle  = array_slice($path, (int)$start);
                $cycle[] = $dep;
                return $cycle;
            }
        }

        unset($stack[$class]);
        return null;
    }
}
