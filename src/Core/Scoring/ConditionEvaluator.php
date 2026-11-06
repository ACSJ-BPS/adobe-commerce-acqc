<?php

declare(strict_types=1);

namespace ACQC\Core\Scoring;

use InvalidArgumentException;

/**
 * Safe, deterministic condition evaluator for scoring caps, readiness rules, KPI bands, and quality gates.
 * Supports expressions of the form "<op> <number>" where <op> is one of >, >=, <, <=, ==, !=.
 */
final class ConditionEvaluator
{
    private const VALID_OPS = ['>', '>=', '<', '<=', '==', '!='];

    /**
     * Parse and validate a condition expression such as "> 0" or "<= 65.5".
     *
     * @return array{op: string, threshold: float}
     * @throws InvalidArgumentException when the expression is malformed
     */
    public function parseExpression(string $expression, string $contextPath = 'condition'): array
    {
        $trimmed = trim($expression);
        if (!preg_match('/^(>=|<=|==|!=|>|<)\s*(-?\d+(?:\.\d+)?)$/', $trimmed, $matches)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid scoring condition "%s" at %s: expected "<op> <number>" with op in [%s].',
                $expression,
                $contextPath,
                implode(', ', self::VALID_OPS)
            ));
        }

        return [
            'op' => $matches[1],
            'threshold' => (float) $matches[2],
        ];
    }

    /**
     * Evaluate a single value against a condition expression ("<op> <number>").
     */
    public function evaluateValue(float|int $actual, string $expression, string $contextPath = 'condition'): bool
    {
        $parsed = $this->parseExpression($expression, $contextPath);
        $lhs = (float) $actual;
        $rhs = $parsed['threshold'];

        return match ($parsed['op']) {
            '>'  => $lhs > $rhs,
            '>=' => $lhs >= $rhs,
            '<'  => $lhs < $rhs,
            '<=' => $lhs <= $rhs,
            '==' => abs($lhs - $rhs) < 1e-9,
            '!=' => abs($lhs - $rhs) >= 1e-9,
            default => throw new InvalidArgumentException("Unsupported operator {$parsed['op']} at {$contextPath}"),
        };
    }

    /**
     * Evaluate a map of {inputKey => "<op> <number>"} against known inputs.
     *
     * @param array<string, string> $conditions
     * @param array<string, float|int> $inputs
     * @param 'any'|'all' $mode
     * @return array{matched: bool, reasons: list<string>}
     */
    public function evaluateMap(
        array $conditions,
        array $inputs,
        string $mode = 'any',
        string $contextPath = 'conditions'
    ): array {
        if ($conditions === []) {
            return ['matched' => true, 'reasons' => []];
        }

        $reasons = [];
        $allMatched = true;
        $anyMatched = false;

        foreach ($conditions as $key => $expr) {
            if (!is_string($key) || $key === '' || !array_key_exists($key, $inputs)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown scoring condition input key "%s" at %s. Known keys: %s',
                    (string) $key,
                    $contextPath,
                    implode(', ', array_keys($inputs))
                ));
            }
            if (!is_string($expr)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid condition expression for "%s" at %s: expected string "<op> <number>".',
                    $key,
                    $contextPath
                ));
            }

            $actual = $inputs[$key];
            $matched = $this->evaluateValue($actual, $expr, "{$contextPath}.{$key}");
            if ($matched) {
                $anyMatched = true;
                $formattedActual = is_int($actual) || floor((float) $actual) === (float) $actual
                    ? (string) (int) round((float) $actual)
                    : number_format((float) $actual, 1, '.', '');
                $reasons[] = sprintf('%s = %s (%s)', $key, $formattedActual, trim($expr));
            } else {
                $allMatched = false;
            }
        }

        $result = $mode === 'all' ? $allMatched : $anyMatched;

        return [
            'matched' => $result,
            'reasons' => $result ? $reasons : [],
        ];
    }
}
