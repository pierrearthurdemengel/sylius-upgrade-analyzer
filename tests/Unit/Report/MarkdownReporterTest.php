<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\MarkdownReporter;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests unitaires pour le générateur de rapport Markdown.
 */
final class MarkdownReporterTest extends TestCase
{
    private MarkdownReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new MarkdownReporter();
    }

    /**
     * Crée un rapport de migration avec un problème bloquant pour les tests.
     */
    private function createReportWithBreakingIssue(): MigrationReport
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12.0',
            targetVersion: '2.0.0',
            projectPath: sys_get_temp_dir(),
        );

        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'DeprecationAnalyzer',
            message: 'Méthode obsolète détectée',
            detail: 'La méthode getChannel() a été supprimée',
            suggestion: 'Utiliser getChannelCode() à la place',
            file: 'src/Controller/ShopController.php',
            line: 55,
            codeSnippet: '$channel = $this->getChannel();',
            docUrl: 'https://docs.sylius.com/migration',
            estimatedMinutes: 30,
        ));

        return $report;
    }

    /**
     * Génère le Markdown et retourne le contenu brut.
     */
    private function generateMarkdown(MigrationReport $report): string
    {
        $output = new BufferedOutput();
        $this->reporter->generate($report, $output);

        return $output->fetch();
    }

    /** Vérifie que le format retourné est bien « markdown ». */
    #[Test]
    public function testGetFormatReturnsMarkdown(): void
    {
        self::assertSame('markdown', $this->reporter->getFormat());
    }

    /** Vérifie que le rapport contient un tableau récapitulatif avec la syntaxe pipe. */
    #[Test]
    public function testGenerateIncludesSummaryTable(): void
    {
        $report = $this->createReportWithBreakingIssue();
        $markdown = $this->generateMarkdown($report);

        /* Vérification de la présence de la syntaxe de tableau Markdown */
        self::assertStringContainsString('|', $markdown);
        self::assertStringContainsString('|--------', $markdown);
        self::assertStringContainsString('Catégorie', $markdown);
    }

    /** Vérifie que les problèmes bloquants apparaissent dans le rapport. */
    #[Test]
    public function testGenerateIncludesBreakingIssues(): void
    {
        $report = $this->createReportWithBreakingIssue();
        $markdown = $this->generateMarkdown($report);

        /* Le titre de section des problèmes bloquants doit être présent */
        self::assertStringContainsString('Problèmes bloquants', $markdown);
        /* Le message du problème doit apparaître */
        self::assertStringContainsString('Méthode obsolète détectée', $markdown);
    }

    /** Vérifie que le badge de complexité est affiché. */
    #[Test]
    public function testGenerateIncludesComplexityBadge(): void
    {
        $report = $this->createReportWithBreakingIssue();
        $markdown = $this->generateMarkdown($report);

        /* Le texte « Complexité » doit apparaître dans le badge */
        self::assertStringContainsString('Complexité', $markdown);
        self::assertStringContainsString('heures estimées', $markdown);
    }

    /** Vérifie que le rapport peut être écrit dans un fichier temporaire. */
    #[Test]
    public function testGenerateWritesToFile(): void
    {
        $report = $this->createReportWithBreakingIssue();
        $tempFile = sys_get_temp_dir() . '/sylius_test_report_' . uniqid() . '.md';

        try {
            $output = new BufferedOutput();
            $this->reporter->generate($report, $output, ['output_file' => $tempFile]);

            self::assertFileExists($tempFile);

            $content = file_get_contents($tempFile);
            self::assertIsString($content);
            self::assertStringContainsString('Rapport de migration Sylius', $content);
        } finally {
            /* Nettoyage du fichier temporaire */
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
