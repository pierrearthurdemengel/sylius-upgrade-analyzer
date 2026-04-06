<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\OrderProcessorPriorityFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de priorite OrderProcessor.
 */
final class OrderProcessorPriorityFixerTest extends TestCase
{
    private OrderProcessorPriorityFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new OrderProcessorPriorityFixer();
        $this->tempDir = sys_get_temp_dir() . '/order-processor-priority-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        mkdir($this->tempDir . '/src/OrderProcessing', 0755, true);
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
            analyzer: 'Order Processor Priority',
            message: sprintf('OrderProcessor sans priorite explicite dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 15,
        );
    }

    #[Test]
    public function testAddsPriorityToYamlTag(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    app.order_processor.custom:\n        tags:\n            - { name: sylius.order_processor\n              }\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('priority: 0', $fix->fixedContent);
    }

    #[Test]
    public function testAddsGetPriorityToPhpClass(): void
    {
        $file = 'src/OrderProcessing/CustomOrderProcessor.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nclass CustomOrderProcessor implements OrderProcessorInterface\n{\n    public function process(OrderInterface \$order): void\n    {\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('public static function getPriority(): int', $fix->fixedContent);
        self::assertStringContainsString('return 0;', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenPhpAlreadyHasGetPriority(): void
    {
        $file = 'src/OrderProcessing/PrioritizedProcessor.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nclass PrioritizedProcessor implements OrderProcessorInterface\n{\n    public function process(OrderInterface \$order): void\n    {\n    }\n\n    public static function getPriority(): int\n    {\n        return 50;\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsPhpAndYamlFiles(): void
    {
        $phpIssue = $this->createIssue('src/OrderProcessing/Processor.php');
        self::assertTrue($this->fixer->supports($phpIssue));

        $yamlIssue = $this->createIssue('config/services.yaml');
        self::assertTrue($this->fixer->supports($yamlIssue));

        $twigIssue = $this->createIssue('templates/order.html.twig');
        self::assertFalse($this->fixer->supports($twigIssue));
    }
}
