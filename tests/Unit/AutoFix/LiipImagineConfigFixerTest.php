<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\LiipImagineConfigFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de configuration Liip Imagine.
 */
final class LiipImagineConfigFixerTest extends TestCase
{
    private LiipImagineConfigFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new LiipImagineConfigFixer();
        $this->tempDir = sys_get_temp_dir() . '/liip-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config/packages', 0755, true);
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
            analyzer: 'Liip Imagine Config',
            message: sprintf('Resolver "default" detecte dans %s', $file),
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
    public function testReplacesDefaultResolver(): void
    {
        $file = 'config/packages/liip_imagine.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "liip_imagine:\n    resolvers:\n        default:\n            web_path: ~\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('sylius_image:', $fix->fixedContent);
        self::assertStringNotContainsString("resolvers:\n        default:", $fix->fixedContent);
    }

    #[Test]
    public function testReplacesDefaultLoader(): void
    {
        $file = 'config/packages/liip_imagine.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "liip_imagine:\n    loaders:\n        default:\n            filesystem: ~\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString("loaders:\n        sylius_image:", $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoDefaultFound(): void
    {
        $file = 'config/packages/liip_imagine.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "liip_imagine:\n    resolvers:\n        sylius_image:\n            web_path: ~\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }
}
