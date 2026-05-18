<?php

declare(strict_types=1);

namespace ACQC\Analyzers;

interface AnalyzerInterface
{
    /**
     * Analyze the given files and return results
     *
     * @param array $files List of file paths to analyze
     * @param array $config Configuration options
     * @return array Analysis results containing issues, metrics, and recommendations
     */
    public function analyze(array $files, array $config = []): array;

    /**
     * Get the name of this analyzer
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the description of what this analyzer does
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get the severity levels this analyzer can report
     *
     * @return array
     */
    public function getSupportedSeverities(): array;
} 