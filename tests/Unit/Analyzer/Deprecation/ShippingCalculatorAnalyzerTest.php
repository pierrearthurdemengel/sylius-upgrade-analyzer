<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\ShippingCalculatorAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests de la detection des references aux services de calcul des frais de port renommes.
 */
final class ShippingCalculatorAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipping-calculator-' . uniqid('', true);
        mkdir($this->tempDir . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir . '/config');
        rmdir($this->tempDir);
    }

    private function analyze(string $yaml): MigrationReport
    {
        file_put_contents($this->tempDir . '/config/services.yaml', $yaml);
        $report = new MigrationReport(new \DateTimeImmutable(), '1.14.0', '2.2', $this->tempDir);
        (new ShippingCalculatorAnalyzer())->analyze($report);

        return $report;
    }

    #[Test]
    public function testFlagsInjectedRenamedService(): void
    {
        $issues = $this->analyze("services:\n    App\\Shipping\\WeightCalculator:\n        arguments: ['@sylius.shipping_calculator.flat_rate']\n")->getIssues();

        self::assertCount(1, $issues);
        self::assertSame(Severity::BREAKING, $issues[0]->getSeverity());
        self::assertSame('Service renomme "sylius.shipping_calculator.flat_rate" reference dans services.yaml', $issues[0]->getMessage());
        self::assertSame('Remplacer sylius.shipping_calculator.flat_rate par sylius.calculator.shipping.flat_rate.', $issues[0]->getSuggestion());
        self::assertSame(3, $issues[0]->getLine());
    }

    #[Test]
    public function testFlagsDecoratedRenamedService(): void
    {
        $issues = $this->analyze("services:\n    App\\Shipping\\LoggingCalculator:\n        decorates: sylius.shipping_calculator\n")->getIssues();

        self::assertCount(1, $issues);
        self::assertStringContainsString('"sylius.shipping_calculator"', $issues[0]->getMessage());
    }

    #[Test]
    public function testIgnoresUnchangedTagOfCustomCalculator(): void
    {
        /* Le tag sylius.shipping_calculator garde son nom en Sylius 2.0 */
        $report = $this->analyze("services:\n    App\\Shipping\\WeightCalculator:\n        tags:\n            - { name: sylius.shipping_calculator, calculator: weight, label: Poids }\n");

        self::assertSame([], $report->getIssues());
    }

    #[Test]
    public function testSupportsProjectsWithConfiguration(): void
    {
        $report = new MigrationReport(new \DateTimeImmutable(), '1.14.0', '2.2', $this->tempDir);

        self::assertTrue((new ShippingCalculatorAnalyzer())->supports($report));
    }

    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        self::assertSame('Shipping Calculator', (new ShippingCalculatorAnalyzer())->getName());
    }
}
