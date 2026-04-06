<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FormTypeExtensionPriorityFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de priorite FormTypeExtension.
 */
final class FormTypeExtensionPriorityFixerTest extends TestCase
{
    private FormTypeExtensionPriorityFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new FormTypeExtensionPriorityFixer();
        $this->tempDir = sys_get_temp_dir() . '/form-type-ext-priority-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src/Form/Extension', 0755, true);
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
            analyzer: 'Form Type Extension Priority',
            message: sprintf('FormTypeExtension sans getPriority() dans %s', $file),
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
    public function testAddsGetPriorityMethodWhenMissing(): void
    {
        $file = 'src/Form/Extension/ProductTypeExtension.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nclass ProductTypeExtension\n{\n    public static function getExtendedTypes(): iterable\n    {\n        return [ProductType::class];\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('public static function getPriority(): int', $fix->fixedContent);
        self::assertStringContainsString('return 0;', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenGetPriorityAlreadyExists(): void
    {
        $file = 'src/Form/Extension/OrderTypeExtension.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nclass OrderTypeExtension\n{\n    public static function getExtendedTypes(): iterable\n    {\n        return [OrderType::class];\n    }\n\n    public static function getPriority(): int\n    {\n        return 10;\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testReturnsNullWhenNoGetExtendedTypes(): void
    {
        $file = 'src/Form/Extension/SimpleClass.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nclass SimpleClass\n{\n    public function doSomething(): void\n    {\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsPhpFilesOnly(): void
    {
        $phpIssue = $this->createIssue('src/Form/Extension/ProductTypeExtension.php');
        self::assertTrue($this->fixer->supports($phpIssue));

        $yamlIssue = $this->createIssue('config/services.yaml');
        self::assertFalse($this->fixer->supports($yamlIssue));
    }
}
