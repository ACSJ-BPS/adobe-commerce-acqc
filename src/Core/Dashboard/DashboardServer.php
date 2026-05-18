<?php
declare(strict_types=1);
namespace ACQC\Core\Dashboard;

use ACQC\Core\History\SqliteHistoryStorage;

/**
 * Minimal HTTP server for the ACQC web dashboard.
 * Uses PHP's built-in web server. Not for production.
 */
class DashboardServer
{
    private string $projectRoot;
    private int    $port;
    private string $host;
    private SqliteHistoryStorage $history;

    public function __construct(string $projectRoot, int $port = 8765, string $host = '127.0.0.1')
    {
        $this->projectRoot = $projectRoot;
        $this->port        = $port;
        $this->host        = $host;
        $this->history     = new SqliteHistoryStorage();
    }

    public function getPort(): int   { return $this->port; }
    public function getHost(): string { return $this->host; }

    /**
     * Start the PHP built-in web server serving the dashboard API + static files.
     * Blocks until the server exits.
     */
    public function start(callable $onStart = null): void
    {
        $routerFile = $this->writeRouterFile();

        $cmd = sprintf(
            '%s -S %s:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            $this->host,
            $this->port,
            escapeshellarg(dirname($routerFile)),
            escapeshellarg($routerFile)
        );

        if ($onStart) {
            $onStart($this->host, $this->port);
        }

        // exec replaces current process — used when called via proc_open from DashboardCommand
        passthru($cmd);
    }

    /**
     * Write a self-contained PHP router that handles API routes and serves static files.
     */
    private function writeRouterFile(): string
    {
        $distDir     = str_replace('\\', '/', realpath(__DIR__ . '/../../../dist') ?: (__DIR__ . '/../../../dist'));
        $historyDb   = str_replace('\\', '/', ($_SERVER['HOME'] ?? sys_get_temp_dir()) . '/.acqc/history.sqlite');
        $projectRoot = str_replace('\\', '/', $this->projectRoot);

        $routerContent = <<<PHP
<?php
// ACQC Dashboard Router — auto-generated, do not edit
\$uri = \$_SERVER['REQUEST_URI'] ?? '/';
\$path = parse_url(\$uri, PHP_URL_PATH);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (\$_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// API routes
if (str_starts_with(\$path, '/api/')) {
    header('Content-Type: application/json');

    \$dbPath = '{$historyDb}';
    \$projectRoot = '{$projectRoot}';

    if (\$path === '/api/history') {
        \$limit = min((int)(\$_GET['limit'] ?? 50), 500);
        \$project = \$_GET['project'] ?? '';
        if (file_exists(\$dbPath)) {
            \$pdo = new PDO('sqlite:' . \$dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            \$sql = 'SELECT * FROM scan_history' . (\$project ? ' WHERE project_path = ?' : '') . ' ORDER BY created_at DESC LIMIT ' . \$limit;
            \$stmt = \$pdo->prepare(\$sql);
            \$stmt->execute(\$project ? [\$project] : []);
            \$rows = \$stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach (\$rows as &\$r) { \$r['metadata'] = json_decode(\$r['metadata'] ?? '{}', true); }
            echo json_encode(['data' => \$rows, 'total' => count(\$rows)]);
        } else {
            echo json_encode(['data' => [], 'total' => 0, 'note' => 'No history database found. Run a scan first.']);
        }
        exit;
    }

    if (\$path === '/api/history/trend') {
        if (file_exists(\$dbPath)) {
            \$pdo = new PDO('sqlite:' . \$dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            \$rows = \$pdo->query(
                "SELECT date(created_at) as date, AVG(score) as avg_score, SUM(total_violations) as violations, COUNT(*) as scans " .
                "FROM scan_history GROUP BY date(created_at) ORDER BY date DESC LIMIT 30"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['data' => array_reverse(\$rows)]);
        } else {
            echo json_encode(['data' => []]);
        }
        exit;
    }

    if (\$path === '/api/projects') {
        if (file_exists(\$dbPath)) {
            \$pdo = new PDO('sqlite:' . \$dbPath);
            \$rows = \$pdo->query(
                "SELECT project_path, project_name, MAX(created_at) as last_scan, AVG(score) as avg_score, COUNT(*) as scan_count " .
                "FROM scan_history GROUP BY project_path ORDER BY last_scan DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['data' => \$rows]);
        } else {
            echo json_encode(['data' => []]);
        }
        exit;
    }

    if (\$path === '/api/status') {
        echo json_encode([
            'status'      => 'ok',
            'version'     => '2.0.0',
            'project'     => \$projectRoot,
            'db_exists'   => file_exists(\$dbPath),
            'server_time' => date('c'),
        ]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'path' => \$path]);
    exit;
}

// Static files — serve the built React dashboard from dist/
\$distDir = '{$distDir}';
if (\$path === '/' || \$path === '') {
    \$index = \$distDir . '/index.html';
    if (file_exists(\$index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(\$index);
        exit;
    }
    // Fallback: embedded minimal dashboard
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html><html><head><meta charset="utf-8">
<title>ACQC Dashboard</title>
<style>body{font-family:system-ui;max-width:900px;margin:2rem auto;padding:1rem}h1{color:#e01e5a}table{width:100%;border-collapse:collapse}th,td{padding:.5rem 1rem;border:1px solid #ddd;text-align:left}tr:nth-child(even){background:#f9f9f9}.score-good{color:green}.score-warn{color:orange}.score-bad{color:red}</style>
</head><body>
<h1>ACQC Dashboard</h1>
<p>React dashboard not built yet. Run <code>npm run build</code> in the project root to build it.</p>
<h2>Recent Scans</h2>
<div id="app"><p>Loading...</p></div>
<script>
fetch('/api/history?limit=20').then(r=>r.json()).then(d=>{
  const rows=d.data||[];
  if(!rows.length){document.getElementById('app').innerHTML='<p>No scans recorded yet.</p>';return;}
  let t='<table><tr><th>Project</th><th>Score</th><th>Violations</th><th>Files</th><th>Date</th></tr>';
  rows.forEach(r=>{
    const cls=r.score>=80?'score-good':r.score>=60?'score-warn':'score-bad';
    t+='<tr><td>'+(r.project_name||r.project_path)+'</td><td class="'+cls+'">'+r.score+'</td><td>'+r.total_violations+'</td><td>'+r.files_analyzed+'</td><td>'+r.created_at+'</td></tr>';
  });
  t+='</table>';
  document.getElementById('app').innerHTML=t;
});
</script>
</body></html>
HTML;
    exit;
}

// Serve static assets
\$filePath = \$distDir . \$path;
if (file_exists(\$filePath) && is_file(\$filePath)) {
    return false; // Let PHP built-in server handle it
}

// SPA fallback — serve index.html for all non-API, non-asset routes
\$index = \$distDir . '/index.html';
if (file_exists(\$index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(\$index);
} else {
    http_response_code(404);
    echo 'Not found';
}
PHP;

        $tmpDir = sys_get_temp_dir() . '/acqc-dashboard';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $routerPath = $tmpDir . '/router.php';
        file_put_contents($routerPath, $routerContent);
        return $routerPath;
    }
}
