<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\PdfGenerationException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder;

/**
 * Tests unitaires de la construction HTML des rapports PDF.
 */
final class HtmlReportBuilderTest extends TestCase
{
    private function createReport(string $name = 'acme/sylius-shop'): MigrationReport
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable('2026-11-04 10:00:00'),
            detectedSyliusVersion: '1.12.0',
            targetVersion: '2.2',
            projectPath: '/var/www/' . basename($name),
        );
        $report->setProjectName($name);

        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'Winzou State Machine',
            message: 'Utilisation de winzou/state-machine détectée',
            detail: 'Sylius 2.0 remplace winzou par Symfony Workflow.',
            suggestion: 'Migrer les callbacks vers des listeners Symfony Workflow.',
            file: 'config/packages/winzou_state_machine.yaml',
            line: 12,
            docUrl: 'https://docs.sylius.com/migration-2.0',
            estimatedMinutes: 240,
        ));
        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::TWIG,
            analyzer: 'Twig Template Override',
            message: 'Template surchargé : SyliusShopBundle/Product/show.html.twig',
            detail: 'Les surcharges doivent migrer vers les Twig Hooks.',
            suggestion: 'Déclarer un hook sylius_shop.product.show.',
            file: 'templates/bundles/SyliusShopBundle/Product/show.html.twig',
            estimatedMinutes: 60,
        ));
        $report->complete();

        return $report;
    }

    /** Vérifie la présence des sections clés du rapport projet. */
    #[Test]
    public function testBuildsProjectReportWithKeySections(): void
    {
        $html = (new HtmlReportBuilder())->buildProjectReport($this->createReport());

        self::assertStringContainsString('acme/sylius-shop', $html);
        self::assertStringContainsString('Synthèse', $html);
        self::assertStringContainsString('Feuille de route', $html);
        self::assertStringContainsString('Problèmes bloquants (1)', $html);
        self::assertStringContainsString('Avertissements (1)', $html);
        self::assertStringContainsString('config/packages/winzou_state_machine.yaml:12', $html);
    }

    /** Vérifie que le budget n'apparaît qu'avec un TJM. */
    #[Test]
    public function testIncludesCostOnlyWhenDailyRateIsProvided(): void
    {
        $builder = new HtmlReportBuilder();
        $report = $this->createReport();

        self::assertStringNotContainsString('TJM retenu', $builder->buildProjectReport($report));
        self::assertStringContainsString('TJM retenu', $builder->buildProjectReport($report, 650));
    }

    /** Vérifie l'échappement des données issues du projet analysé. */
    #[Test]
    public function testEscapesProjectDataInHtml(): void
    {
        $html = (new HtmlReportBuilder())->buildProjectReport($this->createReport('<script>alert(1)</script>'));

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Vérifie que le rapport consolidé liste chaque projet et le total. */
    #[Test]
    public function testBuildsMultiProjectReportWithEveryProject(): void
    {
        $html = (new HtmlReportBuilder())->buildMultiProjectReport(
            [$this->createReport('acme/shop-fr'), $this->createReport('acme/shop-de')],
            600,
        );

        self::assertStringContainsString('2 projet(s) analysé(s)', $html);
        self::assertStringContainsString('acme/shop-fr', $html);
        self::assertStringContainsString('acme/shop-de', $html);
        self::assertStringContainsString('Total', $html);
    }

    /** Vérifie que le total consolidé additionne les budgets par projet. */
    #[Test]
    public function testSumsPerProjectCostsInConsolidatedTotal(): void
    {
        // 5 h par projet = 1 jour chacun après arrondi, soit 2 jours (1 200 €) au total,
        // là où un calcul sur les 10 h cumulées donnerait 1,5 jour.
        $html = (new HtmlReportBuilder())->buildMultiProjectReport(
            [$this->createReport('acme/shop-fr'), $this->createReport('acme/shop-de')],
            600,
        );

        self::assertStringContainsString('1 200 €', $html);
    }

    /** Vérifie le formatage français des heures et montants. */
    #[Test]
    public function testFormatsHoursAndEurosInFrench(): void
    {
        $builder = new HtmlReportBuilder();

        self::assertSame('12,5 h', $builder->hours(12.5));
        self::assertSame('18 300 €', $builder->euros(18300.0));
    }

    /** Vérifie l'erreur explicite quand un template manque. */
    #[Test]
    public function testThrowsWhenTemplateDirectoryIsMissing(): void
    {
        $builder = new HtmlReportBuilder(templateDirectory: sys_get_temp_dir() . '/introuvable-' . uniqid());

        $this->expectException(PdfGenerationException::class);

        $builder->buildProjectReport($this->createReport());
    }
}
