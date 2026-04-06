<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RenamedServiceIdFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer d'identifiants de services renommés.
 */
final class RenamedServiceIdFixerTest extends TestCase
{
    private RenamedServiceIdFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new RenamedServiceIdFixer();
        $this->tempDir = sys_get_temp_dir() . '/service-id-fixer-test-' . uniqid();
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
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'Renamed Service ID',
            message: sprintf('Identifiant de service obsolete detecte dans %s', $file),
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
    public function testReplacesOldServiceId(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    app.service:\n        arguments:\n            - '@sylius.twig.extension.sort_by'\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('sylius_twig_extra.twig.extension.sort_by', $fix->fixedContent);
        self::assertStringNotContainsString('sylius.twig.extension.sort_by', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesServicePrefix(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    sylius.controller.admin.dashboard:\n        class: App\\Controller\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('sylius_admin.controller.dashboard', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoServiceIdsToReplace(): void
    {
        $file = 'config/services.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "services:\n    app.my_service:\n        class: App\\Service\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlFiles(): void
    {
        $issue = $this->createIssue('config/services.yaml');
        self::assertTrue($this->fixer->supports($issue));
    }

    #[Test]
    public function testDoesNotSupportPhpFiles(): void
    {
        $issue = $this->createIssue('src/Controller.php');
        self::assertFalse($this->fixer->supports($issue));
    }
}
