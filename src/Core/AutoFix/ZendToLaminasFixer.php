<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix;

/**
 * Zend Framework to Laminas Migration Fixer
 * 
 * Automatically converts Zend Framework references to Laminas
 */
class ZendToLaminasFixer implements FixerInterface
{
    private array $replacements = [
        // Namespace mappings
        'Zend\\' => 'Laminas\\',
        'Zend_' => 'Laminas_',
        'ZendFramework' => 'Laminas',
        
        // Common class names in comments
        'Zend Framework' => 'Laminas',
        
        // Use statements
        'use Zend\\\\' => 'use Laminas\\\\',
        
        // Composer package names
        '"zendframework/' => '"laminas/',
        "'zendframework/" => "'laminas/",
        
        // Common variable names
        '$zend' => '$laminas',
        '$_zend' => '$_laminas',
        
        // Configuration keys (be careful here)
        "'zend'" => "'laminas'",
        '"zend"' => '"laminas"',
    ];
    
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'zend-to-laminas';
    }
    
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Converts Zend Framework references to Laminas';
    }
    
    /**
     * {@inheritdoc}
     */
    public function canFix(string $filePath, string $content): bool
    {
        // Check if file contains any Zend references
        return preg_match('/\b(Zend[_\\\\]|ZendFramework|zendframework)\b/i', $content) === 1;
    }
    
    /**
     * {@inheritdoc}
     */
    public function fix(string $content): array
    {
        $originalContent = $content;
        $changes = [];
        
        // Apply each replacement
        foreach ($this->replacements as $search => $replace) {
            $count = 0;
            $content = str_replace($search, $replace, $content, $count);
            
            if ($count > 0) {
                $changes[] = [
                    'type' => 'replacement',
                    'from' => $search,
                    'to' => $replace,
                    'count' => $count,
                    'description' => sprintf('Replaced %d occurrence(s) of "%s" with "%s"', $count, $search, $replace)
                ];
            }
        }
        
        // Handle namespace declarations specifically
        $content = preg_replace_callback(
            '/namespace\s+Zend([\\\\a-zA-Z0-9_]+)?;/i',
            function ($matches) use (&$changes) {
                $oldNamespace = 'Zend' . ($matches[1] ?? '');
                $newNamespace = 'Laminas' . ($matches[1] ?? '');
                
                $changes[] = [
                    'type' => 'namespace',
                    'from' => $oldNamespace,
                    'to' => $newNamespace,
                    'description' => sprintf('Updated namespace from "%s" to "%s"', $oldNamespace, $newNamespace)
                ];
                
                return 'namespace ' . $newNamespace . ';';
            },
            $content
        );
        
        // Handle extends and implements clauses
        $content = preg_replace_callback(
            '/(extends|implements)\s+Zend[\\\\a-zA-Z0-9_]+/i',
            function ($matches) use (&$changes) {
                $old = $matches[0];
                $new = str_replace('Zend\\', 'Laminas\\', $old);
                $new = str_replace('Zend_', 'Laminas_', $new);
                
                if ($old !== $new) {
                    $changes[] = [
                        'type' => 'inheritance',
                        'from' => $old,
                        'to' => $new,
                        'description' => sprintf('Updated inheritance: "%s" to "%s"', $old, $new)
                    ];
                }
                
                return $new;
            },
            $content
        );
        
        // Handle type hints
        $content = preg_replace_callback(
            '/([:(,\s])\\\\?Zend([\\\\a-zA-Z0-9_]+)/i',
            function ($matches) use (&$changes) {
                $prefix = $matches[1];
                $suffix = $matches[2];
                $old = 'Zend' . $suffix;
                $new = 'Laminas' . $suffix;
                
                $changes[] = [
                    'type' => 'type-hint',
                    'from' => $old,
                    'to' => $new,
                    'description' => sprintf('Updated type hint from "%s" to "%s"', $old, $new)
                ];
                
                return $prefix . '\\Laminas' . $suffix;
            },
            $content
        );
        
        return [
            'modified' => $content !== $originalContent,
            'content' => $content,
            'changes' => $changes
        ];
    }
}

