<?php

declare(strict_types=1);

namespace ACQC\Tests\Docs;

use ACQC\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the user documentation in step with the CLI: every `acqc <command> --option`
 * written in a code block or code span, and every option table, must exist in the
 * real command definitions, and every relative link and anchor must resolve.
 */
class DocsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const CLI_DOCS = [
        'README.md',
        'CONTRIBUTING.md',
        'SECURITY.md',
        'docs/*.md',
        'abqc/README.md',
        'abqc/rules/README.md',
        'server/README.md',
    ];

    private const LINK_DOCS = [...self::CLI_DOCS, 'CHANGELOG.md'];

    private static ?Application $application = null;

    public function testDocumentedCommandsAndOptionsExist(): void
    {
        $errors = [];
        $checked = 0;

        foreach ($this->files(self::CLI_DOCS) as $file) {
            foreach ($this->invocations($file) as [$line, $command, $tokens]) {
                $checked++;
                foreach ($this->unknownOptions($command, $tokens) as $error) {
                    $errors[] = sprintf('%s:%d %s', $this->relative($file), $line, $error);
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'Expected to find acqc examples in the docs');
        $this->assertSame([], $errors, "Docs use commands or options that do not exist:\n" . implode("\n", $errors));
    }

    public function testOptionTablesMatchTheirCommand(): void
    {
        $errors = [];
        $tables = 0;

        foreach ($this->files(self::CLI_DOCS) as $file) {
            $lastCommand = null;
            $inTable = false;
            $inFence = false;

            foreach ($this->lines($file) as $number => $text) {
                if (preg_match('/^\s*```/', $text)) {
                    $inFence = !$inFence;
                    continue;
                }
                // A table documents the command of the nearest code block above it.
                if ($inFence) {
                    foreach ($this->invocationsInLine($text) as [$command]) {
                        if ($command !== '' && $this->application()->has($command)) {
                            $lastCommand = $command;
                        }
                    }
                    continue;
                }
                if (preg_match('/^\|\s*Option\s*\|/', $text)) {
                    $inTable = true;
                    $tables++;
                    if ($lastCommand === null) {
                        $errors[] = sprintf('%s:%d option table without a preceding acqc example', $this->relative($file), $number);
                    }
                    continue;
                }
                if (!$inTable || !str_starts_with($text, '|')) {
                    $inTable = false;
                    continue;
                }
                if ($lastCommand === null) {
                    continue;
                }
                $firstCell = explode('|', $text)[1] ?? '';
                foreach ($this->unknownOptions($lastCommand, $this->optionTokens($firstCell)) as $error) {
                    $errors[] = sprintf('%s:%d %s', $this->relative($file), $number, $error);
                }
            }
        }

        $this->assertGreaterThan(0, $tables, 'Expected option tables in the docs');
        $this->assertSame([], $errors, "Option tables list options that do not exist:\n" . implode("\n", $errors));
    }

    public function testRelativeLinksAndAnchorsResolve(): void
    {
        $errors = [];

        foreach ($this->files(self::LINK_DOCS) as $file) {
            $content = $this->withoutCode((string) file_get_contents($file));
            preg_match_all('/\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $content, $matches);

            foreach ($matches[1] as $target) {
                $target = trim($target, '<>');
                if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $target)) {
                    continue;
                }
                [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
                $resolved = $path === '' ? $file : dirname($file) . '/' . rawurldecode($path);

                if (!file_exists($resolved)) {
                    $errors[] = sprintf('%s: missing link target %s', $this->relative($file), $target);
                    continue;
                }
                if ($anchor !== null && $anchor !== '' && is_file($resolved) && str_ends_with($resolved, '.md')
                    && !in_array($anchor, $this->anchors($resolved), true)) {
                    $errors[] = sprintf('%s: missing anchor %s', $this->relative($file), $target);
                }
            }
        }

        $this->assertSame([], $errors, "Broken links in the docs:\n" . implode("\n", $errors));
    }

    /**
     * @return list<array{int, string, list<string>}> line number, command name, option tokens
     */
    private function invocations(string $file): array
    {
        $found = [];
        $inFence = false;
        $pending = '';
        $pendingLine = 0;

        foreach ($this->lines($file) as $number => $text) {
            if (preg_match('/^\s*```/', $text)) {
                $inFence = !$inFence;
                $pending = '';
                continue;
            }
            if ($inFence) {
                if ($pending === '') {
                    $pendingLine = $number;
                }
                if (str_ends_with(rtrim($text), '\\')) {
                    $pending .= substr(rtrim($text), 0, -1) . ' ';
                    continue;
                }
                $units = [$pending . $text];
                $line = $pendingLine;
                $pending = '';
            } else {
                // Inline code counts only when it starts with the command, so quoted
                // messages such as `ACQC WARNING: acqc not found` are not examples.
                preg_match_all('/`([^`]+)`/', $text, $spans);
                $units = array_values(array_filter(
                    $spans[1],
                    static fn (string $span): bool => (bool) preg_match('#^(?:php\s+)?(?:\S*/)?acqc\s#', ltrim($span))
                ));
                $line = $number;
            }

            foreach ($units as $unit) {
                foreach ($this->invocationsInLine($unit) as [$command, $tokens]) {
                    $found[] = [$line, $command, $tokens];
                }
            }
        }

        return $found;
    }

    /**
     * @return list<array{string, list<string>}> command name ('' for global options only), option tokens
     */
    private function invocationsInLine(string $text): array
    {
        $result = [];
        preg_match_all('/(?<![\w.$-])acqc[ \t]+(\S+)([^\n]*)/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $first, $rest]) {
            $first = trim($first, '`');
            if ($first === '' || str_starts_with($first, '<') || str_starts_with($first, '[')) {
                continue;
            }
            // Stop at a shell comment, pipe, redirect or command separator.
            $rest = preg_split('/\s(?:#|\|(?!\|)|>|2>|&&|;)/', ' ' . $rest)[0];
            $rest = (string) preg_replace('/(?<!\\\\)`.*$/', '', $rest);
            if (str_starts_with($first, '-')) {
                $result[] = ['', $this->optionTokens($first . ' ' . $rest)];
            } elseif (preg_match('/^[a-z][a-z0-9:-]*$/', $first)) {
                $result[] = [$first, $this->optionTokens($rest)];
            }
        }

        return $result;
    }

    /**
     * @return list<string> tokens like "--format" or "-f"
     */
    private function optionTokens(string $text): array
    {
        preg_match_all('/(?<![\w-])(--[a-zA-Z][\w-]*|-[a-zA-Z](?![\w-]))/', $text, $matches);

        return $matches[1];
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function unknownOptions(string $command, array $tokens): array
    {
        $app = $this->application();
        if ($command !== '' && !$app->has($command)) {
            return [sprintf('unknown command "acqc %s"', $command)];
        }

        $definition = $app->getDefinition();
        if ($command !== '') {
            $instance = $app->get($command);
            $instance->mergeApplicationDefinition();
            $definition = $instance->getDefinition();
        }

        $errors = [];
        foreach ($tokens as $token) {
            $known = str_starts_with($token, '--')
                ? $definition->hasOption(substr($token, 2)) || $definition->hasNegation(substr($token, 2))
                : $definition->hasShortcut(substr($token, 1));
            if (!$known) {
                $errors[] = sprintf('unknown option %s for "acqc %s"', $token, $command);
            }
        }

        return $errors;
    }

    /**
     * GitHub-style heading anchors, including the -1, -2 suffixes for repeated headings.
     *
     * @return list<string>
     */
    private function anchors(string $file): array
    {
        static $cache = [];
        if (isset($cache[$file])) {
            return $cache[$file];
        }

        $anchors = [];
        $seen = [];
        foreach (explode("\n", $this->withoutCode((string) file_get_contents($file))) as $text) {
            if (!preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $text, $heading)) {
                continue;
            }
            $slug = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading[1]);
            $slug = mb_strtolower(strip_tags($slug));
            $slug = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug);
            $slug = str_replace(' ', '-', $slug);
            $count = $seen[$slug] ?? 0;
            $anchors[] = $count === 0 ? $slug : $slug . '-' . $count;
            $seen[$slug] = $count + 1;
        }

        return $cache[$file] = $anchors;
    }

    private function withoutCode(string $content): string
    {
        return (string) preg_replace('/^\s*```.*?^\s*```/ms', '', $content);
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    private function files(array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            $matches = glob(self::ROOT . '/' . $pattern) ?: [];
            $this->assertNotEmpty($matches, 'No docs found for ' . $pattern);
            array_push($files, ...$matches);
        }

        return $files;
    }

    /**
     * @return array<int, string> 1-based line number => text
     */
    private function lines(string $file): array
    {
        $lines = explode("\n", (string) file_get_contents($file));

        return array_combine(range(1, count($lines)), $lines);
    }

    private function relative(string $file): string
    {
        return ltrim(substr($file, strlen(self::ROOT)), '/');
    }

    private function application(): Application
    {
        return self::$application ??= new Application();
    }
}
