<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ConsoleReporter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tests unitaires pour le générateur de rapport console.
 * Vérifie le contenu et le formatage de la sortie console.
 */
final class ConsoleReporterTest extends TestCase
{
    /**
     * Crée un rapport de migration pour les tests.
     */
    private function createReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable('2025-01-15 10:00:00'),
            detectedSyliusVersion: 'v1.14.0',
            targetVersion: '2.2',
            projectPath: '/tmp/test-project',
        );
    }

    /**
     * Vérifie que le titre du rapport est affiché dans la sortie.
     */
    #[Test]
    public function testGenerateOutputsTitle(): void
    {
        $reporter = new ConsoleReporter();
        $report = $this->createReport();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $reporter->generate($report, $output);
        $content = $output->fetch();

        /* Le titre du rapport doit apparaître dans la sortie */
        self::assertStringContainsString('Rapport d\'analyse de migration Sylius', $content);
    }

    /**
     * Vérifie que le niveau de complexité est affiché dans le résumé.
     */
    #[Test]
    public function testGenerateShowsComplexityLevel(): void
    {
        $reporter = new ConsoleReporter();
        $report = $this->createReport();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $reporter->generate($report, $output);
        $content = $output->fetch();

        /* Le libellé de complexité doit être présent (TRIVIALE pour un rapport vide) */
        self::assertStringContainsString('TRIVIALE', $content);
    }

    /**
     * Vérifie que les problèmes BREAKING sont affichés en rouge avec leur message.
     */
    #[Test]
    public function testGenerateShowsBreakingIssuesInRed(): void
    {
        $reporter = new ConsoleReporter();
        $report = $this->createReport();

        /* Ajout d'un problème BREAKING */
        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::PLUGIN,
            analyzer: 'test',
            message: 'Plugin incompatible détecté',
            detail: 'Le plugin X n\'est pas compatible.',
            suggestion: 'Contacter le mainteneur.',
            estimatedMinutes: 120,
        ));

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $reporter->generate($report, $output);
        $content = $output->fetch();

        /* Le message du problème doit apparaître dans la sortie */
        self::assertStringContainsString('Plugin incompatible détecté', $content);
        /* La section des changements cassants doit être présente */
        self::assertStringContainsString('Changements cassants', $content);
        /* Le code ANSI rouge (ESC[31m) doit être présent dans la sortie décorée */
        self::assertStringContainsString("\033[31m", $content);
    }

    /**
     * Vérifie que les heures estimées sont affichées.
     */
    #[Test]
    public function testGenerateShowsEstimatedHours(): void
    {
        $reporter = new ConsoleReporter();
        $report = $this->createReport();

        /* Ajout d'un problème avec estimation */
        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::TWIG,
            analyzer: 'test',
            message: 'Template surchargé',
            detail: 'Détail',
            suggestion: 'Suggestion',
            estimatedMinutes: 120,
        ));

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $reporter->generate($report, $output);
        $content = $output->fetch();

        /* Le total d'heures doit être affiché : 120 min => 2.0 heures */
        self::assertStringContainsString('2.0 heures', $content);
    }

    /**
     * Vérifie qu'un message approprié s'affiche quand il n'y a aucun problème.
     * Pour un rapport vide, les sections BREAKING/WARNING/SUGGESTION sont absentes.
     */
    #[Test]
    public function testGenerateShowsNoIssuesMessage(): void
    {
        $reporter = new ConsoleReporter();
        $report = $this->createReport();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $reporter->generate($report, $output);
        $content = $output->fetch();

        /* Sans problèmes, les sections de détail ne doivent pas apparaître */
        self::assertStringNotContainsString('Changements cassants', $content);
        /* Le total estimé doit être 0 */
        self::assertStringContainsString('0.0 heures', $content);
    }

    /**
     * Vérifie que getFormat retourne 'console'.
     */
    #[Test]
    public function testGetFormatReturnsConsole(): void
    {
        $reporter = new ConsoleReporter();

        self::assertSame('console', $reporter->getFormat());
    }

    /**
     * Vérifie que la version détectée est affichée dans l'en-tête.
     */
    #[Test]
    public function testGenerateShowsDetectedVersion(): void
    {
        $reporter = new ConsoleReporter();
        $report = $this->createReport();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $reporter->generate($report, $output);
        $content = $output->fetch();

        /* La version détectée doit apparaître dans l'en-tête */
        self::assertStringContainsString('v1.14.0', $content);
    }
}
