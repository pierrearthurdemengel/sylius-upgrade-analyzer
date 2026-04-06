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
 * Tests unitaires pour le fixer de ShippingCalculator.
 */
final class ShippingCalculatorFixerTest extends TestCase
{
    private ShippingCalculatorFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ShippingCalculatorFixer();
        $this->tempDir = sys_get_temp_dir() . '/shipping-calculator-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src/Shipping', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createIssue(string $file): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::DEPRECATION,
            analyzer: 'Shipping Calculator',
            message: sprintf('Reference DelegatingCalculatorInterface dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 30,
        );
    }

    #[Test]
    public function testReplacesDelegatingCalculatorInterface(): void
    {
        $file = 'src/Shipping/CustomCalculator.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Sylius\Component\Shipping\Calculator\DelegatingCalculatorInterface;\n\nclass CustomCalculator implements DelegatingCalculatorInterface\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('CalculatorInterface', $fix->fixedContent);
        self::assertStringNotContainsString('DelegatingCalculatorInterface', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoDelegatingReference(): void
    {
        $file = 'src/Shipping/ModernCalculator.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Sylius\Component\Shipping\Calculator\CalculatorInterface;\n\nclass ModernCalculator implements CalculatorInterface\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsPhpAndYamlFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Calculator.php')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/services.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/calc.html.twig')));
    }
}
