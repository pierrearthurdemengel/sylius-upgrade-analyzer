<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ClassMoveFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de classes deplacees entre bundles dans Sylius 2.0.
 */
final class ClassMoveFixerTest extends TestCase
{
    private ClassMoveFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ClassMoveFixer();
        $this->tempDir = sys_get_temp_dir() . '/class-move-fixer-test-' . uniqid();
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

    private function createIssue(string $file): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'Class Move',
            message: sprintf('Classe deplacee detectee dans %s', $file),
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
    public function testReplacesOldFqcnWithNew(): void
    {
        $filePath = $this->tempDir . '/src/Controller/ContactController.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Bundle\\ShopBundle\\EmailManager\\ContactEmailManager;\n\nclass ContactController\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue('src/Controller/ContactController.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('use Sylius\\Bundle\\CoreBundle\\Mailer\\ContactEmailManager;', $fix->fixedContent);
        self::assertStringNotContainsString('Sylius\\Bundle\\ShopBundle\\EmailManager\\ContactEmailManager', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOldFqcn(): void
    {
        $filePath = $this->tempDir . '/src/Service/Clean.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Bundle\\CoreBundle\\Mailer\\ContactEmailManager;\n\nclass Clean\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue('src/Service/Clean.php'), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsOnlyPhpFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Service/Test.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('config/services.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/base.html.twig')));
    }

    #[Test]
    public function testReplacesMultipleClassMovesInSameFile(): void
    {
        $filePath = $this->tempDir . '/src/Service/ShippingService.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Bundle\\AdminBundle\\EmailManager\\ShipmentEmailManager;\nuse Sylius\\Bundle\\AdminBundle\\EmailManager\\ShipmentEmailManagerInterface;\n\nclass ShippingService\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue('src/Service/ShippingService.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('Sylius\\Bundle\\CoreBundle\\Mailer\\ShipmentEmailManager', $fix->fixedContent);
        self::assertStringContainsString('Sylius\\Bundle\\CoreBundle\\Mailer\\ShipmentEmailManagerInterface', $fix->fixedContent);
        self::assertStringNotContainsString('Sylius\\Bundle\\AdminBundle\\EmailManager', $fix->fixedContent);
    }
}
