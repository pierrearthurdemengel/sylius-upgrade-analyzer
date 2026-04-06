<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RoutingImportFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer d'imports de routing.
 */
final class RoutingImportFixerTest extends TestCase
{
    private RoutingImportFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new RoutingImportFixer();
        $this->tempDir = sys_get_temp_dir() . '/routing-import-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config/routes', 0755, true);
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
            analyzer: 'Routing Import',
            message: sprintf('Import de routing obsolete dans %s', $file),
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
    public function testReplacesPayumRoutingImport(): void
    {
        $file = 'config/routes/sylius_shop.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "imports:\n    - { resource: '@SyliusShopBundle/Resources/config/routing/payum.yml' }\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('@SyliusPayumBundle/Resources/config/routing/integrations/sylius_shop.yaml', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesNewApiRouteParam(): void
    {
        $file = 'config/routes/sylius_api.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "api:\n    prefix: '%sylius.security.new_api_route%'\n    host: '%sylius.security.new_api_regex%'\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('%sylius.security.api_route%', $fix->fixedContent);
        self::assertStringContainsString('%sylius.security.api_regex%', $fix->fixedContent);
        self::assertStringNotContainsString('new_api_route', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOldImports(): void
    {
        $file = 'config/routes/shop.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "imports:\n    - { resource: '@SyliusPayumBundle/Resources/config/routing/integrations/sylius_shop.yaml' }\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlFilesOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/routes.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/routes.yml')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Controller.php')));
    }
}
