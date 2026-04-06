<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\CalendarToClockFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer Calendar vers Clock (sylius/calendar → symfony/clock).
 */
final class CalendarToClockFixerTest extends TestCase
{
    private CalendarToClockFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new CalendarToClockFixer();
        $this->tempDir = sys_get_temp_dir() . '/calendar-clock-fixer-test-' . uniqid();
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
            analyzer: 'Calendar to Clock',
            message: sprintf('Reference a sylius/calendar detectee dans %s', $file),
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
    public function testReplacesDateTimeProviderInterfaceWithClockInterface(): void
    {
        $filePath = $this->tempDir . '/src/Service/DateService.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Calendar\\Provider\\DateTimeProviderInterface;\n\nclass DateService\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue('src/Service/DateService.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('use Symfony\\Component\\Clock\\ClockInterface;', $fix->fixedContent);
        self::assertStringNotContainsString('Sylius\\Calendar\\Provider\\DateTimeProviderInterface', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoCalendarReferences(): void
    {
        $filePath = $this->tempDir . '/src/Service/Clean.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\nuse Symfony\\Component\\Clock\\ClockInterface;\n\nclass Clean\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue('src/Service/Clean.php'), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsPhpFilesNotYaml(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Service/DateService.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/base.html.twig')));
    }

    #[Test]
    public function testReplacesDateTimeProviderWithNativeClock(): void
    {
        $filePath = $this->tempDir . '/src/Provider/TimeProvider.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\nuse Sylius\\Calendar\\Provider\\DateTimeProvider;\n\nclass TimeProvider\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue('src/Provider/TimeProvider.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('Symfony\\Component\\Clock\\NativeClock', $fix->fixedContent);
        self::assertStringNotContainsString('Sylius\\Calendar\\Provider\\DateTimeProvider', $fix->fixedContent);
    }
}
