<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiPlatformMigrationFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de migration API Platform.
 */
final class ApiPlatformMigrationFixerTest extends TestCase
{
    private ApiPlatformMigrationFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ApiPlatformMigrationFixer();
        $this->tempDir = sys_get_temp_dir() . '/api-platform-migration-fixer-test-' . uniqid();
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
            analyzer: 'API Platform Migration',
            message: sprintf('Reference API Platform Core obsolete dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 60,
        );
    }

    #[Test]
    public function testReplacesApiResourceAnnotationNamespace(): void
    {
        $file = 'src/Entity/Product.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse ApiPlatform\Core\Annotation\ApiResource;\n\nclass Product\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('use ApiPlatform\Metadata\ApiResource;', $fix->fixedContent);
        self::assertStringNotContainsString('ApiPlatform\Core\Annotation\ApiResource', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesApiFilterNamespace(): void
    {
        $file = 'src/Entity/Order.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse ApiPlatform\Core\Annotation\ApiFilter;\n\nclass Order\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('use ApiPlatform\Metadata\ApiFilter;', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenAlreadyMigrated(): void
    {
        $file = 'src/Entity/ModernEntity.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse ApiPlatform\Metadata\ApiResource;\n\n#[ApiResource]\nclass ModernEntity\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsPhpFilesOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Entity/Product.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('config/api_platform.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/api.html.twig')));
    }
}
