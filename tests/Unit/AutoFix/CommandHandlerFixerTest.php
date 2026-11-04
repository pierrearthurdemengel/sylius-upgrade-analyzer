<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\CommandHandlerFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests du remplacement des espaces de noms Message* de Sylius par Command*.
 */
final class CommandHandlerFixerTest extends TestCase
{
    private const FILE = 'src/Controller/OrderController.php';

    private CommandHandlerFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new CommandHandlerFixer();
        $this->tempDir = sys_get_temp_dir() . '/command-rename-fixer-' . uniqid('', true);
        mkdir($this->tempDir . '/src/Controller', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempDir . '/' . self::FILE)) {
            unlink($this->tempDir . '/' . self::FILE);
        }
        rmdir($this->tempDir . '/src/Controller');
        rmdir($this->tempDir . '/src');
        rmdir($this->tempDir);
    }

    private function issue(): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'Command Handler Rename',
            message: 'Classe deplacee',
            detail: '',
            suggestion: '',
            file: self::FILE,
        );
    }

    #[Test]
    public function testReplacesSyliusNamespacesOnly(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, "<?php\n\nnamespace App\\Controller;\n\nuse App\\Message\\SendNewsletter;\nuse Sylius\\Bundle\\CoreBundle\\Message\\ResendOrderConfirmationEmail;\nuse Sylius\\Bundle\\CoreBundle\\MessageDispatcher\\ResendShipmentConfirmationEmailDispatcherInterface;\n");

        $fix = $this->fixer->fix($this->issue(), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertSame(
            "<?php\n\nnamespace App\\Controller;\n\nuse App\\Message\\SendNewsletter;\nuse Sylius\\Bundle\\CoreBundle\\Command\\ResendOrderConfirmationEmail;\nuse Sylius\\Bundle\\CoreBundle\\CommandDispatcher\\ResendShipmentConfirmationEmailDispatcherInterface;\n",
            $fix->fixedContent,
        );
    }

    #[Test]
    public function testReplacesEscapedNamespacesInStrings(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, "<?php\n\n\$class = 'Sylius\\\\Bundle\\\\AdminBundle\\\\MessageHandler\\\\Notification\\\\SendNotificationHandler';\n");

        $fix = $this->fixer->fix($this->issue(), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString("'Sylius\\\\Bundle\\\\AdminBundle\\\\CommandHandler\\\\Notification\\\\SendNotificationHandler'", $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWithoutSyliusMessageNamespaces(): void
    {
        file_put_contents($this->tempDir . '/' . self::FILE, "<?php\n\nnamespace App\\Message;\n\nfinal class SendNewsletter\n{\n}\n");

        self::assertNull($this->fixer->fix($this->issue(), $this->tempDir));
    }
}
