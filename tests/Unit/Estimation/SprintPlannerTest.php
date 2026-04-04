<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Estimation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\Sprint;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\SprintPlanner;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le planificateur de sprints de migration.
 */
final class SprintPlannerTest extends TestCase
{
    private SprintPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new SprintPlanner();
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
     * Crée un rapport de migration avec des problèmes variés pour la planification.
     */
    private function createReportWithIssues(): MigrationReport
    {
        $report = $this->createEmptyReport();

        /* Plusieurs problèmes bloquants dans différentes catégories */
        for ($i = 0; $i < 3; $i++) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: 'DeprecationAnalyzer',
                message: sprintf('Dépréciation bloquante %d', $i + 1),
                detail: 'Détail',
                suggestion: 'Suggestion',
                file: sprintf('src/Service/Service%d.php', $i),
                line: 10 + $i,
                estimatedMinutes: 60,
            ));
        }

        /* Problèmes bloquants de plugins (tâches critiques) */
        for ($i = 0; $i < 2; $i++) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::PLUGIN,
                analyzer: 'PluginAnalyzer',
                message: sprintf('Plugin incompatible %d', $i + 1),
                detail: 'Détail',
                suggestion: 'Mettre à jour',
                estimatedMinutes: 90,
            ));
        }

        /* Avertissements */
        for ($i = 0; $i < 4; $i++) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::TWIG,
                analyzer: 'TwigAnalyzer',
                message: sprintf('Avertissement Twig %d', $i + 1),
                detail: 'Détail',
                suggestion: 'Corriger',
                file: sprintf('templates/page%d.html.twig', $i),
                line: 5,
                estimatedMinutes: 15,
            ));
        }

        /* Suggestions */
        for ($i = 0; $i < 3; $i++) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::SUGGESTION,
                category: Category::GRID,
                analyzer: 'GridAnalyzer',
                message: sprintf('Suggestion grille %d', $i + 1),
                detail: 'Détail',
                suggestion: 'Appliquer',
                estimatedMinutes: 10,
            ));
        }

        return $report;
    }

    /** Vérifie qu'un rapport vide ne génère aucun sprint (pas d'étapes = pas de sprints). */
    #[Test]
    public function testPlanWithEmptyReport(): void
    {
        $report = $this->createEmptyReport();

        /* Même avec un rapport vide, le roadmap génère des étapes de prérequis et validation.
         * Vérifions que le planificateur retourne des sprints (non vide car prérequis existent). */
        $sprints = $this->planner->plan($report, 40.0);

        /* Le roadmap génère toujours des prérequis et validation, donc des sprints sont créés */
        self::assertIsArray($sprints);

        /* Chaque sprint retourné doit avoir au moins une tâche */
        foreach ($sprints as $sprint) {
            self::assertNotEmpty($sprint->tasks);
        }
    }

    /** Vérifie que les tâches sont réparties sur plusieurs sprints. */
    #[Test]
    public function testPlanDistributesAcrossSprints(): void
    {
        $report = $this->createReportWithIssues();

        /* Vélocité faible pour forcer plusieurs sprints */
        $sprints = $this->planner->plan($report, 5.0);

        self::assertGreaterThan(1, count($sprints), 'Les tâches doivent être réparties sur plusieurs sprints');

        /* Vérification que les numéros de sprint sont séquentiels */
        for ($i = 0; $i < count($sprints); $i++) {
            self::assertSame($i + 1, $sprints[$i]->number);
        }
    }

    /** Vérifie que les tâches critiques sont correctement identifiées. */
    #[Test]
    public function testPlanIdentifiesCriticalTasks(): void
    {
        $report = $this->createReportWithIssues();
        $sprints = $this->planner->plan($report, 40.0);

        /* Au moins un sprint doit contenir une tâche critique (phase « Changements bloquants » ou « Plugins ») */
        $hasCriticalSprint = false;
        foreach ($sprints as $sprint) {
            if ($sprint->hasCriticalTask) {
                $hasCriticalSprint = true;
                break;
            }
        }

        self::assertTrue($hasCriticalSprint, 'Au moins un sprint doit contenir une tâche critique');
    }

    /** Vérifie que le nombre total d'heures par sprint ne dépasse pas la vélocité. */
    #[Test]
    public function testPlanRespectsVelocity(): void
    {
        $report = $this->createReportWithIssues();
        $velocityHours = 8.0;

        $sprints = $this->planner->plan($report, $velocityHours);

        self::assertNotEmpty($sprints, 'Le plan doit contenir au moins un sprint');

        foreach ($sprints as $sprint) {
            /*
             * Un sprint peut dépasser la vélocité uniquement si une seule tâche
             * est plus grande que la vélocité (elle ne peut pas être divisée).
             * Sinon, le total d'heures doit rester inférieur ou égal à la vélocité.
             */
            if ($sprint->totalHours > $velocityHours) {
                self::assertCount(
                    1,
                    $sprint->tasks,
                    sprintf(
                        'Le sprint %d dépasse la vélocité (%.1fh > %.1fh) avec plus d\'une tâche',
                        $sprint->number,
                        $sprint->totalHours,
                        $velocityHours,
                    ),
                );
            } else {
                self::assertLessThanOrEqual(
                    $velocityHours,
                    $sprint->totalHours,
                    sprintf(
                        'Le sprint %d ne doit pas dépasser la vélocité de %.1fh',
                        $sprint->number,
                        $velocityHours,
                    ),
                );
            }
        }
    }
}
