<?php
declare(strict_types=1);
namespace ACQC\Core\Policy;

/**
 * Evaluates PolicyRule conditions against PHP files.
 *
 * Supported rule types:
 *   pattern     — regex match against file content
 *   file_exists — assert that a file path exists (relative to project root)
 *   file_missing — assert that a file path does NOT exist
 *   metric      — check a numeric value (lines_of_code, class_count, etc.)
 */
class PolicyEvaluator
{
    public function evaluate(PolicyRule $rule, string $filePath, string $projectRoot = ''): array
    {
        if (!$rule->enabled) return [];

        return match($rule->type) {
            'pattern'      => $this->evaluatePattern($rule, $filePath),
            'file_exists'  => $this->evaluateFileExists($rule, $projectRoot, true),
            'file_missing' => $this->evaluateFileExists($rule, $projectRoot, false),
            'metric'       => $this->evaluateMetric($rule, $filePath),
            default        => [],
        };
    }

    private function evaluatePattern(PolicyRule $rule, string $filePath): array
    {
        if (!is_file($filePath)) return [];

        $conditions = $rule->conditions;
        $pattern    = $conditions['pattern'] ?? $conditions['regex'] ?? null;
        if (!$pattern) return [];

        $content = file_get_contents($filePath);
        if ($content === false) return [];

        // Optional: restrict to certain file globs
        $fileGlobs = $conditions['files'] ?? ['*.php'];
        $matched = false;
        $baseFile = basename($filePath);
        foreach ($fileGlobs as $glob) {
            if (fnmatch($glob, $baseFile) || fnmatch($glob, str_replace('\\', '/', $filePath))) {
                $matched = true;
                break;
            }
        }
        if (!$matched) return [];

        $negate = (bool)($conditions['negate'] ?? false); // negate: true means "must NOT match"
        $flags  = isset($conditions['case_insensitive']) && $conditions['case_insensitive'] ? 'i' : '';
        $regex  = '/' . str_replace('/', '\\/', $pattern) . '/' . $flags;

        $violations = [];
        $lines = explode("\n", $content);
        foreach ($lines as $lineNo => $line) {
            $matches = (bool)@preg_match($regex, $line);
            if ($matches && !$negate) {
                $violations[] = $this->buildViolation($rule, $filePath, $lineNo + 1, $line);
            }
        }

        // If negate and no matches across whole file, it's also a violation (must-exist check)
        if ($negate && preg_match($regex, $content)) {
            $violations[] = $this->buildViolation($rule, $filePath, 1, '');
        }

        return $violations;
    }

    private function evaluateFileExists(PolicyRule $rule, string $projectRoot, bool $mustExist): array
    {
        $conditions = $rule->conditions;
        $paths = $conditions['paths'] ?? ($conditions['path'] ? [$conditions['path']] : []);
        $violations = [];

        foreach ($paths as $relPath) {
            $full = rtrim($projectRoot, '/') . '/' . ltrim($relPath, '/');
            $exists = file_exists($full);
            if ($mustExist && !$exists) {
                $violations[] = $this->buildViolation($rule, $full, 0, "Expected file missing: {$relPath}");
            } elseif (!$mustExist && $exists) {
                $violations[] = $this->buildViolation($rule, $full, 0, "Forbidden file found: {$relPath}");
            }
        }

        return $violations;
    }

    private function evaluateMetric(PolicyRule $rule, string $filePath): array
    {
        if (!is_file($filePath)) return [];

        $conditions = $rule->conditions;
        $metric     = $conditions['metric'] ?? 'lines_of_code';
        $operator   = $conditions['operator'] ?? 'gt';   // gt, gte, lt, lte, eq
        $threshold  = (int)($conditions['threshold'] ?? 0);

        $value = match($metric) {
            'lines_of_code' => substr_count(file_get_contents($filePath) ?: '', "\n") + 1,
            'class_count'   => preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+\w/m', file_get_contents($filePath) ?: ''),
            'method_count'  => preg_match_all('/^\s*(?:public|protected|private)\s+(?:static\s+)?function\s+\w/m', file_get_contents($filePath) ?: ''),
            default         => 0,
        };

        $violated = match($operator) {
            'gt'  => $value > $threshold,
            'gte' => $value >= $threshold,
            'lt'  => $value < $threshold,
            'lte' => $value <= $threshold,
            'eq'  => $value === $threshold,
            default => false,
        };

        if (!$violated) return [];

        return [$this->buildViolation($rule, $filePath, 1, "Metric '{$metric}' is {$value} (threshold: {$operator} {$threshold})")];
    }

    private function buildViolation(PolicyRule $rule, string $file, int $line, string $context): array
    {
        return [
            'rule_id'  => $rule->id,
            'rule'     => $rule->id,
            'message'  => $this->interpolateMessage($rule->message, ['file' => $file, 'line' => $line, 'context' => $context]),
            'file'     => $file,
            'file_path'=> $file,
            'line'     => $line,
            'severity' => $rule->severity,
            'category' => $rule->category,
            'type'     => 'policy',
            'fix'      => $rule->fix,
            'tags'     => $rule->tags,
        ];
    }

    private function interpolateMessage(string $template, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $template = str_replace('{' . $k . '}', (string)$v, $template);
        }
        return $template;
    }
}
