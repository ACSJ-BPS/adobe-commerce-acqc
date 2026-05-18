<?php
declare(strict_types=1);
namespace ACQC\Core\Git;

/**
 * Resolves changed PHP files from git diff for --diff mode.
 */
class GitDiffResolver
{
    public function getChangedPhpFiles(string $projectRoot, string $base = 'HEAD'): array
    {
        $projectRoot = rtrim($projectRoot, '/');

        // Validate this is a git repo
        $checkCmd = sprintf('git -C %s rev-parse --is-inside-work-tree 2>&1', escapeshellarg($projectRoot));
        exec($checkCmd, $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException("Not a git repository: {$projectRoot}");
        }

        // Get changed files
        if ($base === 'staged') {
            $cmd = sprintf('git -C %s diff --cached --name-only --diff-filter=ACMR 2>&1', escapeshellarg($projectRoot));
        } elseif ($base === 'working') {
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR 2>&1', escapeshellarg($projectRoot));
        } elseif (str_contains($base, '..')) {
            // Range like main..HEAD
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR %s 2>&1', escapeshellarg($projectRoot), escapeshellarg($base));
        } else {
            // vs a branch/commit e.g. main or HEAD
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR %s 2>&1', escapeshellarg($projectRoot), escapeshellarg($base));
        }

        exec($cmd, $lines, $exitCode);
        if ($exitCode !== 0) {
            // Fallback: unstaged changes
            $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMR 2>&1', escapeshellarg($projectRoot));
            exec($cmd, $lines, $exitCode);
        }

        $phpFiles = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!str_ends_with(strtolower($line), '.php')) continue;
            $full = $projectRoot . '/' . $line;
            if (is_file($full)) {
                $phpFiles[] = $full;
            }
        }

        return $phpFiles;
    }

    public function getCurrentBranch(string $projectRoot): string
    {
        $cmd = sprintf('git -C %s rev-parse --abbrev-ref HEAD 2>&1', escapeshellarg($projectRoot));
        exec($cmd, $lines, $code);
        return $code === 0 && !empty($lines) ? trim($lines[0]) : 'unknown';
    }

    public function getDefaultBase(string $projectRoot): string
    {
        // Try to find main/master
        foreach (['main', 'master', 'develop'] as $branch) {
            $cmd = sprintf('git -C %s rev-parse --verify %s 2>/dev/null', escapeshellarg($projectRoot), escapeshellarg($branch));
            exec($cmd, $out, $code);
            if ($code === 0) return $branch;
        }
        return 'HEAD';
    }
}
