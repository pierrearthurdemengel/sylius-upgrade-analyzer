<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Estimation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\MigrationRoadmapGenerator;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\RoadmapStep;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le générateur de feuille de route de migration.
 */
final class MigrationRoadmapGeneratorTest extends TestCase
{
    private MigrationRoadmapGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MigrationRoadmapGenerator();
    }

    /**
     * Crée un rapport de migration vide.
     */
    private function createEmptyReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12.0',
            targetVersion: '2.0.0',
            projectPath: sys_get_temp_dir(),
        );
    }

    /**
     * Crée un rapport de migration avec des problèmes variés.
     */
    private function createReportWithIssues(): MigrationReport
    {
        $report = $this->createEmptyReport();

        /* Problème bloquant de dépréciation */
        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'DeprecationAnalyzer',
            message: 'Méthode obsolète détectée',
            detail: 'Détail',
            suggestion: 'Suggestion',
            file: 'src/Entity/Product.php',
            line: 10,
            estimatedMinutes: 60,
        ));

        /* Problème bloquant de template Twig */
        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::TWIG,
            analyzer: 'TwigHookAnalyzer',
            message: 'Hook Twig obsolète',
            detail: 'Détail',
            suggestion: 'Utiliser le nouveau système de hooks',
            file: 'templates/product/show.html.twig',
            line: 15,
            estimatedMinutes: 45,
        ));

        /* Problème bloquant de plugin */
        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::PLUGIN,
            analyzer: 'PluginCompatibilityAnalyzer',
            message: 'Plugin incompatible',
            detail: 'Détail',
            suggestion: 'Mettre à jour le plugin',
            estimatedMinutes: 120,
        ));

        /* Avertissement */
        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::GRID,
            analyzer: 'GridAnalyzer',
            message: 'Configuration de grille dépréciée',
            detail: 'Détail',
            suggestion: 'Migrer vers la nouvelle syntaxe',
            estimatedMinutes: 20,
        ));

        /* Suggestion de correction automatique */
        $report->addIssue(new MigrationIssue(
            severity: Severity::SUGGESTION,
            category: Category::DEPRECATION,
            analyzer: 'AutoFixAnalyzer',
            message: 'Correction automatique disponible',
            detail: 'Détail',
            suggestion: 'Appliquer le correctif automatique',
            estimatedMinutes: 5,
        ));

        return $report;
    }

    /** Vérifie qu'un rapport vide produit au moins les étapes de prérequis et de validation. */
    #[Test]
    public function testGenerateWithEmptyReport(): void
    {
        $report = $this->createEmptyReport();
        $steps = $this->generator->generate($report);

        /* Même sans problème, les prérequis et la validation sont générés */
        self::assertNotEmpty($steps);

        /* Vérification que les étapes de prérequis sont présentes */
        $stages = array_unique(array_map(
            static fn (RoadmapStep $step): string => $step->stage,
            $steps,
        ));

        self::assertContains('Prérequis', $stages);
        self::assertContains('Validation', $stages);
    }

    /** Vérifie que les phases sont générées dans l'ordre attendu. */
    #[Test]
    public function testGenerateOrdersStagesCorrectly(): void
    {
        $report = $this->createReportWithIssues();
        $steps = $this->generator->generate($report);

        /* Extraction de la liste ordonnée des phases (sans doublons, dans l'ordre d'apparition) */
        $stages = [];
        foreach ($steps as $step) {
            if (!in_array($step->stage, $stages, true)) {
                $stages[] = $step->stage;
            }
        }

        /* Les phases attendues dans l'ordre */
        $expectedOrder = ['Prérequis', 'Corrections automatiques', 'Changements bloquants', 'Frontend', 'Plugins', 'Validation'];

        /* Vérification que les phases présentes respectent l'ordre relatif attendu */
        $filteredExpected = array_values(array_filter(
            $expectedOrder,
            static fn (string $stage): bool => in_array($stage, $stages, true),
        ));

        self::assertSame($filteredExpected, $stages);
    }

    /** Vérifie que chaque étape a une estimation d'heures positive. */
    #[Test]
    public function testGenerateIncludesEstimatedHours(): void
    {
        $report = $this->createReportWithIssues();
        $steps = $this->generator->generate($report);

        foreach ($steps as $step) {
            self::assertGreaterThan(
                0.0,
                $step->estimatedHours,
                sprintf('L\'étape « %s » doit avoir une estimation d\'heures positive', $step->name),
            );
        }
    }

    /** Vérifie que certaines tâches sont identifiées comme parallélisables. */
    #[Test]
    public function testGenerateIdentifiesParallelTasks(): void
    {
        $report = $this->createReportWithIssues();
        $steps = $this->generator->generate($report);

        /* Au moins une étape doit être parallélisable (les corrections automatiques, le frontend, etc.) */
        $parallelSteps = array_filter(
            $steps,
            static fn (RoadmapStep $step): bool => $step->canParallelize,
        );

        self::assertNotEmpty($parallelSteps, 'Au moins une étape doit être parallélisable');
    }
}
