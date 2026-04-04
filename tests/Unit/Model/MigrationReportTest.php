<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Complexity;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour la classe MigrationReport.
 * Vérifie l'agrégation, le filtrage et les calculs de complexité.
 */
final class MigrationReportTest extends TestCase
{
    /**
     * Crée un rapport de migration vide pour les tests.
     */
    private function createReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: '/tmp/test-project',
        );
    }

    /**
     * Crée un problème avec les paramètres spécifiés.
     */
    private function createIssue(
        Severity $severity = Severity::WARNING,
        Category $category = Category::TWIG,
        int $estimatedMinutes = 60,
    ): MigrationIssue {
        return new MigrationIssue(
            severity: $severity,
            category: $category,
            analyzer: 'test-analyzer',
            message: 'Message de test',
            detail: 'Détail de test',
            suggestion: 'Suggestion de test',
            estimatedMinutes: $estimatedMinutes,
        );
    }

    /**
     * Vérifie que addIssue stocke correctement un problème dans le rapport.
     */
    #[Test]
    public function testAddIssueStoresIssue(): void
    {
        $report = $this->createReport();
        $issue = $this->createIssue();

        $report->addIssue($issue);

        /* Le rapport doit contenir exactement un problème */
        self::assertCount(1, $report->getIssues());
        self::assertSame($issue, $report->getIssues()[0]);
    }

    /**
     * Vérifie que la complexité est TRIVIAL pour un total inférieur à 20 heures.
     * 10h = 600 minutes en WARNING => TRIVIAL
     */
    #[Test]
    public function testGetComplexityReturnsTrivialForLowHours(): void
    {
        $report = $this->createReport();

        /* 600 minutes = 10 heures => ceil(600/30)*0.5 = 10.0h => TRIVIAL */
        $report->addIssue($this->createIssue(Severity::WARNING, Category::TWIG, 600));

        self::assertSame(Complexity::TRIVIAL, $report->getComplexity());
    }

    /**
     * Vérifie que la complexité est MODERATE pour un total entre 20 et 80 heures.
     * 2400 minutes = 40 heures => MODERATE
     */
    #[Test]
    public function testGetComplexityReturnsModerateForMediumHours(): void
    {
        $report = $this->createReport();

        /* 2400 minutes = 40 heures => ceil(2400/30)*0.5 = 40.0h => MODERATE */
        $report->addIssue($this->createIssue(Severity::BREAKING, Category::TWIG, 2400));

        self::assertSame(Complexity::MODERATE, $report->getComplexity());
    }

    /**
     * Vérifie que la complexité est COMPLEX pour un total entre 80 et 200 heures.
     * 6000 minutes = 100 heures => COMPLEX
     */
    #[Test]
    public function testGetComplexityReturnsComplexForHighHours(): void
    {
        $report = $this->createReport();

        /* 6000 minutes = 100 heures => ceil(6000/30)*0.5 = 100.0h => COMPLEX */
        $report->addIssue($this->createIssue(Severity::WARNING, Category::TWIG, 6000));

        self::assertSame(Complexity::COMPLEX, $report->getComplexity());
    }

    /**
     * Vérifie que la complexité est MAJOR pour un total supérieur ou égal à 200 heures.
     * 15000 minutes = 250 heures => MAJOR
     */
    #[Test]
    public function testGetComplexityReturnsMajorForVeryHighHours(): void
    {
        $report = $this->createReport();

        /* 15000 minutes = 250 heures => ceil(15000/30)*0.5 = 250.0h => MAJOR */
        $report->addIssue($this->createIssue(Severity::BREAKING, Category::TWIG, 15000));

        self::assertSame(Complexity::MAJOR, $report->getComplexity());
    }

    /**
     * Vérifie que les problèmes de type SUGGESTION sont ignorés dans le calcul de complexité.
     */
    #[Test]
    public function testGetComplexityIgnoresSuggestionIssues(): void
    {
        $report = $this->createReport();

        /* Ajout d'un gros volume en SUGGESTION : ne doit pas être comptabilisé */
        $report->addIssue($this->createIssue(Severity::SUGGESTION, Category::TWIG, 60000));

        /* Ajout d'un petit volume en WARNING : seul celui-ci compte */
        $report->addIssue($this->createIssue(Severity::WARNING, Category::TWIG, 300));

        /* 300 minutes => ceil(300/30)*0.5 = 5.0h => TRIVIAL */
        self::assertSame(Complexity::TRIVIAL, $report->getComplexity());
    }

    /**
     * Vérifie l'arrondi au demi-heure supérieur pour le total d'heures estimées.
     * 45 minutes => ceil(45/30)*0.5 = ceil(1.5)*0.5 = 2*0.5 = 1.0h
     */
    #[Test]
    public function testGetTotalEstimatedHoursRoundsToHalfHour(): void
    {
        $report = $this->createReport();

        /* 45 minutes => ceil(45/30) = 2 => 2 * 0.5 = 1.0h */
        $report->addIssue($this->createIssue(Severity::WARNING, Category::TWIG, 45));

        self::assertSame(1.0, $report->getTotalEstimatedHours());
    }

    /**
     * Vérifie que getEstimatedHoursByCategory regroupe correctement par catégorie.
     */
    #[Test]
    public function testGetEstimatedHoursByCategoryGroupsCorrectly(): void
    {
        $report = $this->createReport();

        /* Ajout de problèmes dans différentes catégories */
        $report->addIssue($this->createIssue(Severity::WARNING, Category::TWIG, 120));
        $report->addIssue($this->createIssue(Severity::WARNING, Category::TWIG, 60));
        $report->addIssue($this->createIssue(Severity::BREAKING, Category::PLUGIN, 90));

        $hoursByCategory = $report->getEstimatedHoursByCategory();

        /* Twig : 180 minutes => ceil(180/30)*0.5 = 3.0h */
        self::assertSame(3.0, $hoursByCategory['twig']);

        /* Plugin : 90 minutes => ceil(90/30)*0.5 = 1.5h */
        self::assertSame(1.5, $hoursByCategory['plugin']);

        /* Twig doit apparaître en premier (tri décroissant) */
        $keys = array_keys($hoursByCategory);
        self::assertSame('twig', $keys[0]);
    }

    /**
     * Vérifie que getIssuesBySeverity filtre correctement par sévérité.
     */
    #[Test]
    public function testGetIssuesBySeverityFiltersCorrectly(): void
    {
        $report = $this->createReport();

        $breaking = $this->createIssue(Severity::BREAKING, Category::TWIG, 60);
        $warning = $this->createIssue(Severity::WARNING, Category::TWIG, 30);
        $suggestion = $this->createIssue(Severity::SUGGESTION, Category::TWIG, 10);

        $report->addIssue($breaking);
        $report->addIssue($warning);
        $report->addIssue($suggestion);

        /* Vérification du filtrage par BREAKING */
        $breakingIssues = $report->getIssuesBySeverity(Severity::BREAKING);
        self::assertCount(1, $breakingIssues);
        self::assertSame($breaking, $breakingIssues[0]);

        /* Vérification du filtrage par WARNING */
        $warningIssues = $report->getIssuesBySeverity(Severity::WARNING);
        self::assertCount(1, $warningIssues);
        self::assertSame($warning, $warningIssues[0]);
    }

    /**
     * Vérifie que getIssuesByCategory filtre correctement par catégorie.
     */
    #[Test]
    public function testGetIssuesByCategoryFiltersCorrectly(): void
    {
        $report = $this->createReport();

        $twig = $this->createIssue(Severity::WARNING, Category::TWIG, 60);
        $plugin = $this->createIssue(Severity::WARNING, Category::PLUGIN, 30);

        $report->addIssue($twig);
        $report->addIssue($plugin);

        /* Vérification du filtrage par catégorie TWIG */
        $twigIssues = $report->getIssuesByCategory(Category::TWIG);
        self::assertCount(1, $twigIssues);
        self::assertSame($twig, $twigIssues[0]);

        /* Vérification du filtrage par catégorie PLUGIN */
        $pluginIssues = $report->getIssuesByCategory(Category::PLUGIN);
        self::assertCount(1, $pluginIssues);
        self::assertSame($plugin, $pluginIssues[0]);
    }

    /**
     * Vérifie que complete() enregistre la date de fin.
     */
    #[Test]
    public function testCompleteSetsDuration(): void
    {
        $report = $this->createReport();

        /* Avant l'appel à complete, completedAt doit être null */
        self::assertNull($report->getCompletedAt());

        $report->complete();

        /* Après l'appel, completedAt doit être défini */
        self::assertNotNull($report->getCompletedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $report->getCompletedAt());
    }

    /**
     * Vérifie que getDetectedSyliusVersion retourne null par défaut.
     */
    #[Test]
    public function testGetDetectedSyliusVersionReturnsNull(): void
    {
        $report = $this->createReport();

        self::assertNull($report->getDetectedSyliusVersion());
    }

    /**
     * Vérifie que le total d'heures est zéro pour un rapport vide.
     */
    #[Test]
    public function testGetTotalEstimatedHoursReturnsZeroForEmptyReport(): void
    {
        $report = $this->createReport();

        self::assertSame(0.0, $report->getTotalEstimatedHours());
    }

    /**
     * Vérifie que getProjectPath retourne le chemin du projet.
     */
    #[Test]
    public function testGetProjectPathReturnsCorrectValue(): void
    {
        $report = $this->createReport();

        self::assertSame('/tmp/test-project', $report->getProjectPath());
    }
}
