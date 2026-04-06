<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\CsvReporter;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests unitaires pour le générateur de rapport CSV.
 * Vérifie le BOM UTF-8, le séparateur point-virgule et le contenu des lignes.
 */
final class CsvReporterTest extends TestCase
{
    private CsvReporter $reporter;

    /** @var list<string> Fichiers temporaires à nettoyer */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->reporter = new CsvReporter();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function createReport(int $issueCount = 0): MigrationReport
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12.0',
            targetVersion: '2.0.0',
            projectPath: sys_get_temp_dir(),
        );

        for ($i = 0; $i < $issueCount; ++$i) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::FRONTEND,
                analyzer: 'TestAnalyzer',
                message: sprintf('Issue %d', $i + 1),
                detail: 'Détail technique',
                suggestion: 'Suggestion de correction',
                file: 'src/test.php',
                line: $i + 1,
                codeSnippet: null,
                docUrl: 'https://docs.sylius.com',
                estimatedMinutes: 30,
            ));
        }

        return $report;
    }

    /** Vérifie que le format retourné est bien « csv ». */
    #[Test]
    public function testGetFormatReturnsCsv(): void
    {
        self::assertSame('csv', $this->reporter->getFormat());
    }

    /** Vérifie que le fichier CSV est créé avec le BOM UTF-8. */
    #[Test]
    public function testGenerateCreatesCsvWithBom(): void
    {
        $outputPath = sys_get_temp_dir() . '/csv-test-bom-' . uniqid() . '.csv';
        $this->tempFiles[] = $outputPath;

        $report = $this->createReport(1);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output, ['output_file' => $outputPath]);

        self::assertFileExists($outputPath);

        $content = file_get_contents($outputPath);
        self::assertNotFalse($content);

        /* Le fichier doit commencer par le BOM UTF-8 */
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
    }

    /** Vérifie que le séparateur est le point-virgule. */
    #[Test]
    public function testGenerateUsesSemicolonSeparator(): void
    {
        $outputPath = sys_get_temp_dir() . '/csv-test-sep-' . uniqid() . '.csv';
        $this->tempFiles[] = $outputPath;

        $report = $this->createReport(1);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output, ['output_file' => $outputPath]);

        $content = file_get_contents($outputPath);
        self::assertNotFalse($content);

        /* La première ligne (en-têtes) doit contenir des points-virgules */
        $lines = explode("\n", $content);
        self::assertStringContainsString(';', $lines[0]);
    }

    /** Vérifie que les en-têtes sont présents dans la première ligne. */
    #[Test]
    public function testGenerateIncludesHeaders(): void
    {
        $outputPath = sys_get_temp_dir() . '/csv-test-hdr-' . uniqid() . '.csv';
        $this->tempFiles[] = $outputPath;

        $report = $this->createReport(0);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output, ['output_file' => $outputPath]);

        $content = file_get_contents($outputPath);
        self::assertNotFalse($content);

        self::assertStringContainsString('Catégorie', $content);
        self::assertStringContainsString('Sévérité', $content);
        self::assertStringContainsString('Analyseur', $content);
        self::assertStringContainsString('Message', $content);
    }

    /** Vérifie que chaque issue produit une ligne dans le CSV. */
    #[Test]
    public function testGenerateIncludesAllIssues(): void
    {
        $outputPath = sys_get_temp_dir() . '/csv-test-issues-' . uniqid() . '.csv';
        $this->tempFiles[] = $outputPath;

        $report = $this->createReport(3);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output, ['output_file' => $outputPath]);

        $content = file_get_contents($outputPath);
        self::assertNotFalse($content);

        /* 1 ligne BOM+headers + 3 lignes issues + possible ligne vide finale */
        $lines = array_filter(explode("\n", trim($content)));
        self::assertGreaterThanOrEqual(4, count($lines));

        self::assertStringContainsString('Issue 1', $content);
        self::assertStringContainsString('Issue 2', $content);
        self::assertStringContainsString('Issue 3', $content);
    }

    /** Vérifie que le message de confirmation est écrit dans la sortie console. */
    #[Test]
    public function testGenerateOutputsConfirmation(): void
    {
        $outputPath = sys_get_temp_dir() . '/csv-test-confirm-' . uniqid() . '.csv';
        $this->tempFiles[] = $outputPath;

        $report = $this->createReport(2);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output, ['output_file' => $outputPath]);

        $display = $output->fetch();
        self::assertStringContainsString('CSV', $display);
        self::assertStringContainsString('2', $display);
    }

    /** Vérifie que l'extension .csv est ajoutée automatiquement si manquante. */
    #[Test]
    public function testGenerateAppendsCsvExtension(): void
    {
        $basePath = sys_get_temp_dir() . '/csv-test-ext-' . uniqid();
        $expectedPath = $basePath . '.csv';
        $this->tempFiles[] = $expectedPath;

        $report = $this->createReport(0);
        $output = new BufferedOutput();

        /* Passe un chemin .json — le reporter doit remplacer par .csv */
        $this->reporter->generate($report, $output, ['output_file' => $basePath . '.json']);

        self::assertFileExists($expectedPath);
    }

    /** Vérifie le fallback vers un nom de fichier par défaut sans contexte. */
    #[Test]
    public function testGenerateUsesDefaultFilename(): void
    {
        $report = $this->createReport(0);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output, []);

        /* Le fichier par défaut est migration-report.csv dans le répertoire courant */
        $defaultPath = 'migration-report.csv';
        if (file_exists($defaultPath)) {
            $this->tempFiles[] = $defaultPath;
        }

        $display = $output->fetch();
        self::assertStringContainsString('CSV', $display);
    }
}
