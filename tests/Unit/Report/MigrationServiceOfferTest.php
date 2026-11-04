<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ConsoleReporter;
use PierreArthur\SyliusUpgradeAnalyzer\Report\CsvReporter;
use PierreArthur\SyliusUpgradeAnalyzer\Report\JsonReporter;
use PierreArthur\SyliusUpgradeAnalyzer\Report\MarkdownReporter;
use PierreArthur\SyliusUpgradeAnalyzer\Report\MigrationServiceOffer;
use PierreArthur\SyliusUpgradeAnalyzer\Report\MultiProjectReporter;
use PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ReporterInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Report\SarifReporter;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests de la mention d'accompagnement a la migration : contenu, liens par support,
 * presence dans les formats lus par des humains et absence dans les formats machine.
 */
final class MigrationServiceOfferTest extends TestCase
{
    private const DOMAIN = 'creation-site-pro.fr';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Rapport d'un projet avec deux issues bloquantes de 6 heures (12 h, soit 2 jours).
     */
    private function createReport(): MigrationReport
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.13.4',
            targetVersion: '2.2',
            projectPath: '/var/www/acme-shop',
        );
        $report->setProjectName('acme/sylius-shop');

        foreach (['sylius_order', 'sylius_payment'] as $graph) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: 'Winzou State Machine',
                message: sprintf('Callback winzou sur le graphe %s', $graph),
                detail: 'winzou/state-machine est remplace par Symfony Workflow.',
                suggestion: 'Migrer le callback vers un listener Symfony Workflow.',
                estimatedMinutes: 360,
            ));
        }
        $report->complete();

        return $report;
    }

    private function tempFile(string $extension): string
    {
        $path = sys_get_temp_dir() . '/offer-' . uniqid() . '.' . $extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    /** Verifie que chaque support recoit son propre marqueur de provenance. */
    #[Test]
    public function testBuildsLandingUrlTaggedWithMedium(): void
    {
        self::assertSame(
            'https://creation-site-pro.fr/en/sylius-2-upgrade?utm_source=analyzer-pdf',
            (new MigrationServiceOffer())->url('pdf'),
        );
    }

    /** Verifie que la mention est active par defaut et retiree seulement par un false explicite. */
    #[Test]
    public function testIsEnabledUnlessExplicitlyDisabled(): void
    {
        self::assertTrue(MigrationServiceOffer::isEnabled([]));
        self::assertTrue(MigrationServiceOffer::isEnabled([MigrationServiceOffer::CONTEXT_KEY => true]));
        self::assertFalse(MigrationServiceOffer::isEnabled([MigrationServiceOffer::CONTEXT_KEY => false]));
    }

    /** Verifie la conversion de la charge en jours ouvres arrondis a la demi-journee. */
    #[Test]
    public function testFormatsEffortInWorkingDays(): void
    {
        $offer = new MigrationServiceOffer();

        self::assertSame('0.5 working day', $offer->formatDays(3.0));
        self::assertSame('1 working day', $offer->formatDays(7.0));
        self::assertSame('2 working days', $offer->formatDays(14.0));
        self::assertSame('10.5 working days', $offer->formatDays(73.5));
    }

    /** Verifie qu'aucune mention n'est proposee pour un projet sans charge de migration. */
    #[Test]
    public function testOmitsConsoleLineWhenNoEffortIsEstimated(): void
    {
        self::assertNull((new MigrationServiceOffer())->consoleLine(0.0));
    }

    /** Verifie la ligne console : charge en jours et lien marque « console ». */
    #[Test]
    public function testConsoleReportEndsWithOfferLine(): void
    {
        $output = new BufferedOutput();

        (new ConsoleReporter())->generate($this->createReport(), $output);

        $display = $output->fetch();
        self::assertStringContainsString('Estimated effort: about 2 working days', $display);
        self::assertStringContainsString('utm_source=analyzer-console', $display);
    }

    /** Verifie que --no-promo retire la ligne de la sortie console. */
    #[Test]
    public function testConsoleReportHidesOfferWhenDisabled(): void
    {
        $output = new BufferedOutput();

        (new ConsoleReporter())->generate($this->createReport(), $output, [MigrationServiceOffer::CONTEXT_KEY => false]);

        self::assertStringNotContainsString(self::DOMAIN, $output->fetch());
    }

    /** Verifie la ligne de pied du rapport Markdown, et son retrait avec --no-promo. */
    #[Test]
    public function testMarkdownReportEndsWithOfferUnlessDisabled(): void
    {
        $withOffer = $this->tempFile('md');
        $withoutOffer = $this->tempFile('md');

        (new MarkdownReporter())->generate($this->createReport(), new BufferedOutput(), ['output_file' => $withOffer]);
        (new MarkdownReporter())->generate($this->createReport(), new BufferedOutput(), [
            'output_file' => $withoutOffer,
            MigrationServiceOffer::CONTEXT_KEY => false,
        ]);

        $markdown = rtrim((string) file_get_contents($withOffer));
        self::assertStringEndsWith('(https://creation-site-pro.fr/en/sylius-2-upgrade?utm_source=analyzer-markdown).', $markdown);
        self::assertStringNotContainsString(self::DOMAIN, (string) file_get_contents($withoutOffer));
    }

    /**
     * Verifie que les formats lus par des outils ne contiennent jamais la mention,
     * meme lorsqu'elle est autorisee.
     *
     * @param class-string<ReporterInterface> $reporterClass
     */
    #[Test]
    #[DataProvider('machineReadableReporters')]
    public function testMachineReadableFormatsNeverContainOffer(string $reporterClass, string $extension): void
    {
        $outputFile = $this->tempFile($extension);
        $output = new BufferedOutput();

        (new $reporterClass())->generate($this->createReport(), $output, [
            'output_file' => $outputFile,
            MigrationServiceOffer::CONTEXT_KEY => true,
        ]);

        self::assertFileExists($outputFile);
        self::assertStringNotContainsString(self::DOMAIN, (string) file_get_contents($outputFile));
        self::assertStringNotContainsString(self::DOMAIN, $output->fetch());
    }

    /**
     * @return iterable<string, array{class-string<ReporterInterface>, string}>
     */
    public static function machineReadableReporters(): iterable
    {
        yield 'json' => [JsonReporter::class, 'json'];
        yield 'sarif' => [SarifReporter::class, 'sarif'];
        yield 'csv' => [CsvReporter::class, 'csv'];
    }

    /** Verifie l'encart de fin du PDF et son retrait. */
    #[Test]
    public function testPdfReportIncludesOfferSectionUnlessDisabled(): void
    {
        $builder = new HtmlReportBuilder();

        $withOffer = $builder->buildProjectReport($this->createReport());
        $withoutOffer = $builder->buildProjectReport($this->createReport(), null, false);

        self::assertStringContainsString('Need help with this migration?', $withOffer);
        self::assertStringContainsString('utm_source=analyzer-pdf', $withOffer);
        self::assertStringNotContainsString(self::DOMAIN, $withoutOffer);
    }

    /** Verifie la mention du rapport consolide en console et Markdown, et son absence en JSON. */
    #[Test]
    public function testMultiProjectReportAddsOfferOnlyToHumanFormats(): void
    {
        $reports = [$this->createReport(), $this->createReport()];
        $reporter = new MultiProjectReporter();
        $console = new BufferedOutput();

        $reporter->generate($reports, 'console', $console);

        // 2 projets x 12 h = 24 h, soit 3,5 jours sur une base de 7 h
        self::assertStringContainsString('about 3.5 working days', $console->fetch());
        self::assertStringContainsString('utm_source=analyzer-markdown', $reporter->buildMarkdown($reports));
        self::assertStringNotContainsString(self::DOMAIN, $reporter->buildMarkdown($reports, null, false));
        self::assertStringNotContainsString(self::DOMAIN, $reporter->buildJson($reports));
    }

    /** Verifie que la mention du rapport consolide disparait avec --no-promo. */
    #[Test]
    public function testMultiProjectConsoleHidesOfferWhenDisabled(): void
    {
        $console = new BufferedOutput();

        (new MultiProjectReporter())->generate([$this->createReport()], 'console', $console, null, null, false);

        self::assertStringNotContainsString(self::DOMAIN, $console->fetch());
    }
}
