<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\GridFilterEntityFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de type de filtre de grille entities → entity.
 */
final class GridFilterEntityFixerTest extends TestCase
{
    private GridFilterEntityFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new GridFilterEntityFixer();
        $this->tempDir = sys_get_temp_dir() . '/grid-fixer-test-' . uniqid();
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
            severity: Severity::BREAKING,
            category: Category::GRID,
            analyzer: 'Grid Filter Entity',
            message: sprintf('Type de filtre `entities` obsolete dans %s ligne 10', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: 10,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 15,
        );
    }

    #[Test]
    public function testReplacesEntitiesWithEntity(): void
    {
        $file = 'config/grids.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_grid:\n    grids:\n        app_order:\n            filters:\n                customer:\n                    type: entities\n                    options:\n                        fields: [customer]\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('type: entity', $fix->fixedContent);
        self::assertStringNotContainsString('type: entities', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenAlreadyCorrect(): void
    {
        $file = 'config/grids.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_grid:\n    grids:\n        app_order:\n            filters:\n                customer:\n                    type: entity\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlFilesOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/grids.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Grid.php')));
    }
}
