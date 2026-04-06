<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ResourceBundleFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de configuration ResourceBundle.
 */
final class ResourceBundleFixerTest extends TestCase
{
    private ResourceBundleFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ResourceBundleFixer();
        $this->tempDir = sys_get_temp_dir() . '/resource-bundle-fixer-test-' . uniqid();
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
            category: Category::RESOURCE,
            analyzer: 'Resource Bundle',
            message: sprintf('Configuration ResourceBundle obsolete dans %s', $file),
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
    public function testReplacesDoctrineOrmDriverFormat(): void
    {
        $file = 'config/packages/sylius_resource.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_resource:\n    resources:\n        app.product:\n            driver: doctrine/orm\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('driver: doctrine_orm', $fix->fixedContent);
        self::assertStringNotContainsString('driver: doctrine/orm', $fix->fixedContent);
    }

    #[Test]
    public function testCommentsPhpcrDriver(): void
    {
        $file = 'config/packages/sylius_resource.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_resource:\n    resources:\n        app.content:\n            driver: doctrine/phpcr-odm\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('# TODO: PHPCR supprime', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenAlreadyModern(): void
    {
        $file = 'config/packages/sylius_resource.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_resource:\n    resources:\n        app.product:\n            driver: doctrine_orm\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlFilesOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/resource.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/resource.yml')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Resource.php')));
    }
}
