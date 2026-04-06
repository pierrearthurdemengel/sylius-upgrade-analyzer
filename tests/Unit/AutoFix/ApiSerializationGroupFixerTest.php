<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiSerializationGroupFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de groupes de serialization API.
 */
final class ApiSerializationGroupFixerTest extends TestCase
{
    private ApiSerializationGroupFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ApiSerializationGroupFixer();
        $this->tempDir = sys_get_temp_dir() . '/api-serialization-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src/Entity', 0755, true);
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
            category: Category::API,
            analyzer: 'API Serialization Group',
            message: sprintf('Groupe de serialization sans prefixe sylius: dans %s', $file),
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
    public function testAddsSymliusPrefixToSerializationGroups(): void
    {
        $file = 'src/Entity/Product.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\n#[Groups(['admin:read', 'admin:write'])]\nclass Product {\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString("'sylius:admin:read'", $fix->fixedContent);
        self::assertStringContainsString("'sylius:admin:write'", $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenAlreadyPrefixed(): void
    {
        $file = 'src/Entity/Product.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\n#[Groups(['sylius:admin:read', 'sylius:shop:write'])]\nclass Product {\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testReplacesShopGroups(): void
    {
        $file = 'src/Entity/Order.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\n#[Groups(['shop:read', 'shop:write'])]\nclass Order {\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString("'sylius:shop:read'", $fix->fixedContent);
        self::assertStringContainsString("'sylius:shop:write'", $fix->fixedContent);
    }

    #[Test]
    public function testSupportsPhpFiles(): void
    {
        $issue = $this->createIssue('src/Entity/Product.php');
        self::assertTrue($this->fixer->supports($issue));
    }

    #[Test]
    public function testDoesNotSupportTwigFiles(): void
    {
        $issue = $this->createIssue('templates/product.html.twig');
        self::assertFalse($this->fixer->supports($issue));
    }
}
