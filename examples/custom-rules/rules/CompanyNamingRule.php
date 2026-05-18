<?php

namespace Example\CustomRules\Rules;

use M2Quality\Core\Rules\AbstractRule;
use M2Quality\Core\Rules\Violation;

/**
 * Company Naming Convention Rule
 * 
 * Enforces company-specific naming conventions for classes, methods, and variables
 */
class CompanyNamingRule extends AbstractRule
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'class_prefix_required' => true,
            'company_prefix' => 'Company',
            'interface_suffix' => 'Interface',
            'abstract_prefix' => 'Abstract',
            'method_naming' => 'camelCase',
            'constant_naming' => 'UPPER_SNAKE_CASE'
        ], $config);
    }

    public function getName(): string
    {
        return 'company-naming-convention';
    }

    public function getDescription(): string
    {
        return 'Enforces company-specific naming conventions';
    }

    public function check(string $filePath, string $content): array
    {
        $violations = [];
        $tokens = token_get_all($content);

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                switch ($token[0]) {
                    case T_CLASS:
                        $violations = array_merge($violations, $this->checkClassName($tokens, $i, $filePath));
                        break;
                    case T_INTERFACE:
                        $violations = array_merge($violations, $this->checkInterfaceName($tokens, $i, $filePath));
                        break;
                    case T_FUNCTION:
                        $violations = array_merge($violations, $this->checkMethodName($tokens, $i, $filePath));
                        break;
                    case T_CONST:
                        $violations = array_merge($violations, $this->checkConstantName($tokens, $i, $filePath));
                        break;
                }
            }
        }

        return $violations;
    }

    private function checkClassName(array $tokens, int $index, string $filePath): array
    {
        $violations = [];
        $className = $this->getNextIdentifier($tokens, $index);
        
        if (!$className) {
            return $violations;
        }

        // Check class prefix requirement
        if ($this->config['class_prefix_required']) {
            $prefix = $this->config['company_prefix'];
            if (!str_starts_with($className['name'], $prefix)) {
                $violations[] = new Violation(
                    'class-naming-prefix',
                    "Class '{$className['name']}' should start with '{$prefix}' prefix",
                    $filePath,
                    $className['line'],
                    'warning'
                );
            }
        }

        // Check PascalCase naming
        if (!$this->isPascalCase($className['name'])) {
            $violations[] = new Violation(
                'class-naming-case',
                "Class '{$className['name']}' should use PascalCase naming",
                $filePath,
                $className['line'],
                'warning'
            );
        }

        return $violations;
    }

    private function checkInterfaceName(array $tokens, int $index, string $filePath): array
    {
        $violations = [];
        $interfaceName = $this->getNextIdentifier($tokens, $index);
        
        if (!$interfaceName) {
            return $violations;
        }

        $suffix = $this->config['interface_suffix'];
        if (!str_ends_with($interfaceName['name'], $suffix)) {
            $violations[] = new Violation(
                'interface-naming-suffix',
                "Interface '{$interfaceName['name']}' should end with '{$suffix}' suffix",
                $filePath,
                $interfaceName['line'],
                'warning'
            );
        }

        return $violations;
    }

    private function checkMethodName(array $tokens, int $index, string $filePath): array
    {
        $violations = [];
        $methodName = $this->getNextIdentifier($tokens, $index);
        
        if (!$methodName || $this->isConstructorOrMagicMethod($methodName['name'])) {
            return $violations;
        }

        if ($this->config['method_naming'] === 'camelCase' && !$this->isCamelCase($methodName['name'])) {
            $violations[] = new Violation(
                'method-naming-case',
                "Method '{$methodName['name']}' should use camelCase naming",
                $filePath,
                $methodName['line'],
                'info'
            );
        }

        return $violations;
    }

    private function checkConstantName(array $tokens, int $index, string $filePath): array
    {
        $violations = [];
        $constantName = $this->getNextIdentifier($tokens, $index);
        
        if (!$constantName) {
            return $violations;
        }

        if ($this->config['constant_naming'] === 'UPPER_SNAKE_CASE' && !$this->isUpperSnakeCase($constantName['name'])) {
            $violations[] = new Violation(
                'constant-naming-case',
                "Constant '{$constantName['name']}' should use UPPER_SNAKE_CASE naming",
                $filePath,
                $constantName['line'],
                'info'
            );
        }

        return $violations;
    }

    private function getNextIdentifier(array $tokens, int $startIndex): ?array
    {
        for ($i = $startIndex + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_STRING) {
                return [
                    'name' => $token[1],
                    'line' => $token[2]
                ];
            }
        }
        return null;
    }

    private function isPascalCase(string $name): bool
    {
        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name);
    }

    private function isCamelCase(string $name): bool
    {
        return preg_match('/^[a-z][a-zA-Z0-9]*$/', $name);
    }

    private function isUpperSnakeCase(string $name): bool
    {
        return preg_match('/^[A-Z][A-Z0-9_]*$/', $name);
    }

    private function isConstructorOrMagicMethod(string $name): bool
    {
        return in_array($name, ['__construct', '__destruct', '__call', '__callStatic', '__get', '__set', '__isset', '__unset', '__sleep', '__wakeup', '__toString', '__invoke', '__set_state', '__clone', '__debugInfo']);
    }
} 