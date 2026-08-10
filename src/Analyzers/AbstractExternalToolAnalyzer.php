<?php

declare(strict_types=1);

namespace ACQC\Analyzers;

use Symfony\Component\Process\Process;

/**
 * Base for analyzers that shell out to an external CLI (ESLint, npm audit, tsc,
 * and future Pylint/Checkstyle). Mirrors the PHPCS/PHPMD pattern but stays
 * decoupled from the Magento-oriented AbstractAnalyzer.
 *
 * Contract for subclasses: describe the tool, build the command, and map its
 * output to issue arrays. The base guarantees graceful degradation — if the
 * tool is not installed the analyzer returns no issues (never fails the run)
 * and performs no network access unless the subclass's command does.
 */
abstract class AbstractExternalToolAnalyzer implements AnalyzerInterface
{
    /** Executable name to look for, e.g. 'eslint'. */
    abstract protected function toolBinary(): string;

    /**
     * Build the process to run, or null to skip (e.g. no config present).
     *
     * @param string   $binary     resolved executable
     * @param string   $projectDir project root
     * @param string[] $files      files selected for this analyzer
     */
    abstract protected function buildProcess(string $binary, string $projectDir, array $files): ?Process;

    /**
     * Map tool output to issue arrays (keys: rule_id, severity, file, line,
     * message, recommendation, category).
     *
     * @return array<int,array<string,mixed>>
     */
    abstract protected function parseOutput(string $stdout, string $stderr, ?int $exitCode): array;

    /** File extensions this analyzer applies to (empty = project-level). */
    abstract protected function supportedExtensions(): array;

    public function getSupportedSeverities(): array
    {
        return ['error', 'warning', 'info'];
    }

    public function isAvailable(): bool
    {
        return $this->resolveBinary(getcwd() ?: '.') !== null;
    }

    public function analyze(array $files, array $config = []): array
    {
        $empty = ['issues' => [], 'metrics' => [], 'recommendations' => []];

        $projectDir = isset($config['project_path'])
            ? rtrim((string) $config['project_path'], '/')
            : (getcwd() ?: '.');

        $selected = $this->filterFiles($files);
        if ($selected === [] && $this->supportedExtensions() !== []) {
            return $empty;
        }

        $binary = $this->resolveBinary($projectDir);
        if ($binary === null) {
            return $empty; // tool not installed — graceful skip
        }

        $process = $this->buildProcess($binary, $projectDir, $selected);
        if ($process === null) {
            return $empty;
        }

        try {
            $process->setTimeout(180.0);
            $process->run();
        } catch (\Throwable) {
            return $empty;
        }

        return [
            'issues'          => $this->parseOutput($process->getOutput(), $process->getErrorOutput(), $process->getExitCode()),
            'metrics'         => [],
            'recommendations' => [],
        ];
    }

    /** @param string[] $files @return string[] */
    protected function filterFiles(array $files): array
    {
        $exts = $this->supportedExtensions();
        if ($exts === []) {
            return $files;
        }

        return array_values(array_filter($files, function ($file) use ($exts): bool {
            $ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
            return in_array($ext, $exts, true);
        }));
    }

    /**
     * Resolve the tool binary: prefer the project's local node_modules/.bin, then
     * a binary on PATH. Never triggers an install.
     */
    protected function resolveBinary(string $projectDir): ?string
    {
        $name  = $this->toolBinary();
        $local = $projectDir . '/node_modules/.bin/' . $name;
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        $which = @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($name)));
        if (is_string($which) && trim($which) !== '') {
            return trim($which);
        }

        return null;
    }
}
