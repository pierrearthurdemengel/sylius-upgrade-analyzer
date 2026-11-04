<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\PdfRenderer;
use PierreArthur\SyliusUpgradeAnalyzer\Report\PdfReporter;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests unitaires du reporter PDF. dompdf est installé en dépendance de
 * développement : les tests produisent de vrais documents PDF.
 */
final class PdfReporterTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/pdf-reporter-test-' . uniqid();
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDirectory);
    }

    private function createReport(): MigrationReport
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.13.4',
            targetVersion: '2.2',
            projectPath: '/var/www/acme-shop',
        );
        $report->setProjectName('acme/sylius-shop');
        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::PLUGIN,
            analyzer: 'Plugin Compatibility',
            message: 'Plugin incompatible détecté : bitbag/wishlist-plugin',
            detail: 'Aucune version compatible Sylius 2.x publiée.',
            suggestion: 'Remplacer par une alternative maintenue ou forker le plugin.',
            estimatedMinutes: 480,
        ));
        $report->complete();

        return $report;
    }

    /** Vérifie que le format exposé est « pdf ». */
    #[Test]
    public function testExposesPdfFormat(): void
    {
        self::assertSame('pdf', (new PdfReporter())->getFormat());
    }

    /** Vérifie la génération d'un véritable fichier PDF. */
    #[Test]
    public function testWritesValidPdfFile(): void
    {
        $outputFile = $this->workDirectory . '/rapport.pdf';
        $output = new BufferedOutput();

        (new PdfReporter())->generate($this->createReport(), $output, ['output_file' => $outputFile, 'tjm' => 600]);

        self::assertFileExists($outputFile);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($outputFile));
        self::assertStringContainsString('Rapport PDF généré', $output->fetch());
    }

    /** Vérifie qu'une extension d'un autre format est corrigée en .pdf. */
    #[Test]
    public function testReplacesForeignExtensionWithPdf(): void
    {
        (new PdfReporter())->generate($this->createReport(), new BufferedOutput(), ['output_file' => $this->workDirectory . '/rapport.json']);

        self::assertFileExists($this->workDirectory . '/rapport.pdf');
        self::assertFileDoesNotExist($this->workDirectory . '/rapport.json');
    }

    /** Vérifie que le renderer détecte la présence de dompdf. */
    #[Test]
    public function testRendererReportsDompdfAvailability(): void
    {
        self::assertTrue((new PdfRenderer())->isAvailable());
    }
}
