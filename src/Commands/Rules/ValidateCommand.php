<?php

declare(strict_types=1);

namespace ACQC\Commands\Rules;

use ACQC\Core\Rules\RuleValidationException;
use ACQC\Core\Rules\RuleValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('rules:validate')
            ->setDescription('Validate all rule YAML files in a directory')
            ->addArgument('path', InputArgument::OPTIONAL, 'Directory to scan', 'config/rules/')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text|json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawPath = (string) $input->getArgument('path');
        $path    = realpath($rawPath) ?: (getcwd() . '/' . ltrim($rawPath, '/'));
        $format  = strtolower((string) ($input->getOption('format') ?? 'text'));

        if (!is_dir($path)) {
            $output->writeln("<error>Directory not found: {$path}</error>");
            return Command::FAILURE;
        }

        $validator = new RuleValidator();
        $finder    = new Finder();
        $finder->files()->name(['*.yaml', '*.yml'])->in($path)->sortByName();

        $passed  = 0;
        $failed  = 0;
        $jsonOut = [];

        foreach ($finder as $file) {
            $rel    = ltrim(str_replace($path, '', $file->getRealPath()), '/');
            $errors = [];

            try {
                $data = Yaml::parseFile($file->getRealPath());
            } catch (ParseException $e) {
                $errors[] = 'YAML parse error: ' . $e->getMessage();
                $failed++;
                if ($format === 'json') {
                    $jsonOut[] = ['file' => $rel, 'status' => 'fail', 'errors' => $errors];
                } else {
                    $output->writeln("<fg=red>✗</> {$rel}");
                    $output->writeln('    ' . $errors[0]);
                }
                continue;
            }

            if (!is_array($data)) {
                $errors[] = 'File must contain a YAML mapping (associative array)';
                $failed++;
                if ($format === 'json') {
                    $jsonOut[] = ['file' => $rel, 'status' => 'fail', 'errors' => $errors];
                } else {
                    $output->writeln("<fg=red>✗</> {$rel}");
                    $output->writeln('    ' . $errors[0]);
                }
                continue;
            }

            try {
                $validator->validate($data, $file->getRealPath());
                $passed++;
                if ($format === 'json') {
                    $jsonOut[] = ['file' => $rel, 'status' => 'pass', 'errors' => []];
                } else {
                    $output->writeln("<fg=green>✓</> {$rel}");
                }
            } catch (RuleValidationException $e) {
                foreach (explode('; ', $e->getMessage()) as $line) {
                    $line = ltrim($line);
                    if ($line !== '') {
                        $errors[] = $line;
                    }
                }
                $failed++;
                if ($format === 'json') {
                    $jsonOut[] = ['file' => $rel, 'status' => 'fail', 'errors' => $errors];
                } else {
                    $output->writeln("<fg=red>✗</> {$rel}");
                    foreach ($errors as $line) {
                        $output->writeln("    {$line}");
                    }
                }
            }
        }

        if ($format === 'json') {
            $output->writeln(json_encode($jsonOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $total = $passed + $failed;
            $output->writeln('');
            $output->writeln("<info>{$total} rule(s) validated: {$passed} passed, {$failed} failed.</info>");
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
