<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Reports\Generators;

use ACQC\Reports\Generators\SARIFReportGenerator;
use PHPUnit\Framework\TestCase;

final class SARIFReportGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/acqc-sarif-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/code/V/M/Model', 0777, true);
        file_put_contents($this->root . '/app/code/V/M/Model/A.php', "<?php\nclass A {}\n\$om = ObjectManager::getInstance();\n");
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function sarif(array $data, array $options = []): array
    {
        $result = (new SARIFReportGenerator())->generate($data, $options + ['project_path' => $this->root]);
        self::assertTrue($result->isSuccess(), (string) $result->getError());
        $json = json_decode($result->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertRequiredSarifShape($json);
        return $json;
    }

    private function issue(array $overrides = []): array
    {
        return $overrides + [
            'file'     => $this->root . '/app/code/V/M/Model/A.php',
            'line'     => 3,
            'severity' => 'error',
            'type'     => 'object_manager_usage',
            'message'  => 'Direct ObjectManager usage is discouraged.',
        ];
    }

    /** Minimal SARIF 2.1.0 required-property check (offline substitute for full schema validation). */
    private function assertRequiredSarifShape(array $j): void
    {
        self::assertSame('2.1.0', $j['version']);
        self::assertIsString($j['$schema']);
        self::assertIsList($j['runs']);
        foreach ($j['runs'] as $run) {
            self::assertIsString($run['tool']['driver']['name']);
            $ids = array_column($run['tool']['driver']['rules'] ?? [], 'id');
            self::assertSame(count($ids), count(array_unique($ids)), 'rule ids must be unique');
            foreach ($run['results'] as $r) {
                self::assertNotSame('', $r['message']['text']);
                self::assertContains($r['level'], ['none', 'note', 'warning', 'error']);
                self::assertContains($r['ruleId'], $ids, 'every result must reference a declared rule');
                self::assertSame($r['ruleId'], $run['tool']['driver']['rules'][$r['ruleIndex']]['id']);
                self::assertArrayNotHasKey('fixes', $r, 'fixes without artifactChanges are invalid');
                $loc = $r['locations'][0]['physicalLocation'];
                self::assertGreaterThanOrEqual(1, $loc['region']['startLine']);
                self::assertStringStartsNotWith('/', $loc['artifactLocation']['uri']);
            }
        }
    }

    public function testFlatIssuesProduceRelativeLocatedResults(): void
    {
        $r = $this->sarif(['issues' => [$this->issue()]])['runs'][0]['results'];

        self::assertCount(1, $r);
        self::assertSame('object_manager_usage', $r[0]['ruleId']);
        self::assertSame('error', $r[0]['level']);
        self::assertSame('app/code/V/M/Model/A.php', $r[0]['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertSame('SRCROOT', $r[0]['locations'][0]['physicalLocation']['artifactLocation']['uriBaseId']);
        self::assertSame(3, $r[0]['locations'][0]['physicalLocation']['region']['startLine']);
    }

    public function testNestedReportCommandShapeIgnoresSummaryKeys(): void
    {
        $data = ['violations' => [
            'total_count' => 1,
            'by_severity' => ['error' => 1],
            'by_category' => ['di' => 1],
            'top_violations' => [],
            'violations'  => [$this->issue()],
        ]];

        $r = $this->sarif($data)['runs'][0]['results'];
        self::assertCount(1, $r);
        self::assertSame('object_manager_usage', $r[0]['ruleId']);
    }

    public function testLegacyItemsShape(): void
    {
        self::assertCount(1, $this->sarif(['violations' => ['items' => [$this->issue()]]])['runs'][0]['results']);
    }

    public function testSrcRootIsWellFormedFileUri(): void
    {
        $uri = $this->sarif(['issues' => []])['runs'][0]['originalUriBaseIds']['SRCROOT']['uri'];
        self::assertSame('file://' . $this->root . '/', $uri);
        self::assertStringStartsNotWith('file:////', $uri);
    }

    public function testSeverityMapping(): void
    {
        $issues = [];
        foreach (['blocker', 'critical', 'error', 'warning', 'info', 'minor'] as $i => $sev) {
            $issues[] = $this->issue(['severity' => $sev, 'type' => "r{$i}"]);
        }
        $levels = array_column($this->sarif(['issues' => $issues])['runs'][0]['results'], 'level');
        self::assertSame(['error', 'error', 'error', 'warning', 'note', 'note'], $levels);
    }

    public function testFingerprintStableWhenLineMoves(): void
    {
        $before = $this->sarif(['issues' => [$this->issue()]])['runs'][0]['results'][0]['partialFingerprints'];

        $file = $this->root . '/app/code/V/M/Model/A.php';
        file_put_contents($file, "<?php\n\n\n\nclass A {}\n\$om = ObjectManager::getInstance();\n");
        $after = $this->sarif(['issues' => [$this->issue(['line' => 6])]])['runs'][0]['results'][0]['partialFingerprints'];

        self::assertSame($before, $after);
        self::assertArrayHasKey(SARIFReportGenerator::FINGERPRINT_KEY, $before);
    }

    public function testFingerprintChangesWhenFlaggedCodeChanges(): void
    {
        $before = $this->sarif(['issues' => [$this->issue()]])['runs'][0]['results'][0]['partialFingerprints'];
        file_put_contents($this->root . '/app/code/V/M/Model/A.php', "<?php\nclass A {}\n\$x = ObjectManager::getInstance()->get('Y');\n");
        $after = $this->sarif(['issues' => [$this->issue()]])['runs'][0]['results'][0]['partialFingerprints'];

        self::assertNotSame($before, $after);
    }

    public function testDuplicateIssuesGetDistinctFingerprints(): void
    {
        $r = $this->sarif(['issues' => [$this->issue(), $this->issue()]])['runs'][0]['results'];
        self::assertNotSame($r[0]['partialFingerprints'], $r[1]['partialFingerprints']);
    }

    public function testSourceRootIsUsedForLineText(): void
    {
        // Staged mode: issue paths are relative, source is read from the snapshot dir.
        $snap = $this->root . '/snap';
        mkdir($snap . '/app/code/V/M/Model', 0777, true);
        copy($this->root . '/app/code/V/M/Model/A.php', $snap . '/app/code/V/M/Model/A.php');
        $issue = $this->issue(['file' => 'app/code/V/M/Model/A.php']);

        $viaSnap = $this->sarif(['issues' => [$issue]], ['source_root' => $snap])['runs'][0]['results'][0];
        $viaRoot = $this->sarif(['issues' => [$issue]])['runs'][0]['results'][0];

        self::assertSame('app/code/V/M/Model/A.php', $viaSnap['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertSame($viaRoot['partialFingerprints'], $viaSnap['partialFingerprints']);
    }

    public function testSuggestionGoesIntoMessageNotFixes(): void
    {
        $r = $this->sarif(['issues' => [$this->issue(['suggestion' => 'Inject a factory.'])]])['runs'][0]['results'][0];
        self::assertStringContainsString('Suggestion: Inject a factory.', $r['message']['text']);
    }

    public function testSecurityRulesGetSecuritySeverity(): void
    {
        $rules = $this->sarif(['issues' => [$this->issue(['category' => 'security', 'severity' => 'critical', 'type' => 'sql_injection'])]])
            ['runs'][0]['tool']['driver']['rules'];
        self::assertSame('9.0', $rules[0]['properties']['security-severity']);
        self::assertContains('security', $rules[0]['properties']['tags']);
    }

    public function testFileOutsideRootUsesAbsoluteUriWithoutBase(): void
    {
        $loc = $this->sarif(['issues' => [$this->issue(['file' => '/elsewhere/B.php'])]])
            ['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation'];
        self::assertSame('file:///elsewhere/B.php', $loc['uri']);
        self::assertArrayNotHasKey('uriBaseId', $loc);
    }

    public function testSourceTextIsNeverCopiedIntoSarif(): void
    {
        $secret = 'AKIAIOSFODNN7EXAMPLE';
        file_put_contents($this->root . '/app/code/V/M/Model/A.php', "<?php\n\$key = '{$secret}';\n");
        $issue = $this->issue(['line' => 2, 'message' => 'Possible hard-coded credential.', 'category' => 'security']);

        $result = (new SARIFReportGenerator())->generate(['issues' => [$issue]], ['project_path' => $this->root]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringNotContainsString($secret, $result->getContent(), 'flagged line is hashed, not emitted');
    }

    public function testFingerprintStableAcrossLineEndingsAndIndentation(): void
    {
        $file = $this->root . '/app/code/V/M/Model/A.php';
        $lf   = $this->sarif(['issues' => [$this->issue()]])['runs'][0]['results'][0]['partialFingerprints'];

        file_put_contents($file, "<?php\r\nclass A {}\r\n    \$om = ObjectManager::getInstance();\r\n");
        $crlf = $this->sarif(['issues' => [$this->issue()]])['runs'][0]['results'][0]['partialFingerprints'];

        $this->assertSame($lf, $crlf);
    }

    public function testMissingFieldsFallBackSafely(): void
    {
        $r = $this->sarif(['issues' => [['message' => 'x']]])['runs'][0]['results'][0];
        self::assertSame('ACQC-GENERIC', $r['ruleId']);
        self::assertSame(1, $r['locations'][0]['physicalLocation']['region']['startLine']);
    }
}
