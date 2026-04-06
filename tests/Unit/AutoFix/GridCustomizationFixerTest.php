<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\GridCustomizationFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de configuration de grille.
 */
final class GridCustomizationFixerTest extends TestCase
{
    private GridCustomizationFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new GridCustomizationFixer();
        $this->tempDir = sys_get_temp_dir() . '/grid-customization-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config/grids', 0755, true);
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
            category: Category::GRID,
            analyzer: 'Grid Customization',
            message: sprintf('Configuration de grille obsolete dans %s', $file),
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
    public function testReplacesFieldWithFields(): void
    {
        $file = 'config/grids/product.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_grid:\n    grids:\n        app_product:\n            filters:\n                name:\n                    field: product.name\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('fields: product.name', $fix->fixedContent);
        self::assertStringNotContainsString('field: product.name', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesDoctrineSlashOrmDriver(): void
    {
        $file = 'config/grids/order.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_grid:\n    grids:\n        app_order:\n            driver: doctrine/orm\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('driver: doctrine_orm', $fix->fixedContent);
        self::assertStringNotContainsString('driver: doctrine/orm', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenAlreadyModern(): void
    {
        $file = 'config/grids/modern.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_grid:\n    grids:\n        app_product:\n            driver: doctrine_orm\n            filters:\n                name:\n                    fields: product.name\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlAndPhpFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/grids/product.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/grids/product.yml')));
        self::assertTrue($this->fixer->supports($this->createIssue('src/Grid/ProductGrid.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/grid.html.twig')));
    }
}
