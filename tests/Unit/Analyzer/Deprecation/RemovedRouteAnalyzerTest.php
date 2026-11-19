<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\RemovedRouteAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des routes supprimees.
 * Verifie la detection des references aux routes Sylius supprimees dans les fichiers PHP et Twig.
 */
final class RemovedRouteAnalyzerTest extends TestCase
{
    /** Chemin vers le repertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /**
     * Cree un rapport de migration pointant vers le projet de fixture specifie.
     */
    private function createReportForFixture(string $projectName): MigrationReport
    {
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le repertoire de fixture "%s" est introuvable.', $projectName));

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.0',
            projectPath: $path,
        );
    }

    /**
     * Verifie que supports retourne false pour le projet trivial sans routes supprimees.
     */
    #[Test]
    public function testSupportsReturnsFalseForTrivialProject(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true pour le projet moderate avec des routes supprimees.
     */
    #[Test]
    public function testSupportsReturnsTrueForModerateProject(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte les routes dans les fichiers PHP.
     */
    #[Test]
    public function testDetectsRoutesInPhpFiles(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Le fichier DashboardController.php contient une reference a sylius_admin_dashboard_statistics */
        $phpIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sylius_admin_dashboard_statistics')
                && str_contains($issue->getMessage(), 'referencee dans'),
        );

        self::assertNotEmpty($phpIssues);
    }

    /**
     * Verifie que l'analyseur detecte les routes dans les templates Twig via path() et url().
     */
    #[Test]
    public function testDetectsRoutesInTwigTemplates(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Les templates contiennent des path('sylius_admin_ajax_generate_product_slug') */
        $twigIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'utilisee dans le template'),
        );

        self::assertNotEmpty($twigIssues);
    }

    /**
     * Verifie que le projet major genere davantage de problemes que le projet moderate.
     */
    #[Test]
    public function testMajorProjectHasMoreIssues(): void
    {
        $analyzer = new RemovedRouteAnalyzer();

        $moderateReport = $this->createReportForFixture('project-moderate');
        $analyzer->analyze($moderateReport);
        $moderateCount = count($moderateReport->getIssues());

        $majorReport = $this->createReportForFixture('project-major');
        $analyzer->analyze($majorReport);
        $majorCount = count($majorReport->getIssues());

        self::assertGreaterThan($moderateCount, $majorCount);
    }

    /**
     * Verifie que tous les problemes sont de severite BREAKING.
     */
    #[Test]
    public function testCreatesBreakingIssues(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::BREAKING, $issue->getSeverity());
        }
    }

    /**
     * Verifie que la categorie est DEPRECATION.
     */
    #[Test]
    public function testCategoryIsDeprecation(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Category::DEPRECATION, $issue->getCategory());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new RemovedRouteAnalyzer();

        self::assertSame('Removed Route', $analyzer->getName());
    }

    /**
     * Verifie que l'estimation est de 30 minutes par route.
     */
    #[Test]
    public function testEstimatesThirtyMinutesPerRoute(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'reference(s) a des routes supprimees'),
        ));

        self::assertNotEmpty($globalIssues, 'Le probleme global de synthese devrait etre present.');
        /* Au moins 1 reference x 30 minutes */
        self::assertGreaterThanOrEqual(30, $globalIssues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que les routes supprimees appelees depuis un theme sont detectees.
     */
    #[Test]
    public function testDetectsRemovedRouteInTheme(): void
    {
        $analyzer = new RemovedRouteAnalyzer();
        $report = $this->createReportForFixture('project-themed');

        self::assertTrue($analyzer->supports($report));
        $analyzer->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());

        self::assertContains(
            'Route supprimee "sylius_shop_partial_taxon_index_by_code" utilisee dans le template '
            . 'themes/BootstrapTheme/templates/bundles/SyliusShopBundle/layout.html.twig',
            $messages,
        );
    }
}
