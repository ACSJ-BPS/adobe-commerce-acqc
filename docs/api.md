# API Documentation

Complete API reference for M2-Quality programmatic usage and integrations.

## 🔌 PHP API

### Core Classes

#### CodeAnalyzer

```php
use M2Quality\Core\CodeAnalyzer;
use M2Quality\Core\Scanner\ScanContext;

$analyzer = new CodeAnalyzer();

// Basic analysis
$result = $analyzer->analyze('/path/to/code');

// With options
$context = new ScanContext('/path/to/code', [
    'analyzers' => ['phpcs', 'security'],
    'severity' => 'warning'
]);
$result = $analyzer->analyze($context);

// Get violations
$violations = $result->getViolations();
foreach ($violations as $violation) {
    echo $violation->getFile() . ':' . $violation->getLine() . ' - ' . $violation->getMessage();
}
```

#### ScannerEngine

```php
use M2Quality\Core\Scanner\OptimizedScannerEngine;

$scanner = new OptimizedScannerEngine($ruleEngine, $analyzerRegistry);

// Configure optimizations
$scanner->enableIncrementalMode(true);

// Scan with performance optimization
$result = $scanner->scan('/path/to/code', [
    'enable_caching' => true,
    'enable_parallel' => true,
    'max_parallel_processes' => 8
]);

// Get performance metrics
$performance = $scanner->getPerformanceReport();
```

#### AI Integration

```php
use M2Quality\Core\AI\AIEngine;
use M2Quality\Core\AI\ConversationalInterface;

$aiEngine = new AIEngine();
$aiEngine->configure([
    'provider' => 'openai',
    'api_key' => 'your-api-key'
]);

$conversation = new ConversationalInterface($aiEngine);

// Ask questions
$response = $conversation->processQuery(
    "How can I improve this code?",
    ['file' => 'path/to/file.php']
);

echo $response['content'];
```

### Report Generation

```php
use M2Quality\Reports\ReportGenerator;

$reportGenerator = new ReportGenerator();

// Generate HTML report
$htmlReport = $reportGenerator->generate($violations, 'html');

// Generate PDF report
$pdfReport = $reportGenerator->generate($violations, 'pdf', [
    'template' => 'corporate',
    'include_charts' => true
]);

// Save report
file_put_contents('quality-report.html', $htmlReport);
```

## 🌐 REST API

### Authentication

```bash
# API Key authentication
curl -H "Authorization: Bearer YOUR_API_KEY" \
     -H "Content-Type: application/json" \
     http://localhost:8080/api/v1/scan
```

### Endpoints

#### POST /api/v1/scan

Trigger code analysis:

```bash
curl -X POST http://localhost:8080/api/v1/scan \
  -H "Content-Type: application/json" \
  -d '{
    "path": "/path/to/code",
    "analyzers": ["phpcs", "security"],
    "options": {
      "severity": "warning",
      "format": "json"
    }
  }'
```

Response:
```json
{
  "scan_id": "scan_123456",
  "status": "completed",
  "results": {
    "violations": [...],
    "metrics": {...},
    "summary": {...}
  }
}
```

#### GET /api/v1/scan/{id}

Get scan results:

```bash
curl http://localhost:8080/api/v1/scan/scan_123456
```

#### POST /api/v1/ai/ask

AI queries:

```bash
curl -X POST http://localhost:8080/api/v1/ai/ask \
  -H "Content-Type: application/json" \
  -d '{
    "question": "How can I improve my code quality?",
    "context": {
      "role": "developer",
      "include_code": true
    }
  }'
```

### WebSocket API

Real-time updates:

```javascript
const ws = new WebSocket('ws://localhost:8080/ws');

ws.onmessage = (event) => {
  const data = JSON.parse(event.data);
  if (data.type === 'scan_progress') {
    console.log(`Progress: ${data.progress}%`);
  }
};

// Subscribe to scan updates
ws.send(JSON.stringify({
  type: 'subscribe',
  channel: 'scan_updates'
}));
```

## 🔧 CLI Integration

### Programmatic CLI Usage

```php
use Symfony\Component\Process\Process;

// Run scan programmatically
$process = new Process([
    'php', 'bin/acqc', 'scan', '/path/to/code',
    '--format=json',
    '--output=/tmp/results.json'
]);

$process->run();

if ($process->isSuccessful()) {
    $results = json_decode(file_get_contents('/tmp/results.json'), true);
    // Process results
}
```

### Exit Codes

- `0` - Success, no issues
- `1` - Issues found (warnings/errors)
- `2` - Critical issues found
- `3` - Configuration error
- `4` - Runtime error

## 📊 JavaScript/Node.js Integration

### npm Package Usage

```bash
npm install @m2quality/client
```

```javascript
const M2Quality = require('@m2quality/client');

const client = new M2Quality({
  apiUrl: 'http://localhost:8080',
  apiKey: 'your-api-key'
});

// Trigger scan
const scan = await client.scan({
  path: '/path/to/code',
  analyzers: ['phpcs', 'security']
});

console.log('Scan ID:', scan.id);

// Poll for results
const results = await client.waitForResults(scan.id);
console.log('Quality Score:', results.summary.quality_score);
```

### React Dashboard Integration

```jsx
import { QualityDashboard } from '@m2quality/react-components';

function App() {
  return (
    <QualityDashboard
      apiUrl="http://localhost:8080"
      refreshInterval={30000}
      showNotifications={true}
    />
  );
}
```

## 🐳 Docker API

### Container Integration

```bash
# Run as service
docker run -d \
  --name m2quality-service \
  -p 8080:8080 \
  -v /path/to/code:/app \
  m2quality/m2-quality:latest \
  serve --host=0.0.0.0 --port=8080

# API call to container
curl http://localhost:8080/api/v1/scan \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"path": "/app"}'
```

## 🔄 CI/CD Integration

### GitHub Actions

```yaml
- name: Code Quality Analysis
  uses: m2quality/github-action@v1
  with:
    path: './app/code'
    analyzers: 'phpcs,security,magento'
    format: 'github-annotations'
    fail-on: 'error'
  env:
    OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
```

### Jenkins Pipeline

```groovy
pipeline {
  agent any
  stages {
    stage('Quality Analysis') {
      steps {
        sh '''
          m2-quality scan . \
            --format=junit \
            --output=reports/quality.xml \
            --baseline=baseline.json
        '''
        publishTestResults testResultsPattern: 'reports/quality.xml'
      }
    }
  }
}
```

### GitLab CI

```yaml
quality_analysis:
  stage: test
  script:
    - m2-quality scan . --format=gitlab-ci --exit-code
  artifacts:
    reports:
      junit: reports/quality.xml
      codequality: reports/codequality.json
```

## 📈 Webhook Integration

### Webhook Configuration

```json
{
  "webhooks": {
    "scan_completed": "https://your-app.com/webhooks/quality",
    "critical_issues": "https://your-app.com/webhooks/critical",
    "quality_improved": "https://your-app.com/webhooks/improved"
  }
}
```

### Webhook Payload

```json
{
  "event": "scan_completed",
  "timestamp": "2023-12-15T10:30:00Z",
  "scan_id": "scan_123456",
  "project": "magento-project",
  "results": {
    "quality_score": 8.5,
    "violations_count": 23,
    "critical_count": 0,
    "error_count": 5,
    "warning_count": 18
  },
  "changes": {
    "quality_score_change": +0.3,
    "new_violations": 2,
    "resolved_violations": 5
  }
}
```

## 🔌 Database Integration

### Results Storage

```php
use M2Quality\Storage\DatabaseStorage;

$storage = new DatabaseStorage([
    'host' => 'localhost',
    'database' => 'm2quality',
    'username' => 'user',
    'password' => 'pass'
]);

// Store scan results
$storage->storeScanResult($scanId, $results);

// Query historical data
$trends = $storage->getQualityTrends($projectId, $timeframe);
```

### Custom Storage Backends

```php
class CustomStorage implements StorageInterface
{
    public function storeScanResult(string $scanId, array $results): void
    {
        // Custom storage logic
    }

    public function retrieveScanResult(string $scanId): ?array
    {
        // Custom retrieval logic
    }
}
```

## 🎨 Custom Extensions

### Custom Analyzer

```php
namespace App\Analyzers;

use M2Quality\Analyzers\AbstractAnalyzer;
use M2Quality\Core\Scanner\ScanContext;
use M2Quality\Core\Scanner\ScanResult;

class CustomAnalyzer extends AbstractAnalyzer
{
    public function getName(): string
    {
        return 'custom';
    }

    public function analyze(ScanContext $context): ScanResult
    {
        $violations = [];
        $metrics = [];

        // Custom analysis logic
        $files = $context->getFiles();
        foreach ($files as $file) {
            // Analyze file
            $fileViolations = $this->analyzeFile($file);
            $violations = array_merge($violations, $fileViolations);
        }

        return new ScanResult($violations, true, $metrics);
    }
}
```

### Custom Report Generator

```php
namespace App\Reports;

use M2Quality\Reports\Generators\AbstractReportGenerator;

class CustomReportGenerator extends AbstractReportGenerator
{
    public function generate(array $violations, array $options = []): string
    {
        // Custom report generation logic
        return $this->renderTemplate('custom-template', [
            'violations' => $violations,
            'options' => $options
        ]);
    }
}
```

## 🔒 Security Considerations

### API Security

```php
// Rate limiting
$rateLimit = new RateLimit([
    'requests_per_minute' => 60,
    'requests_per_hour' => 1000
]);

// Input validation
$validator = new InputValidator([
    'path' => 'required|string|max:255',
    'analyzers' => 'array|in:phpcs,phpmd,security'
]);

// Authentication
$auth = new ApiAuthentication([
    'require_api_key' => true,
    'jwt_secret' => 'your-secret-key'
]);
```

### Data Protection

```php
// Sanitize file paths
$safePath = $pathSanitizer->sanitize($inputPath);

// Encrypt sensitive data
$encryptedResults = $encryptor->encrypt(json_encode($results));

// Audit logging
$auditLogger->log('scan_executed', [
    'user_id' => $userId,
    'path' => $safePath,
    'timestamp' => time()
]);
```

## 📚 SDK Examples

### Python SDK

```python
from m2quality import M2Quality

client = M2Quality(
    api_url='http://localhost:8080',
    api_key='your-api-key'
)

# Trigger scan
scan = client.scan(
    path='/path/to/code',
    analyzers=['phpcs', 'security']
)

# Wait for results
results = client.wait_for_results(scan.id)
print(f"Quality Score: {results.summary.quality_score}")
```

### Go SDK

```go
package main

import (
    "github.com/m2quality/go-client"
)

func main() {
    client := m2quality.NewClient("http://localhost:8080", "your-api-key")
    
    scan, err := client.Scan(m2quality.ScanRequest{
        Path: "/path/to/code",
        Analyzers: []string{"phpcs", "security"},
    })
    
    if err != nil {
        log.Fatal(err)
    }
    
    results, err := client.WaitForResults(scan.ID)
    if err != nil {
        log.Fatal(err)
    }
    
    fmt.Printf("Quality Score: %.1f\n", results.Summary.QualityScore)
}
```

---

**Next**: See [Extending M2-Quality](extending.md) for custom development. 