<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Api\ApiEndpointRestructureAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de restructuration des endpoints API.
 * Verifie la detection des anciens chemins d'endpoints dans les fichiers PHP,
 * Twig, JS et de configuration.
 */
final class ApiEndpointRestructureAnalyzerTest extends TestCase
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
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();

        self::assertSame('API Endpoint Restructure', $analyzer->getName());
    }

    /**
     * Verifie que supports retourne false pour un projet sans references aux anciens endpoints.
     */
    #[Test]
    public function testSupportsReturnsFalseForTrivialProject(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true pour un projet contenant des anciens endpoints.
     */
    #[Test]
    public function testSupportsReturnsTrueForComplexProject(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte les anciens endpoints dans les fichiers PHP.
     */
    #[Test]
    public function testDetectsOldEndpointsInPhpFiles(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche d'issues mentionnant des endpoints dans src/ */
        $phpIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'src/')
                && str_contains($issue->getMessage(), 'Ancien endpoint API'),
        );

        self::assertNotEmpty($phpIssues, 'Des anciens endpoints auraient du etre detectes dans les fichiers PHP.');
    }

    /**
     * Verifie que l'analyseur detecte les anciens endpoints dans les fichiers JS.
     */
    #[Test]
    public function testDetectsOldEndpointsInJsFiles(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche d'issues mentionnant des endpoints dans assets/ */
        $jsIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'assets/')
                && str_contains($issue->getMessage(), 'Ancien endpoint API'),
        );

        self::assertNotEmpty($jsIssues, 'Des anciens endpoints auraient du etre detectes dans les fichiers JS.');
    }

    /**
     * Verifie que l'analyseur detecte les anciens noms de route dans les fichiers config.
     */
    #[Test]
    public function testDetectsOldRouteNameInConfigFiles(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche d'issues mentionnant api_platform.action.post_item */
        $routeIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Ancien nom de route API Platform'),
        );

        self::assertNotEmpty($routeIssues, 'L\'ancien nom de route API Platform aurait du etre detecte.');
    }

    /**
     * Verifie que toutes les issues sont de severite BREAKING et categorie API.
     */
    #[Test]
    public function testAllIssuesAreBreakingInApiCategory(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::BREAKING, $issue->getSeverity());
            self::assertSame(Category::API, $issue->getCategory());
        }
    }

    /**
     * Verifie que le projet majeur genere plus d'issues que le projet complexe.
     */
    #[Test]
    public function testMajorProjectGeneratesMoreIssues(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();

        $complexReport = $this->createReportForFixture('project-complex');
        $analyzer->analyze($complexReport);
        $complexCount = count($complexReport->getIssues());

        $majorReport = $this->createReportForFixture('project-major');
        $analyzer->analyze($majorReport);
        $majorCount = count($majorReport->getIssues());

        self::assertGreaterThan(
            $complexCount,
            $majorCount,
            'Le projet majeur devrait generer plus d\'issues que le projet complexe.',
        );
    }

    /**
     * Verifie l'estimation du temps pour le projet complexe (60 min par reference).
     */
    #[Test]
    public function testEstimatesCorrectMinutesPerReference(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche de l'issue de synthese */
        $summaryIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'reference(s) a d\'anciens endpoints'),
        ));

        self::assertNotEmpty($summaryIssues);

        /* L'estimation doit etre un multiple de 60 */
        $minutes = $summaryIssues[0]->getEstimatedMinutes();
        self::assertSame(0, $minutes % 60, 'L\'estimation doit etre un multiple de 60 minutes.');
        self::assertGreaterThan(0, $minutes);
    }

    /**
     * Verifie que les anciens endpoints appeles depuis un theme sont detectes.
     */
    #[Test]
    public function testDetectsOldEndpointInThemeTemplates(): void
    {
        $analyzer = new ApiEndpointRestructureAnalyzer();
        $report = $this->createReportForFixture('project-themed');

        self::assertTrue($analyzer->supports($report));
        $analyzer->analyze($report);

        /* Les separateurs suivent le systeme de fichiers : normalisation pour Windows */
        $messages = array_map(
            static fn ($issue): string => str_replace('\\', '/', $issue->getMessage()),
            $report->getIssues(),
        );

        self::assertContains(
            'Ancien endpoint API detecte dans themes/BootstrapTheme/templates/bundles/SyliusShopBundle/Product/show.html.twig '
            . 'ligne 8 : /api/v2/shop/reset-password-requests',
            $messages,
        );
    }
}
