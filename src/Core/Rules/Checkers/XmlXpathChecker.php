<?php

declare(strict_types=1);

namespace ACQC\Core\Rules\Checkers;

use ACQC\Core\Rules\RuleDefinition;

class XmlXpathChecker implements CheckerInterface
{
    public function check(RuleDefinition $rule, string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $dom  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $dom->loadXML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            return [new RuleViolation(
                ruleId:         $rule->id,
                severity:       'warning',
                filePath:       $filePath,
                lineNumber:     null,
                message:        "Malformed XML — could not parse file for rule {$rule->id}",
                recommendation: null,
            )];
        }

        $xpath      = new \DOMXPath($dom);
        $expression = $rule->check['expression'] ?? '';
        if ($expression === '') {
            return [];
        }

        $nodes = @$xpath->query($expression);
        if ($nodes === false) {
            return [];
        }

        $violations = [];
        foreach ($nodes as $node) {
            $attribute = $rule->check['attribute'] ?? null;
            if ($attribute !== null && $node instanceof \DOMElement) {
                $value = $node->getAttribute($attribute);
            } else {
                $value = trim($node->nodeValue ?? '');
            }

            $lineNumber = method_exists($node, 'getLineNo') ? $node->getLineNo() : null;

            $violations[] = new RuleViolation(
                ruleId:         $rule->id,
                severity:       $rule->severity,
                filePath:       $filePath,
                lineNumber:     $lineNumber ?: null,
                message:        $value !== ''
                                    ? "{$rule->title}: {$value}"
                                    : $rule->title,
                recommendation: $rule->recommendation,
            );
        }

        return $violations;
    }
}
