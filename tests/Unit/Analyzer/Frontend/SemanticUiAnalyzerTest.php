<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Frontend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Frontend\SemanticUiAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur Semantic UI dans les templates Twig.
 * Verifie la detection des classes CSS Semantic UI et l'estimation du temps.
 */
final class SemanticUiAnalyzerTest extends TestCase
{
    /** Chemin vers le repertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /**
     * Cree un rapport de migration pointant vers le projet de fixture specifie.
     */
    private function createReportForFixture(string $projectName): MigrationReport
    {
        /* Resolution du chemin reel pour eviter les problemes de chemins relatifs */
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le repertoire de fixture "%s" est introuvable.', $projectName));

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: $path,
        );
    }

    /**
     * Verifie que supports() retourne true pour un projet contenant des templates Twig.
     * Le projet complexe possede un repertoire templates/ avec des fichiers .twig.
     */
    #[Test]
    public function testSupportsReturnsTrueForProjectWithTemplates(): void
    {
        $analyzer = new SemanticUiAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        /* Le projet complexe contient des templates Twig */
        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que supports() retourne false pour un projet sans templates.
     * Utilise un repertoire temporaire vide pour simuler un projet sans templates.
     */
    #[Test]
    public function testSupportsReturnsFalseForEmptyProject(): void
    {
        $analyzer = new SemanticUiAnalyzer();

        /* Creation d'un repertoire temporaire vide pour simuler un projet vide */
        $tempDir = sys_get_temp_dir() . '/sylius-test-no-templates-' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            $report = new MigrationReport(
                startedAt: new \DateTimeImmutable(),
                detectedSyliusVersion: null,
                targetVersion: '2.2',
                projectPath: $tempDir,
            );

            /* Un projet sans repertoire templates/ ne doit pas etre supporte */
            self::assertFalse($analyzer->supports($report));
        } finally {
            /* Nettoyage du repertoire temporaire */
            rmdir($tempDir);
        }
    }

    /**
     * Verifie que l'analyseur detecte les classes CSS Semantic UI dans les templates.
     * Le projet complexe contient des classes comme "ui container", "ui segment", etc.
     */
    #[Test]
    public function testDetectsSemanticUiClasses(): void
    {
        $analyzer = new SemanticUiAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problemes individuels par fichier (pas le resume) */
        $fileIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Classes Semantic UI detectees dans'),
        );

        self::assertNotEmpty($fileIssues, 'Les classes Semantic UI auraient du etre detectees.');

        /* Verification que les motifs Semantic UI sont mentionnes dans le detail */
        $allDetails = implode(' ', array_map(
            static fn ($issue): string => $issue->getDetail(),
            $fileIssues,
        ));

        /* Le projet complexe utilise "ui container" et "ui segment" dans ses templates */
        self::assertStringContainsString('ui container', $allDetails);
        self::assertStringContainsString('ui segment', $allDetails);
    }

    /**
     * Verifie que les problemes sont de severite WARNING dans la categorie FRONTEND.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $analyzer = new SemanticUiAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Tous les problemes detectes doivent etre des WARNING dans la categorie FRONTEND */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
            self::assertSame(Category::FRONTEND, $issue->getCategory());
        }
    }

    /**
     * Verifie l'estimation d'une heure (60 minutes) par fichier impacte.
     * Chaque fichier contenant des classes Semantic UI est estime a MINUTES_PER_FILE (60).
     */
    #[Test]
    public function testEstimatesOneHourPerFile(): void
    {
        $analyzer = new SemanticUiAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problemes individuels par fichier (pas le resume global) */
        $fileIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Classes Semantic UI detectees dans'),
        );

        /* Chaque fichier individuel doit etre estime a 60 minutes */
        foreach ($fileIssues as $issue) {
            self::assertSame(60, $issue->getEstimatedMinutes());
        }
    }

    /**
     * Verifie que getName() retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new SemanticUiAnalyzer();

        self::assertSame('Semantic UI', $analyzer->getName());
    }

    /**
     * Verifie qu'un probleme de synthese est cree avec le nombre total de fichiers impactes.
     * Le resume global doit compter tous les fichiers contenant Semantic UI.
     */
    #[Test]
    public function testCreatesSummaryIssueWithFileCount(): void
    {
        $analyzer = new SemanticUiAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche du probleme de synthese */
        $summaryIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'fichier(s) Twig contenant des classes Semantic UI'),
        );

        self::assertCount(1, $summaryIssues, 'Un seul probleme de synthese doit etre cree.');
    }
}
