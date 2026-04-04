<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Frontend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Frontend\WebpackEncoreAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de configuration Webpack Encore.
 * Verifie la detection de webpack.config.js, package.json et l'estimation du temps.
 */
final class WebpackEncoreAnalyzerTest extends TestCase
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
     * Verifie que supports() retourne true pour un projet avec webpack.config.js.
     * Le projet complexe contient un fichier webpack.config.js a la racine.
     */
    #[Test]
    public function testSupportsReturnsTrueForProjectWithWebpack(): void
    {
        $analyzer = new WebpackEncoreAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        /* Le projet complexe contient webpack.config.js */
        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que supports() retourne false pour un projet sans webpack.config.js.
     * Le projet trivial ne contient ni webpack.config.js ni package.json.
     */
    #[Test]
    public function testSupportsReturnsFalseForProjectWithoutWebpack(): void
    {
        $analyzer = new WebpackEncoreAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        /* Le projet trivial n'a ni webpack.config.js ni package.json */
        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte la configuration Encore dans webpack.config.js.
     * Le projet complexe utilise Encore avec les points d'entree 'app' et 'admin'.
     */
    #[Test]
    public function testDetectsEncoreConfiguration(): void
    {
        $analyzer = new WebpackEncoreAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche du probleme concernant webpack.config.js */
        $webpackIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Configuration Webpack Encore detectee'),
        );

        self::assertNotEmpty($webpackIssues, 'La configuration Encore aurait du etre detectee dans webpack.config.js.');

        /* Verification que les points d'entree sont mentionnes dans le detail */
        $webpackIssue = array_values($webpackIssues)[0];
        self::assertStringContainsString('app', $webpackIssue->getDetail());
        self::assertStringContainsString('admin', $webpackIssue->getDetail());

        /* Recherche du probleme concernant package.json */
        $packageIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), '@symfony/webpack-encore'),
        );

        self::assertNotEmpty($packageIssues, 'La dependance @symfony/webpack-encore aurait du etre detectee dans package.json.');
    }

    /**
     * Verifie l'estimation de 3 heures (180 minutes) pour la migration du systeme de build.
     * L'issue globale de migration est estimee a MINUTES_BUILD_MIGRATION (180).
     */
    #[Test]
    public function testEstimatesThreeHoursForMigration(): void
    {
        $analyzer = new WebpackEncoreAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche du probleme global de migration */
        $migrationIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Migration du systeme de build'),
        ));

        self::assertNotEmpty($migrationIssues);

        /* L'issue globale doit etre estimee a 180 minutes (3 heures) */
        self::assertSame(180, $migrationIssues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que getName() retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new WebpackEncoreAnalyzer();

        self::assertSame('Webpack Encore', $analyzer->getName());
    }

    /**
     * Verifie que tous les problemes sont de severite WARNING et categorie FRONTEND.
     */
    #[Test]
    public function testAllIssuesAreWarningsInFrontendCategory(): void
    {
        $analyzer = new WebpackEncoreAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Tous les problemes doivent etre des WARNING dans la categorie FRONTEND */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
            self::assertSame(Category::FRONTEND, $issue->getCategory());
        }
    }
}
