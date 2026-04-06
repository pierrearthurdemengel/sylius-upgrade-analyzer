<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\DeprecatedEmailManagerFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer d'EmailManager deprecie.
 */
final class DeprecatedEmailManagerFixerTest extends TestCase
{
    private DeprecatedEmailManagerFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new DeprecatedEmailManagerFixer();
        $this->tempDir = sys_get_temp_dir() . '/deprecated-email-manager-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src/Controller', 0755, true);
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
            analyzer: 'Deprecated Email Manager',
            message: sprintf('EmailManager deprecie detecte dans %s', $file),
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
    public function testReplacesShopBundleEmailManagerNamespace(): void
    {
        $file = 'src/Controller/OrderController.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Bundle\\ShopBundle\\EmailManager\\OrderEmailManagerInterface;\n\nclass OrderController\n{\n    public function __construct(private OrderEmailManagerInterface \$emailManager)\n    {\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('Sylius\\Bundle\\CoreBundle\\Mailer\\OrderEmailManagerInterface', $fix->fixedContent);
        self::assertStringNotContainsString('Sylius\\Bundle\\ShopBundle\\EmailManager', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOldNamespace(): void
    {
        $file = 'src/Controller/ProductController.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Bundle\\CoreBundle\\Mailer\\OrderEmailManagerInterface;\n\nclass ProductController\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testReplacesAdminBundleShipmentEmailManager(): void
    {
        $file = 'src/Controller/ShipmentController.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Bundle\\AdminBundle\\EmailManager\\ShipmentEmailManagerInterface;\n\nclass ShipmentController\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('Sylius\\Bundle\\CoreBundle\\Mailer\\ShipmentEmailManagerInterface', $fix->fixedContent);
        self::assertStringNotContainsString('Sylius\\Bundle\\AdminBundle\\EmailManager', $fix->fixedContent);
    }

    #[Test]
    public function testSupportsPhpFilesOnly(): void
    {
        $phpIssue = $this->createIssue('src/Controller/OrderController.php');
        self::assertTrue($this->fixer->supports($phpIssue));

        $yamlIssue = $this->createIssue('config/services.yaml');
        self::assertFalse($this->fixer->supports($yamlIssue));
    }
}
