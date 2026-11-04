<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\OrderProcessorAutoconfigurationFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests du correctif qui ajoute #[AsOrderProcessor] ou #[AsCartContext].
 */
final class OrderProcessorAutoconfigurationFixerTest extends TestCase
{
    private const FILE = 'src/OrderProcessing/GiftWrappingProcessor.php';

    private OrderProcessorAutoconfigurationFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new OrderProcessorAutoconfigurationFixer();
        $this->tempDir = sys_get_temp_dir() . '/order-autoconfig-fixer-' . uniqid('', true);
        mkdir($this->tempDir . '/src/OrderProcessing', 0755, true);
    }

    protected function tearDown(): void
    {
        $path = $this->tempDir . '/' . self::FILE;
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($this->tempDir . '/src/OrderProcessing');
        rmdir($this->tempDir . '/src');
        rmdir($this->tempDir);
    }

    private function issue(int $line, string $interface = 'OrderProcessorInterface', string $analyzer = 'Order Processor Autoconfiguration'): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: $analyzer,
            message: sprintf('App\OrderProcessing\GiftWrappingProcessor ne sera plus enregistre : %s sans attribut', $interface),
            detail: '',
            suggestion: '',
            file: self::FILE,
            line: $line,
        );
    }

    #[Test]
    public function testAddsAttributeAndImportAfterLastUse(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, "<?php\n\nnamespace App\\OrderProcessing;\n\nuse Sylius\\Component\\Order\\Processor\\OrderProcessorInterface;\n\nfinal class GiftWrappingProcessor implements OrderProcessorInterface\n{\n}\n");

        $fix = $this->fixer->fix($this->issue(7), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertSame(
            "<?php\n\nnamespace App\\OrderProcessing;\n\nuse Sylius\\Component\\Order\\Processor\\OrderProcessorInterface;\nuse Sylius\\Bundle\\OrderBundle\\Attribute\\AsOrderProcessor;\n\n#[AsOrderProcessor]\nfinal class GiftWrappingProcessor implements OrderProcessorInterface\n{\n}\n",
            $fix->fixedContent,
        );
    }

    #[Test]
    public function testAddsCartContextAttribute(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, "<?php\n\nnamespace App;\n\nclass ChannelCartContext implements \\Sylius\\Component\\Order\\Context\\CartContextInterface\n{\n}\n");

        $fix = $this->fixer->fix($this->issue(5, 'CartContextInterface'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString("namespace App;\n\nuse Sylius\\Bundle\\OrderBundle\\Attribute\\AsCartContext;", $fix->fixedContent);
        self::assertStringContainsString("#[AsCartContext]\nclass ChannelCartContext", $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenLineIsNotAClassDeclaration(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, "<?php\n\nfinal class GiftWrappingProcessor\n{\n}\n");

        self::assertNull($this->fixer->fix($this->issue(1), $this->tempDir));
    }

    #[Test]
    public function testSupportsOnlyItsAnalyzerOnPhpFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->issue(7)));
        self::assertFalse($this->fixer->supports($this->issue(7, analyzer: 'Order Processor Priority')));
    }
}
