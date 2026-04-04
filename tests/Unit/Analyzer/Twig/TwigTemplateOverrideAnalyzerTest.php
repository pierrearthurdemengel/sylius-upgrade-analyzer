<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Twig;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig\TwigTemplateOverrideAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de surcharges de templates Twig.
 * Utilise les projets de fixtures pour vérifier la détection et l'estimation.
 */
final class TwigTemplateOverrideAnalyzerTest extends TestCase
{
    /** Chemin vers le répertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /**
     * Crée un rapport de migration pointant vers le projet de fixture spécifié.
     */
    private function createReportForFixture(string $projectName): MigrationReport
    {
        /* Résolution du chemin réel pour éviter les problèmes de chemins relatifs */
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le répertoire de fixture "%s" est introuvable.', $projectName));

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: $path,
        );
    }

    /**
     * Vérifie qu'aucun problème n'est détecté pour le projet trivial (pas de surcharges).
     * L'analyseur ne supporte pas ce projet et ne doit générer aucun problème.
     */
    #[Test]
    public function testReturnsNoIssuesForTrivialProject(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        /* Le projet trivial ne contient aucun répertoire de surcharge */
        self::assertFalse($analyzer->supports($report));

        /* Vérification que le rapport reste vide sans exécution de l'analyseur */
        self::assertCount(0, $report->getIssues());
    }

    /**
     * Vérifie la détection de la surcharge du layout dans le projet modéré.
     */
    #[Test]
    public function testDetectsLayoutOverrideInModerateProject(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Recherche du problème concernant le layout */
        $layoutIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'layout.html.twig'),
        );

        self::assertNotEmpty($layoutIssues, 'La surcharge du layout aurait dû être détectée.');
    }

    /**
     * Vérifie la détection de la surcharge Product/show dans le projet modéré.
     */
    #[Test]
    public function testDetectsProductShowOverrideInModerateProject(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Recherche du problème concernant Product/show */
        $productIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Product/show.html.twig'),
        );

        self::assertNotEmpty($productIssues, 'La surcharge de Product/show.html.twig aurait dû être détectée.');
    }

    /**
     * Vérifie le nombre total de problèmes détectés dans le projet modéré.
     */
    #[Test]
    public function testCountsCorrectIssuesForModerateProject(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Le projet modéré contient 2 templates surchargés */
        self::assertCount(2, $report->getIssues());
    }

    /**
     * Vérifie la détection de toutes les surcharges dans le projet complexe (8 templates).
     */
    #[Test]
    public function testDetectsAllOverridesInComplexProject(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Le projet complexe contient 8 templates surchargés */
        self::assertCount(8, $report->getIssues());
    }

    /**
     * Vérifie que le template complexe layout.html.twig est estimé à 2 heures (120 minutes).
     * Le layout du projet complexe contient du JS inline, stimulus et encore_entry.
     */
    #[Test]
    public function testEstimatesComplexTemplateAtTwoHours(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche du problème concernant le layout complexe */
        $layoutIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'SyliusShopBundle/layout.html.twig'),
        ));

        self::assertNotEmpty($layoutIssues, 'La surcharge du layout aurait dû être détectée.');

        /* Le layout complexe doit être estimé à 120 minutes (2 heures) */
        self::assertSame(120, $layoutIssues[0]->getEstimatedMinutes());
    }

    /**
     * Vérifie que le template standard Product/show est estimé à 1 heure (60 minutes).
     * Le show du projet modéré contient des includes (complexité standard).
     */
    #[Test]
    public function testEstimatesStandardTemplateAtOneHour(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Recherche du problème concernant Product/show */
        $showIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Product/show.html.twig'),
        ));

        self::assertNotEmpty($showIssues, 'La surcharge de Product/show.html.twig aurait dû être détectée.');

        /* Le template standard doit être estimé à 60 minutes (1 heure) */
        self::assertSame(60, $showIssues[0]->getEstimatedMinutes());
    }

    /**
     * Vérifie que supports retourne false pour un projet sans templates Sylius.
     */
    #[Test]
    public function testSupportsReturnsFalseForNonSyliusProject(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();

        /* Création d'un répertoire temporaire vide pour simuler un projet sans templates */
        $tempDir = sys_get_temp_dir() . '/sylius-test-empty-' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            $report = new MigrationReport(
                startedAt: new \DateTimeImmutable(),
                detectedSyliusVersion: null,
                targetVersion: '2.2',
                projectPath: $tempDir,
            );

            /* Un projet sans répertoire de surcharge ne doit pas être supporté */
            self::assertFalse($analyzer->supports($report));
        } finally {
            /* Nettoyage du répertoire temporaire */
            rmdir($tempDir);
        }
    }

    /**
     * Vérifie que getName retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpectedValue(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();

        self::assertSame('Twig Template Override', $analyzer->getName());
    }

    /**
     * Vérifie que tous les problèmes détectés sont de sévérité WARNING.
     */
    #[Test]
    public function testAllIssuesAreWarnings(): void
    {
        $analyzer = new TwigTemplateOverrideAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Tous les problèmes de surcharge de template doivent être des WARNING */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
        }
    }
}
