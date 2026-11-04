<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\MultiProjectReporter;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests unitaires du rapport consolidé multi-projets.
 */
final class MultiProjectReporterTest extends TestCase
{
    /**
     * Crée un rapport avec un nombre donné d'issues bloquantes de 2 h chacune.
     */
    private function createReport(string $name, int $breakingCount): MigrationReport
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12.0',
            targetVersion: '2.2',
            projectPath: '/var/www/' . basename($name),
        );
        $report->setProjectName($name);

        for ($i = 0; $i < $breakingCount; ++$i) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: 'SwiftMailer',
                message: sprintf('Usage de SwiftMailer détecté (%d)', $i),
                detail: 'SwiftMailer est supprimé de Sylius 2.0.',
                suggestion: 'Migrer vers symfony/mailer.',
                estimatedMinutes: 120,
            ));
        }
        $report->complete();

        return $report;
    }

    /**
     * @return list<MigrationReport>
     */
    private function createReports(): array
    {
        return [$this->createReport('acme/shop-fr', 2), $this->createReport('acme/shop-de', 5)];
    }

    /** Vérifie les totaux et la liste des projets du JSON consolidé. */
    #[Test]
    public function testBuildsJsonWithTotalsAndProjects(): void
    {
        $data = json_decode((new MultiProjectReporter())->buildJson($this->createReports(), 600), true);

        self::assertIsArray($data);
        self::assertSame(2, $data['meta']['projects_count']);
        self::assertSame(7, $data['totals']['breaking']);
        self::assertSame(14.0, $data['totals']['estimated_hours']);
        // 4 h → 1 jour, 10 h → 1,5 jour : 2,5 jours au total, soit 1 500 €.
        self::assertSame(2.5, $data['totals']['estimated_days']);
        self::assertSame(1500.0, $data['totals']['estimated_cost']);
        self::assertSame(['acme/shop-fr', 'acme/shop-de'], array_column($data['projects'], 'name'));
        self::assertArrayHasKey('issues', $data['projects'][0]['report']);
    }

    /** Vérifie que le budget est absent du JSON sans TJM. */
    #[Test]
    public function testOmitsCostFromJsonWithoutDailyRate(): void
    {
        $data = json_decode((new MultiProjectReporter())->buildJson($this->createReports()), true);

        self::assertIsArray($data);
        self::assertNull($data['totals']['estimated_cost']);
        self::assertNull($data['projects'][0]['estimated_cost']);
    }

    /** Vérifie le tableau Markdown avec sa ligne de total. */
    #[Test]
    public function testBuildsMarkdownTableWithTotalRow(): void
    {
        $markdown = (new MultiProjectReporter())->buildMarkdown($this->createReports(), 600);

        self::assertStringContainsString('| acme/shop-fr |', $markdown);
        self::assertStringContainsString('| acme/shop-de |', $markdown);
        self::assertStringContainsString('| **Total** |', $markdown);
        self::assertStringContainsString('Budget', $markdown);
    }

    /** Vérifie l'affichage console du tableau comparatif. */
    #[Test]
    public function testRendersConsoleTable(): void
    {
        $output = new BufferedOutput();

        (new MultiProjectReporter())->generate($this->createReports(), 'console', $output, null, 600);

        $display = $output->fetch();
        self::assertStringContainsString('acme/shop-fr', $display);
        self::assertStringContainsString('Total', $display);
        self::assertStringContainsString('1 500 €', $display);
    }

    /** Vérifie l'écriture du rapport dans un fichier. */
    #[Test]
    public function testWritesReportToOutputFile(): void
    {
        $outputFile = sys_get_temp_dir() . '/multi-report-' . uniqid() . '.md';

        try {
            (new MultiProjectReporter())->generate($this->createReports(), 'markdown', new BufferedOutput(), $outputFile);

            self::assertFileExists($outputFile);
            self::assertStringContainsString('consolidé', (string) file_get_contents($outputFile));
        } finally {
            @unlink($outputFile);
        }
    }

    /** Vérifie la génération d'un PDF consolidé valide. */
    #[Test]
    public function testWritesConsolidatedPdf(): void
    {
        $outputFile = sys_get_temp_dir() . '/multi-report-' . uniqid() . '.pdf';

        try {
            (new MultiProjectReporter())->generate($this->createReports(), 'pdf', new BufferedOutput(), $outputFile, 600);

            self::assertStringStartsWith('%PDF-', (string) file_get_contents($outputFile));
        } finally {
            @unlink($outputFile);
        }
    }

    /** Vérifie le refus d'un format non supporté. */
    #[Test]
    public function testRejectsUnsupportedFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MultiProjectReporter())->generate($this->createReports(), 'sarif', new BufferedOutput());
    }
}
