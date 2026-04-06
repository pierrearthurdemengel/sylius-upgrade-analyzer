<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\BehatContextFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de contextes Behat deprecies.
 */
final class BehatContextFixerTest extends TestCase
{
    private BehatContextFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new BehatContextFixer();
        $this->tempDir = sys_get_temp_dir() . '/behat-context-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config/behat', 0755, true);
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
            analyzer: 'Behat Context Deprecation',
            message: sprintf('Contexte Behat deprecie detecte dans %s', $file),
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
    public function testReplacesCalendarContextWithClock(): void
    {
        $file = 'config/behat/suites.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "suites:\n    default:\n        contexts:\n            - sylius.behat.context.hook.calendar\n            - sylius.behat.context.setup.calendar\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('sylius.behat.context.hook.clock', $fix->fixedContent);
        self::assertStringContainsString('sylius.behat.context.setup.clock', $fix->fixedContent);
        self::assertStringNotContainsString('sylius.behat.context.hook.calendar', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoDeprecatedContexts(): void
    {
        $file = 'config/behat/suites.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "suites:\n    default:\n        contexts:\n            - sylius.behat.context.hook.clock\n            - app.behat.context.custom\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlFilesOnly(): void
    {
        $yamlIssue = $this->createIssue('config/behat/suites.yaml');
        self::assertTrue($this->fixer->supports($yamlIssue));

        $ymlIssue = $this->createIssue('config/behat/suites.yml');
        self::assertTrue($this->fixer->supports($ymlIssue));

        $phpIssue = $this->createIssue('src/Behat/Context.php');
        self::assertFalse($this->fixer->supports($phpIssue));
    }

    #[Test]
    public function testReplacesCalendarFqcnWithClock(): void
    {
        $file = 'config/behat/suites.yml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "suites:\n    default:\n        contexts:\n            - Sylius\\Behat\\Context\\Hook\\CalendarContext\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('Sylius\\Behat\\Context\\Hook\\ClockContext', $fix->fixedContent);
        self::assertStringNotContainsString('CalendarContext', $fix->fixedContent);
    }
}
