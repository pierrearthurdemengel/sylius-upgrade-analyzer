<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedClassFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de classes supprimees dans Sylius 2.0.
 */
final class RemovedClassFixerTest extends TestCase
{
    private RemovedClassFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new RemovedClassFixer();
        $this->tempDir = sys_get_temp_dir() . '/removed-class-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src', 0755, true);
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

    private function createIssue(string $file, int $line = 5): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'Removed Class',
            message: sprintf('Classe supprimee detectee dans %s ligne %d', $file, $line),
            detail: '',
            suggestion: '',
            file: $file,
            line: $line,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 60,
        );
    }

    #[Test]
    public function testCommentsOutUseStatementOfRemovedClass(): void
    {
        $filePath = $this->tempDir . '/src/Service/BasketService.php';
        mkdir(dirname($filePath), 0755, true);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nuse Sylius\\Bundle\\CoreBundle\\Templating\\Helper\\BasketHelper;\n\nclass BasketService\n{\n}\n";
        file_put_contents($filePath, $content);

        $fix = $this->fixer->fix($this->createIssue('src/Service/BasketService.php', 5), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('// TODO: classe supprimee dans Sylius 2.0', $fix->fixedContent);
        self::assertStringContainsString('use Sylius\\Bundle\\CoreBundle\\Templating\\Helper\\BasketHelper;', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenLineIsNotUseStatement(): void
    {
        $filePath = $this->tempDir . '/src/Service/Other.php';
        mkdir(dirname($filePath), 0755, true);
        $content = "<?php\n\ndeclare(strict_types=1);\n\n\$basket = new BasketHelper();\n\nclass Other\n{\n}\n";
        file_put_contents($filePath, $content);

        $fix = $this->fixer->fix($this->createIssue('src/Service/Other.php', 5), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsOnlyPhpFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Service/Test.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('config/services.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/base.html.twig')));
    }
}
