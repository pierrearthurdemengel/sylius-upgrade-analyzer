<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\AdminMenuEventFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer d'evenements de menu admin.
 */
final class AdminMenuEventFixerTest extends TestCase
{
    private AdminMenuEventFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new AdminMenuEventFixer();
        $this->tempDir = sys_get_temp_dir() . '/admin-menu-event-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src/EventListener', 0755, true);
        mkdir($this->tempDir . '/config', 0755, true);
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
            analyzer: 'Admin Menu Event',
            message: sprintf('Evenement de menu admin deprecie dans %s', $file),
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
    public function testReplacesOldMenuEventInPhp(): void
    {
        $file = 'src/EventListener/AdminMenuListener.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nclass AdminMenuListener\n{\n    public static function getSubscribedEvents(): array\n    {\n        return ['sylius.menu.admin.main' => 'addAdminMenuItems'];\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('sylius_admin.menu.main', $fix->fixedContent);
        self::assertStringNotContainsString('sylius.menu.admin.main', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesOldMenuEventInYaml(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    app.listener.admin_menu:\n        tags:\n            - { name: kernel.event_listener, event: sylius.menu.admin.main }\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('sylius_admin.menu.main', $fix->fixedContent);
        self::assertStringNotContainsString('sylius.menu.admin.main', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOldEvents(): void
    {
        $file = 'src/EventListener/CustomListener.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nclass CustomListener\n{\n    public static function getSubscribedEvents(): array\n    {\n        return ['sylius_admin.menu.main' => 'addItems'];\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsPhpAndYamlFiles(): void
    {
        $phpIssue = $this->createIssue('src/EventListener/MenuListener.php');
        self::assertTrue($this->fixer->supports($phpIssue));

        $yamlIssue = $this->createIssue('config/services.yaml');
        self::assertTrue($this->fixer->supports($yamlIssue));

        $twigIssue = $this->createIssue('templates/admin.html.twig');
        self::assertFalse($this->fixer->supports($twigIssue));
    }
}
