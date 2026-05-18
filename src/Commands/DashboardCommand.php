<?php
declare(strict_types=1);
namespace ACQC\Commands;

use ACQC\Core\Dashboard\DashboardServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dashboard',
    description: 'Start the ACQC web dashboard server'
)]
class DashboardCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::OPTIONAL,
                'Project path to monitor', '.')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL,
                'Port to listen on', '8765')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL,
                'Host to bind to', '127.0.0.1')
            ->addOption('no-open', null, InputOption::VALUE_NONE,
                'Do not open browser automatically')
            ->setHelp(<<<'HELP'
Starts a local web server serving the ACQC quality dashboard.

The dashboard displays:
  - Historical scan results and trends
  - Violation distribution charts
  - Module-level quality scores
  - Team performance metrics

<comment>Usage:</comment>
  <info>acqc dashboard</info>
  <info>acqc dashboard /path/to/project --port=9000</info>
  <info>acqc dashboard --no-open</info>

The dashboard reads from ~/.acqc/history.sqlite.
Run scans with <info>--save-history</info> or use the <info>history</info> command to populate it.

<comment>Build the React dashboard:</comment>
  <info>npm run build</info>   — builds to dist/ directory
  <info>npm run dev</info>     — development mode with hot reload
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $projectPath = realpath($input->getArgument('project')) ?: $input->getArgument('project');
        $port        = (int)($input->getOption('port') ?? 8765);
        $host        = $input->getOption('host') ?? '127.0.0.1';
        $noOpen      = (bool)$input->getOption('no-open');

        // Check PHP built-in server is available
        if (PHP_VERSION_ID < 70400) {
            $io->error('PHP 7.4+ required for built-in web server.');
            return Command::FAILURE;
        }

        $io->title('ACQC Web Dashboard');

        $server = new DashboardServer($projectPath, $port, $host);

        $url = "http://{$host}:{$port}";
        $io->writeln([
            "<info>Dashboard URL:</info> <href={$url}>{$url}</>",
            "<info>Project:</info>       {$projectPath}",
            "<info>History DB:</info>    " . ($_SERVER['HOME'] ?? '~') . "/.acqc/history.sqlite",
            '',
            '<comment>Press Ctrl+C to stop the server</comment>',
        ]);

        // Open browser
        if (!$noOpen) {
            $this->openBrowser($url);
        }

        $io->newLine();

        $server->start(function (string $h, int $p) use ($io) {
            $io->writeln(sprintf('<fg=gray>Server started on %s:%d</>', $h, $p));
        });

        return Command::SUCCESS;
    }

    private function openBrowser(string $url): void
    {
        $os = PHP_OS_FAMILY;
        $cmd = match($os) {
            'Darwin'  => 'open ' . escapeshellarg($url) . ' 2>/dev/null &',
            'Windows' => 'start "" ' . escapeshellarg($url),
            default   => 'xdg-open ' . escapeshellarg($url) . ' 2>/dev/null &',
        };
        @exec($cmd);
    }
}
