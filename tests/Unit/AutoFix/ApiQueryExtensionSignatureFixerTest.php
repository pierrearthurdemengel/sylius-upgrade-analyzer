<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiQueryExtensionSignatureFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de signature QueryExtension API Platform.
 */
final class ApiQueryExtensionSignatureFixerTest extends TestCase
{
    private ApiQueryExtensionSignatureFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ApiQueryExtensionSignatureFixer();
        $this->tempDir = sys_get_temp_dir() . '/api-query-extension-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src/ApiExtension', 0755, true);
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
            analyzer: 'API Query Extension Signature',
            message: sprintf('Signature QueryExtension obsolete dans %s', $file),
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
    public function testReplacesOperationNameWithOperation(): void
    {
        $file = 'src/ApiExtension/ProductExtension.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nnamespace App\\ApiExtension;\n\nclass ProductExtension\n{\n    public function applyToCollection(QueryBuilder \$queryBuilder, QueryNameGeneratorInterface \$queryNameGenerator, string \$resourceClass, string \$operationName = null): void\n    {\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('Operation $operation', $fix->fixedContent);
        self::assertStringNotContainsString('string $operationName', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOperationName(): void
    {
        $file = 'src/ApiExtension/SimpleExtension.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nnamespace App\\ApiExtension;\n\nclass SimpleExtension\n{\n    public function doSomething(string \$value): void\n    {\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testAddsUseStatementForOperation(): void
    {
        $file = 'src/ApiExtension/OrderExtension.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nnamespace App\\ApiExtension;\n\nclass OrderExtension\n{\n    public function applyToItem(QueryBuilder \$queryBuilder, QueryNameGeneratorInterface \$queryNameGenerator, string \$resourceClass, array \$identifiers, string \$operationName = null): void\n    {\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('use ApiPlatform\\Metadata\\Operation;', $fix->fixedContent);
    }

    #[Test]
    public function testSupportsPhpFilesOnly(): void
    {
        $phpIssue = $this->createIssue('src/Extension.php');
        self::assertTrue($this->fixer->supports($phpIssue));

        $yamlIssue = $this->createIssue('config/services.yaml');
        self::assertFalse($this->fixer->supports($yamlIssue));
    }
}
