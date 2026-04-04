<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Frontend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Frontend\JQueryAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur jQuery dans les fichiers JavaScript.
 * Verifie la detection des usages jQuery et la classification simple/complexe.
 */
final class JQueryAnalyzerTest extends TestCase
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
     * Verifie que supports() retourne true pour un projet avec des fichiers JS.
     * Le projet complexe possede un repertoire assets/js/ avec des fichiers .js.
     */
    #[Test]
    public function testSupportsReturnsTrueForProjectWithJsFiles(): void
    {
        $analyzer = new JQueryAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        /* Le projet complexe contient des fichiers JS dans assets/ */
        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que supports() retourne false pour un projet sans fichiers JS.
     * Le projet trivial ne contient aucun repertoire assets/ ni public/.
     */
    #[Test]
    public function testSupportsReturnsFalseForProjectWithNoJs(): void
    {
        $analyzer = new JQueryAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        /* Le projet trivial n'a pas de repertoire assets/ ni public/ */
        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte les usages jQuery dans les fichiers JS.
     * Le projet complexe contient des appels $(), $.ajax, $(document).ready, etc.
     */
    #[Test]
    public function testDetectsJQueryUsages(): void
    {
        $analyzer = new JQueryAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problemes individuels par fichier (pas le resume) */
        $fileIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'usage(s) jQuery detecte(s)'),
        );

        /* Le projet complexe contient 6 fichiers JS, tous avec du jQuery */
        self::assertNotEmpty($fileIssues, 'Les usages jQuery auraient du etre detectes.');
        self::assertGreaterThanOrEqual(6, count($fileIssues), 'Tous les fichiers JS avec jQuery doivent etre detectes.');
    }

    /**
     * Verifie que les fichiers avec 5 usages ou plus sont classifies comme complexes.
     * Le seuil de complexite est de 5 usages jQuery par fichier.
     */
    #[Test]
    public function testClassifiesComplexFilesCorrectly(): void
    {
        $analyzer = new JQueryAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des fichiers complexes (120 minutes) */
        $complexIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'fichier complexe'),
        );

        /* Recherche des fichiers simples (30 minutes) */
        $simpleIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'fichier simple'),
        );

        /* Le projet complexe a des fichiers avec beaucoup de jQuery (complexes)
         * et certains avec moins de 5 usages (simples) */
        self::assertNotEmpty(
            array_merge($complexIssues, $simpleIssues),
            'Les fichiers doivent etre classifies en simple ou complexe.',
        );

        /* Verification des estimations : simple = 30 min, complexe = 120 min */
        foreach ($complexIssues as $issue) {
            self::assertSame(120, $issue->getEstimatedMinutes());
        }

        foreach ($simpleIssues as $issue) {
            self::assertSame(30, $issue->getEstimatedMinutes());
        }
    }

    /**
     * Verifie que les problemes sont de severite WARNING dans la categorie FRONTEND.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $analyzer = new JQueryAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Tous les problemes detectes doivent etre des WARNING dans la categorie FRONTEND */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
            self::assertSame(Category::FRONTEND, $issue->getCategory());
        }
    }

    /**
     * Verifie que getName() retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new JQueryAnalyzer();

        self::assertSame('jQuery', $analyzer->getName());
    }

    /**
     * Verifie qu'un probleme de synthese est cree avec le decompte simple/complexe.
     * Le resume global doit compter les fichiers simples et complexes separement.
     */
    #[Test]
    public function testCreatesSummaryIssueWithFileCount(): void
    {
        $analyzer = new JQueryAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche du probleme de synthese */
        $summaryIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'fichier(s) JavaScript/TypeScript utilisant jQuery'),
        );

        self::assertCount(1, $summaryIssues, 'Un seul probleme de synthese doit etre cree.');
    }
}
