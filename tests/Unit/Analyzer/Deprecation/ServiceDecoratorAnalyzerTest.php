<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\ServiceDecoratorAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des decorateurs de services Sylius.
 * Verifie la detection des decorators referencant des interfaces renommees.
 */
final class ServiceDecoratorAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('decorator_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir((string) $item->getRealPath());
            } else {
                unlink((string) $item->getRealPath());
            }
        }

        rmdir($path);
    }

    private function createReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12',
            targetVersion: '2.0',
            projectPath: $this->tempDir,
        );
    }

    /**
     * Verifie que supports retourne false sans config/.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutConfigDir(): void
    {
        $analyzer = new ServiceDecoratorAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand un YAML contient decorates:.
     */
    #[Test]
    public function testSupportsReturnsTrueWithDecorates(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/services.yaml', <<<'YAML'
services:
    App\Repository\CustomProductRepository:
        decorates: sylius.repository.product
YAML);

        $analyzer = new ServiceDecoratorAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection d'un decorateur de service Sylius.
     */
    #[Test]
    public function testDetectsSyliusServiceDecorator(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/services.yaml', <<<'YAML'
services:
    App\Repository\CustomProductRepository:
        decorates: sylius.repository.product
YAML);

        $analyzer = new ServiceDecoratorAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $decoratorIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sylius.repository.product'),
        );
        self::assertNotEmpty($decoratorIssues);
    }

    /**
     * Verifie la detection d'un decorateur referencant une interface renommee.
     */
    #[Test]
    public function testDetectsRenamedInterfaceDecorator(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/services.yaml', <<<'YAML'
services:
    App\Calculator\CustomPriceCalculator:
        decorates: sylius.calculator.ProductVariantPriceCalculatorInterface
YAML);

        $analyzer = new ServiceDecoratorAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $renamedIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'interface renommee'),
        );
        self::assertNotEmpty($renamedIssues);
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/services.yaml', <<<'YAML'
services:
    App\Repository\CustomProductRepository:
        decorates: sylius.repository.product
YAML);

        $analyzer = new ServiceDecoratorAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new ServiceDecoratorAnalyzer();

        self::assertSame('Service Decorator', $analyzer->getName());
    }
}
