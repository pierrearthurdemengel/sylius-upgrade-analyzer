<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ShippingCalculatorFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests du renommage des references aux services de calcul des frais de port.
 */
final class ShippingCalculatorFixerTest extends TestCase
{
    private const FILE = 'config/services.yaml';

    private ShippingCalculatorFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ShippingCalculatorFixer();
        $this->tempDir = sys_get_temp_dir() . '/shipping-calculator-fixer-' . uniqid('', true);
        mkdir($this->tempDir . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempDir . '/' . self::FILE)) {
            unlink($this->tempDir . '/' . self::FILE);
        }
        rmdir($this->tempDir . '/config');
        rmdir($this->tempDir);
    }

    private function issue(string $analyzer = 'Shipping Calculator', string $file = self::FILE): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: $analyzer,
            message: 'Service renomme',
            detail: '',
            suggestion: '',
            file: $file,
        );
    }

    #[Test]
    public function testRenamesReferencesButKeepsTheUnchangedTag(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, <<<'YAML'
services:
    App\Shipping\WeightCalculator:
        arguments: ['@sylius.shipping_calculator.per_unit_rate']
        tags:
            - { name: sylius.shipping_calculator, calculator: weight }
    App\Shipping\LoggingCalculator:
        decorates: sylius.shipping_calculator
        arguments: ['@.inner']
YAML);

        $fix = $this->fixer->fix($this->issue(), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString("['@sylius.calculator.shipping.per_unit_rate']", $fix->fixedContent);
        self::assertStringContainsString('decorates: sylius.calculator.shipping', $fix->fixedContent);
        self::assertStringContainsString('{ name: sylius.shipping_calculator, calculator: weight }', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenOnlyTheTagIsUsed(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, "services:\n    App\\Shipping\\WeightCalculator:\n        tags: [{ name: sylius.shipping_calculator, calculator: weight }]\n");

        self::assertNull($this->fixer->fix($this->issue(), $this->tempDir));
    }

    #[Test]
    public function testSupportsOnlyYamlFilesOfItsAnalyzer(): void
    {
        self::assertTrue($this->fixer->supports($this->issue()));
        self::assertFalse($this->fixer->supports($this->issue(file: 'src/Shipping/WeightCalculator.php')));
        self::assertFalse($this->fixer->supports($this->issue('Service Decorator')));
    }
}
