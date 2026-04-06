<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\UseWebpackConfigFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de suppression de use_webpack.
 */
final class UseWebpackConfigFixerTest extends TestCase
{
    private UseWebpackConfigFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new UseWebpackConfigFixer();
        $this->tempDir = sys_get_temp_dir() . '/webpack-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config/packages', 0755, true);
        mkdir($this->tempDir . '/templates', 0755, true);
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
            analyzer: 'Use Webpack Config',
            message: sprintf('Configuration use_webpack detectee dans %s ligne 5', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: 5,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 15,
        );
    }

    #[Test]
    public function testRemovesUseWebpackFromYaml(): void
    {
        $file = 'config/packages/sylius_ui.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_ui:\n    events:\n        sylius.shop.layout:\n            use_webpack: true\n            blocks:\n                header: ~\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringNotContainsString('use_webpack', $fix->fixedContent);
        self::assertStringContainsString('blocks:', $fix->fixedContent);
    }

    #[Test]
    public function testRemovesUseWebpackConditionFromTwig(): void
    {
        $file = 'templates/layout.html.twig';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "{% if use_webpack %}<script src=\"build/app.js\"></script>{% endif %}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('<script src="build/app.js"></script>', $fix->fixedContent);
        self::assertStringNotContainsString('use_webpack', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoWebpackConfig(): void
    {
        $file = 'config/packages/sylius_ui.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_ui:\n    events:\n        sylius.shop.layout:\n            blocks:\n                header: ~\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlAndTwigFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/sylius.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('templates/base.html.twig')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Controller.php')));
    }
}
