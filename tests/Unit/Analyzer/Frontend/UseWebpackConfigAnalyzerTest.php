<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Frontend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Frontend\UseWebpackConfigAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de la configuration use_webpack.
 * Verifie la detection de la cle use_webpack dans les fichiers YAML
 * et dans les templates Twig.
 */
final class UseWebpackConfigAnalyzerTest extends TestCase
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
        $analyzer = new UseWebpackConfigAnalyzer();

        self::assertSame('Use Webpack Config', $analyzer->getName());
    }

    /**
     * Verifie que supports retourne false pour un projet sans use_webpack.
     */
    #[Test]
    public function testSupportsReturnsFalseForTrivialProject(): void
    {
        $analyzer = new UseWebpackConfigAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true pour un projet avec use_webpack dans la config.
     */
    #[Test]
    public function testSupportsReturnsTrueForModerateProject(): void
    {
        $analyzer = new UseWebpackConfigAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection de use_webpack dans les fichiers YAML de configuration.
     */
    #[Test]
    public function testDetectsUseWebpackInYamlConfig(): void
    {
        $analyzer = new UseWebpackConfigAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Recherche d'issues mentionnant la configuration use_webpack */
        $configIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Configuration use_webpack'),
        );

        self::assertNotEmpty($configIssues, 'La configuration use_webpack aurait du etre detectee dans le YAML.');
    }

    /**
     * Verifie la detection de use_webpack dans les templates Twig.
     */
    #[Test]
    public function testDetectsUseWebpackInTwigTemplates(): void
    {
        $analyzer = new UseWebpackConfigAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche d'issues mentionnant use_webpack dans les templates */
        $twigIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Variable use_webpack'),
        );

        self::assertNotEmpty($twigIssues, 'La variable use_webpack aurait du etre detectee dans les templates Twig.');
    }

    /**
     * Verifie que toutes les issues sont de severite BREAKING et categorie FRONTEND.
     */
    #[Test]
    public function testAllIssuesAreBreakingInFrontendCategory(): void
    {
        $analyzer = new UseWebpackConfigAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::BREAKING, $issue->getSeverity());
            self::assertSame(Category::FRONTEND, $issue->getCategory());
        }
    }

    /**
     * Verifie que le projet majeur detecte plus de references que le projet modere.
     */
    #[Test]
    public function testMajorProjectDetectsMoreReferences(): void
    {
        $analyzer = new UseWebpackConfigAnalyzer();

        $moderateReport = $this->createReportForFixture('project-moderate');
        $analyzer->analyze($moderateReport);
        $moderateCount = count($moderateReport->getIssues());

        $majorReport = $this->createReportForFixture('project-major');
        $analyzer->analyze($majorReport);
        $majorCount = count($majorReport->getIssues());

        self::assertGreaterThan(
            $moderateCount,
            $majorCount,
            'Le projet majeur devrait detecter plus de references que le projet modere.',
        );
    }

    /**
     * Verifie l'estimation du temps (60 min par reference).
     */
    #[Test]
    public function testEstimatesCorrectMinutesPerReference(): void
    {
        $analyzer = new UseWebpackConfigAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche de l'issue de synthese */
        $summaryIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'reference(s) a use_webpack'),
        ));

        self::assertNotEmpty($summaryIssues);

        /* L'estimation doit etre un multiple de 60 */
        $minutes = $summaryIssues[0]->getEstimatedMinutes();
        self::assertSame(0, $minutes % 60, 'L\'estimation doit etre un multiple de 60 minutes.');
        self::assertGreaterThan(0, $minutes);
    }
}
