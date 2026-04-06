<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedConfigKeyFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de cles de configuration supprimees.
 */
final class RemovedConfigKeyFixerTest extends TestCase
{
    private RemovedConfigKeyFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new RemovedConfigKeyFixer();
        $this->tempDir = sys_get_temp_dir() . '/removed-config-key-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config/packages', 0755, true);
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

    private function createIssue(string $file, int $line): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::DEPRECATION,
            analyzer: 'Removed Config Key',
            message: sprintf('Cle de configuration supprimee dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: $line,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 15,
        );
    }

    #[Test]
    public function testCommentsAutoconfigureWithAttributesKey(): void
    {
        $file = 'config/packages/sylius.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_resource:\n    autoconfigure_with_attributes: true\n    other_key: value\n");

        $fix = $this->fixer->fix($this->createIssue($file, 2), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('# TODO: cle supprimee dans Sylius 2.0', $fix->fixedContent);
        self::assertStringContainsString('other_key: value', $fix->fixedContent);
    }

    #[Test]
    public function testCommentsStateMachineKey(): void
    {
        $file = 'config/packages/sylius.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_order:\n    state_machine: winzou\n");

        $fix = $this->fixer->fix($this->createIssue($file, 2), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('# TODO: cle supprimee dans Sylius 2.0', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenLineDoesNotContainToken(): void
    {
        $file = 'config/packages/sylius.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius_resource:\n    driver: doctrine_orm\n");

        $fix = $this->fixer->fix($this->createIssue($file, 2), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testReturnsNullWithoutLine(): void
    {
        $issue = new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::DEPRECATION,
            analyzer: 'Removed Config Key',
            message: 'Cle supprimee',
            detail: '',
            suggestion: '',
            file: 'config/sylius.yaml',
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 15,
        );

        $fix = $this->fixer->fix($issue, $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlFilesOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/sylius.yaml', 1)));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Kernel.php', 1)));
    }
}
