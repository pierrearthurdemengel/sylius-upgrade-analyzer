<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\SonataBlockEventAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des evenements de blocs Sonata.
 * Verifie la detection de sonata_block_render_event, sylius_template_event et BlockEventListener.
 */
final class SonataBlockEventAnalyzerTest extends TestCase
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
     * Verifie que supports retourne false pour le projet trivial sans blocs Sonata.
     */
    #[Test]
    public function testSupportsReturnsFalseForTrivialProject(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true pour le projet moderate avec sonata_block_render_event.
     */
    #[Test]
    public function testSupportsReturnsTrueForModerateProject(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte les appels a sonata_block_render_event().
     */
    #[Test]
    public function testDetectsSonataBlockRenderEvent(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        $sonataIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sonata_block_render_event'),
        );

        self::assertNotEmpty($sonataIssues);
    }

    /**
     * Verifie que l'analyseur detecte les appels a sylius_template_event().
     */
    #[Test]
    public function testDetectsSyliusTemplateEvent(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        $templateEventIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sylius_template_event'),
        );

        self::assertNotEmpty($templateEventIssues);
    }

    /**
     * Verifie que l'analyseur detecte les references a BlockEventListener dans les fichiers YAML.
     */
    #[Test]
    public function testDetectsBlockEventListenerInYaml(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        $listenerIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'BlockEventListener'),
        );

        self::assertNotEmpty($listenerIssues);
    }

    /**
     * Verifie que le projet major genere plus de problemes que le projet moderate.
     */
    #[Test]
    public function testMajorProjectHasMoreIssues(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();

        $moderateReport = $this->createReportForFixture('project-moderate');
        $analyzer->analyze($moderateReport);
        $moderateCount = count($moderateReport->getIssues());

        $majorReport = $this->createReportForFixture('project-major');
        $analyzer->analyze($majorReport);
        $majorCount = count($majorReport->getIssues());

        self::assertGreaterThan($moderateCount, $majorCount);
    }

    /**
     * Verifie que tous les problemes sont de severite BREAKING et categorie TWIG.
     */
    #[Test]
    public function testCreatesBreakingTwigIssues(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::BREAKING, $issue->getSeverity());
            self::assertSame(Category::TWIG, $issue->getCategory());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();

        self::assertSame('Sonata Block Event', $analyzer->getName());
    }

    /**
     * Verifie que les appels situes dans des surcharges de templates Sylius sont
     * detectes mais pas estimes : ils sont comptes avec la reprise de la surcharge.
     */
    #[Test]
    public function testDoesNotEstimateCallsInTemplateOverrides(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        $globalIssue = $this->findSummaryIssue($report);

        self::assertSame(0, $globalIssue->getEstimatedMinutes());
    }

    /**
     * Verifie l'estimation de 30 minutes par appel dans un template propre au projet,
     * une copie identique du template dans un second theme n'etant comptee qu'une fois.
     * Les 15 appels des surcharges (copie admin, fiches produit et paniers) ne sont pas estimes.
     */
    #[Test]
    public function testEstimatesThirtyMinutesPerCallInOwnTemplatesOnce(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-vendor-copies');

        $analyzer->analyze($report);

        $globalIssue = $this->findSummaryIssue($report);

        self::assertSame('17 usage(s) de blocs Sonata / template events detecte(s)', $globalIssue->getMessage());
        self::assertSame(30, $globalIssue->getEstimatedMinutes());
        self::assertStringContainsString('1 appel(s) dans les templates propres au projet', $globalIssue->getDetail());
    }

    /**
     * Verifie qu'une reference a BlockEventListener reste estimee a 60 minutes.
     */
    #[Test]
    public function testEstimatesSixtyMinutesPerBlockEventListener(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-major');

        $analyzer->analyze($report);

        $listenerCount = count(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'BlockEventListener'),
        ));

        self::assertGreaterThan(0, $listenerCount);
        self::assertSame($listenerCount * 60, $this->findSummaryIssue($report)->getEstimatedMinutes());
    }

    /**
     * Retourne le probleme de synthese de l'analyseur.
     */
    private function findSummaryIssue(MigrationReport $report): MigrationIssue
    {
        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'usage(s) de blocs Sonata'),
        ));
        self::assertCount(1, $globalIssues, 'Le probleme global de synthese devrait etre present.');

        return $globalIssues[0];
    }

    /**
     * Verifie que les appels presents dans les themes sont comptes, hors node_modules.
     * 2 sonata_block_render_event (themes) + 4 sylius_template_event (templates/ et themes).
     */
    #[Test]
    public function testCountsUsagesInThemes(): void
    {
        $analyzer = new SonataBlockEventAnalyzer();
        $report = $this->createReportForFixture('project-themed');

        self::assertTrue($analyzer->supports($report));
        $analyzer->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());

        self::assertContains(
            'Appel a sonata_block_render_event() detecte dans themes/LegacyTheme/SyliusShopBundle/views/Homepage/index.html.twig',
            $messages,
        );
        self::assertContains('6 usage(s) de blocs Sonata / template events detecte(s)', $messages);
    }
}
