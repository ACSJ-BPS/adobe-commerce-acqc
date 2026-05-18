<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Reports;

use ACQC\Reports\ReportGenerator;
use ACQC\Reports\Generators\HTMLReportGenerator;
use ACQC\Reports\Generators\JSONReportGenerator;
use ACQC\Reports\Generators\PDFReportGenerator;
use ACQC\Reports\Generators\ReportResult;
use ACQC\Core\Rules\Violation;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \ACQC\Reports\ReportGenerator
 * @group unit
 * @group reports
 */
class ReportGeneratorTest extends TestCase
{
    private ReportGenerator $reportGenerator;
    private HTMLReportGenerator|MockObject $htmlGenerator;
    private JSONReportGenerator|MockObject $jsonGenerator;
    private PDFReportGenerator|MockObject $pdfGenerator;

    protected function setUp(): void
    {
        $this->htmlGenerator = $this->createMock(HTMLReportGenerator::class);
        $this->jsonGenerator = $this->createMock(JSONReportGenerator::class);
        $this->pdfGenerator = $this->createMock(PDFReportGenerator::class);

        $this->reportGenerator = new ReportGenerator([
            'html' => $this->htmlGenerator,
            'json' => $this->jsonGenerator,
            'pdf' => $this->pdfGenerator
        ]);
    }

    /**
     * @test
     * @group fast
     */
    public function testGenerateHTMLReport(): void
    {
        $violations = $this->createSampleViolations();
        $expectedOutput = '<html><body>Report content</body></html>';

        $this->htmlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with($violations)
            ->willReturn(new ReportResult(true, $expectedOutput));

        $result = $this->reportGenerator->generate($violations, 'html');

        $this->assertEquals($expectedOutput, $result);
    }

    /**
     * @test
     * @group fast
     */
    public function testGenerateJSONReport(): void
    {
        $violations = $this->createSampleViolations();
        $expectedOutput = json_encode(['violations' => []]);

        $this->jsonGenerator
            ->expects($this->once())
            ->method('generate')
            ->with($violations)
            ->willReturn(new ReportResult(true, $expectedOutput));

        $result = $this->reportGenerator->generate($violations, 'json');

        $this->assertEquals($expectedOutput, $result);
    }

    /**
     * @test
     * @group fast
     */
    public function testUnsupportedFormat(): void
    {
        $violations = $this->createSampleViolations();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported report format: unsupported');

        $this->reportGenerator->generate($violations, 'unsupported');
    }

    /**
     * @test
     * @group fast
     */
    public function testGetSupportedFormats(): void
    {
        $formats = $this->reportGenerator->getSupportedFormats();

        $this->assertIsArray($formats);
        $this->assertContains('html', $formats);
        $this->assertContains('json', $formats);
        $this->assertContains('pdf', $formats);
    }

    private function createSampleViolations(): array
    {
        return [
            new Violation('test-rule', 'Test violation message', 'test.php', 10, 0, 'error'),
            new Violation('another-rule', 'Another violation', 'another.php', 20, 0, 'warning'),
        ];
    }
} 