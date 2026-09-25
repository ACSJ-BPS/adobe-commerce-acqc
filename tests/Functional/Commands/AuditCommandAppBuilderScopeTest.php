<?php

declare(strict_types=1);

namespace ACQC\Tests\Functional\Commands;

use ACQC\Analyzers\AppBuilder\AbqcAnalyzer;
use ACQC\Commands\AuditCommand;
use ACQC\Tests\Support\GitRepoFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Scoped audits (--staged / --diff-from / --files) on App Builder and mixed
 * Commerce + App Builder repositories. Requires node and a built abqc.
 */
#[CoversClass(AuditCommand::class)]
#[Group('functional')]
#[Group('git')]
#[Group('appbuilder')]
final class AuditCommandAppBuilderScopeTest extends TestCase
{
    private GitRepoFixture $repo;
    private string $origCwd;

    private const BAD_JS  = "exports.main = async (p) => { eval(p.x); return {} }\n";
    private const GOOD_JS = "exports.main = async (params) => {\n  try {\n    return { statusCode: 200, body: {} }\n  } catch (e) {\n    return { error: { statusCode: 500, body: {} } }\n  }\n}\n";
    private const BAD_PHP = "<?php\nclass Bad { public function x() { return \\Magento\\Framework\\App\\ObjectManager::getInstance(); } }\n";

    protected function setUp(): void
    {
        if (!GitRepoFixture::gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->repo = new GitRepoFixture();
        $this->repo->write('package.json', "{\"name\":\"x\",\"dependencies\":{\"@adobe/aio-sdk\":\"^6\"}}\n");
        $this->repo->write('app.config.yaml', "application:\n  runtimeManifest: {}\n");
        $this->repo->write('.gitignore', "node_modules\n.env\n.aio\nconsole.json\n");
        $this->repo->commitAll('init');
        $this->origCwd = (string) getcwd();
        chdir($this->repo->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->origCwd)) {
            chdir($this->origCwd);
        }
        if (isset($this->repo)) {
            $this->repo->destroy();
        }
    }

    private function requireAbqc(): void
    {
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if ($node === '' || !is_file(AbqcAnalyzer::abqcBinPath())) {
            $this->markTestSkipped('node or built abqc not available');
        }
    }

    private function audit(array $args): array
    {
        $cmd = new AuditCommand();
        (new Application())->add($cmd);
        $t = new CommandTester($cmd);
        $exit = $t->execute(['path' => $this->repo->root] + $args);
        return [$exit, $t->getDisplay()];
    }

    private function json(string $out): array
    {
        $j = json_decode(substr($out, (int) strpos($out, '{')), true);
        $this->assertIsArray($j, $out);
        return $j;
    }

    public function testCommerceRepoWithGruntPackageJsonStagedRequireJsNotRoutedToAbqc(): void
    {
        // CR-1: Magento repo with a Grunt-only root package.json is NOT App Builder.
        $this->repo->destroy();
        $this->repo = new GitRepoFixture();
        chdir($this->repo->root);
        $this->repo->write('composer.json', "{\"require\":{\"magento/product-community-edition\":\"2.4.7\"}}\n");
        $this->repo->write('app/etc/config.php', "<?php return [];\n");
        $this->repo->write('package.json', "{\"name\":\"magento2\",\"devDependencies\":{\"grunt\":\"^1.0.4\"}}\n");
        $this->repo->write('Gruntfile.js', "module.exports = function () {};\n");
        $this->repo->commitAll('init');
        $this->repo->write('app/code/V/M/view/frontend/web/js/x.js', "define(['jquery'], function ($) { 'use strict'; return {}; });\n");
        $this->repo->write('.gitlab-ci.yml', "stages: [test]\n");
        $this->repo->git('add', '-A');

        // Hide node (keep git): the gate must not need node for a Commerce-only change.
        $origPath = (string) getenv('PATH');
        $git = trim((string) shell_exec('command -v git'));
        $bin = sys_get_temp_dir() . '/acqc-nonode-' . bin2hex(random_bytes(4));
        mkdir($bin);
        symlink($git, $bin . '/git');
        putenv('PATH=' . $bin);
        try {
            [$exit, $out] = $this->audit(['--staged' => true, '--profile' => 'pre-commit']);
        } finally {
            putenv('PATH=' . $origPath);
            @unlink($bin . '/git');
            @rmdir($bin);
        }

        $this->assertSame(AuditCommand::EXIT_PASS, $exit, $out);
        $this->assertStringNotContainsString('ABQC:', $out);
    }

    public function testMixedRepoSubdirAppBuilderStagedSecretBlocks(): void
    {
        $this->requireAbqc();
        // Replace the root-level App Builder fixture with Commerce at the root and
        // App Builder under app-builder/ (C4).
        $this->repo->destroy();
        $this->repo = new GitRepoFixture();
        chdir($this->repo->root);
        $this->repo->write('composer.json', "{\"require\":{\"magento/product-community-edition\":\"2.4.7\"}}\n");
        $this->repo->write('app/etc/config.php', "<?php return [];\n");
        $this->repo->write('app-builder/package.json', "{\"name\":\"ab\",\"dependencies\":{\"@adobe/aio-sdk\":\"^6\"}}\n");
        $this->repo->write('app-builder/app.config.yaml', "application:\n  runtimeManifest: {}\n");
        $this->repo->commitAll('init');
        $this->repo->write('app-builder/actions/a/index.js', "const k = \"AKIAIOSFODNN7EXAMPLE\";\n" . self::BAD_JS);
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');

        [$exit, $out] = $this->audit(['--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $j = $this->json($out);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit, $out);
        $this->assertSame(2, $j['files_analyzed']);
        $files = array_column($j['issues'], 'file');
        $this->assertContains('app-builder/actions/a/index.js', $files, 'sub-folder App Builder JS must be audited, repo-relative');
        $this->assertContains('app/code/V/M/Model/Bad.php', $files, 'Commerce PHP still audited');
        $rules = array_column($j['issues'], 'rule_id');
        $this->assertContains('ABQC:security/supply-chain/no-hard-coded-api-keys-in-source-params-git-history', $rules);
        $this->assertStringNotContainsString('acqc-staged-', $out);
    }

    public function testStagedMinifiedJsIsStillLinted(): void
    {
        $this->requireAbqc();
        $this->repo->write('actions/a/index.min.js', "const k = \"AKIAIOSFODNN7EXAMPLE\";\n" . self::BAD_JS);
        $this->repo->git('add', '-A');

        [$exit, $out] = $this->audit(['--staged' => true, '--profile' => 'ci', '--format' => 'json']);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit, 'QJ-2: *.min.js must not bypass the gate');
        $this->assertContains('actions/a/index.min.js', array_column($this->json($out)['issues'], 'file'));
    }

    public function testHardCodedSecretInManifestYamlBlocks(): void
    {
        $this->requireAbqc();
        $this->repo->write('actions/a/manifest.yml', "inputs:\n  AWS_KEY: AKIAIOSFODNN7EXAMPLE\n");
        $this->repo->git('add', '-A');

        [$exit, $out] = $this->audit(['--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $issues = array_filter($this->json($out)['issues'], fn (array $i): bool => ($i['rule_id'] ?? '') === 'ACQC:appbuilder/yaml-hardcoded-secret');

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit, 'QJ-3');
        $this->assertCount(1, $issues);
        $this->assertSame('actions/a/manifest.yml', array_values($issues)[0]['file']);
        $this->assertSame(2, array_values($issues)[0]['line']);
        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', array_values($issues)[0]['message']);
    }

    public function testStagedJsIsInScopeAndBlocks(): void
    {
        $this->requireAbqc();
        $this->repo->write('actions/a/index.js', self::BAD_JS);
        $this->repo->git('add', '-A');

        [$exit, $out] = $this->audit(['--staged' => true, '--profile' => 'pre-commit']);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit, $out);
        $this->assertStringContainsString('1 file(s) checked (staged)', $out);
        $this->assertMatchesRegularExpression('#^  actions/a/index\.js:\d+  ERROR  \[ABQC:#m', $out, 'repo-relative path');
        $this->assertStringNotContainsString('acqc-staged-', $out, 'snapshot paths must not leak');
    }

    public function testPartialStagingAuditsIndexNotWorkingTree(): void
    {
        $this->requireAbqc();
        $this->repo->write('actions/a/index.js', self::GOOD_JS);
        $this->repo->git('add', '-A');
        $this->repo->write('actions/a/index.js', self::BAD_JS); // unstaged

        [, $out] = $this->audit(['--staged' => true, '--profile' => 'ci', '--format' => 'json']);

        $rules = array_column($this->json($out)['issues'], 'rule_id');
        $this->assertNotContains('ABQC:node/bug-classes/no-eval-new-function-or-vm-runinthiscontext', $rules);
    }

    public function testUnchangedJsOutsideScopeIsIgnored(): void
    {
        $this->requireAbqc();
        $this->repo->write('actions/legacy/index.js', self::BAD_JS);
        $this->repo->commitAll('legacy');
        $this->repo->write('actions/new/index.js', self::GOOD_JS);
        $this->repo->git('add', '-A');

        [, $out] = $this->audit(['--staged' => true, '--profile' => 'ci', '--format' => 'json']);

        foreach ($this->json($out)['issues'] as $issue) {
            $this->assertStringNotContainsString('actions/legacy/', (string) $issue['file']);
        }
    }

    public function testDiffFromIncludesJsAndCountsFiles(): void
    {
        $this->requireAbqc();
        $this->repo->write('actions/a/index.js', self::BAD_JS);
        $this->repo->commitAll('bad');

        [$exit, $out] = $this->audit(['--diff-from' => 'HEAD~1', '--profile' => 'ci', '--format' => 'json']);
        $j = $this->json($out);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $this->assertSame(1, $j['files_analyzed']);
        $this->assertNotSame([], $j['issues']);
    }

    public function testFilesOptionCountsJsFiles(): void
    {
        $this->requireAbqc();
        $this->repo->write('actions/a/index.js', self::BAD_JS);

        [, $out] = $this->audit(['--files' => ['actions/a/index.js'], '--profile' => 'ci', '--format' => 'json']);
        $this->assertSame(1, $this->json($out)['files_analyzed']);
    }

    public function testMixedRepoChecksPhpAndJsTogether(): void
    {
        $this->requireAbqc();
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->write('actions/a/index.js', self::BAD_JS);
        $this->repo->git('add', '-A');

        [$exit, $out] = $this->audit(['--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $j = $this->json($out);
        $files = array_unique(array_column($j['issues'], 'file'));
        sort($files);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $this->assertSame(2, $j['files_analyzed']);
        $this->assertSame(['actions/a/index.js', 'app/code/V/M/Model/Bad.php'], array_values($files));
    }

    public function testMissingNodeInScopedModeIsToolError(): void
    {
        $this->repo->write('actions/a/index.js', self::BAD_JS);
        $this->repo->git('add', '-A');

        $oldPath = (string) getenv('PATH');
        $bin = sys_get_temp_dir() . '/acqc-nonode-' . bin2hex(random_bytes(4));
        mkdir($bin);
        symlink(trim((string) shell_exec('command -v git')), $bin . '/git');
        putenv('PATH=' . $bin);
        try {
            [$exit, $out] = $this->audit(['--staged' => true, '--profile' => 'pre-commit']);
        } finally {
            putenv('PATH=' . $oldPath);
            unlink($bin . '/git');
            rmdir($bin);
        }

        $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $exit, $out);
        $this->assertStringContainsString('Node.js ("node") was not found', $out);
    }
}
