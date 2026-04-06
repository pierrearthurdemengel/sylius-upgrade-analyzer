<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ServiceDecoratorFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de decorateurs de services.
 */
final class ServiceDecoratorFixerTest extends TestCase
{
    private ServiceDecoratorFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ServiceDecoratorFixer();
        $this->tempDir = sys_get_temp_dir() . '/service-decorator-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
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
            analyzer: 'Service Decorator',
            message: sprintf('Decorateur de service obsolete dans %s', $file),
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
    public function testReplacesAdminControllerPrefix(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    App\Controller\ProductController:\n        decorates: sylius.controller.admin.product\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('decorates: sylius_admin.controller.product', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesShopControllerPrefixQuoted(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    App\Controller\CartController:\n        decorates: 'sylius.controller.shop.cart'\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString("decorates: 'sylius_shop.controller.cart'", $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOldPrefixes(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    App\Controller\ProductController:\n        decorates: sylius_admin.controller.product\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlAndXmlFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/services.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/services.xml')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Controller.php')));
    }
}
