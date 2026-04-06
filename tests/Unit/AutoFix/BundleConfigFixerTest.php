<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\BundleConfigFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de config/bundles.php (bundles obsoletes Sylius 2.0).
 */
final class BundleConfigFixerTest extends TestCase
{
    private BundleConfigFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new BundleConfigFixer();
        $this->tempDir = sys_get_temp_dir() . '/bundle-config-fixer-test-' . uniqid();
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
            analyzer: 'Bundle Configuration',
            message: sprintf('Configuration de bundle obsolete dans %s', $file),
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
    public function testRemovesObsoleteBundleFromBundlesPhp(): void
    {
        $filePath = $this->tempDir . '/config/bundles.php';
        file_put_contents($filePath, "<?php\n\nreturn [\n    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ['all' => true],\n    winzou\\Bundle\\StateMachineBundle\\winzouStateMachineBundle::class => ['all' => true],\n];\n");

        $fix = $this->fixer->fix($this->createIssue('config/bundles.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringNotContainsString('winzouStateMachineBundle', $fix->fixedContent);
        self::assertStringContainsString('FrameworkBundle', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenBundlesPhpAlreadyClean(): void
    {
        $filePath = $this->tempDir . '/config/bundles.php';
        $content = "<?php\n\nreturn [\n    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ['all' => true],\n    Sylius\\TwigHooks\\SyliusTwigHooksBundle::class => ['all' => true],\n    Symfony\\UX\\TwigComponent\\TwigComponentBundle::class => ['all' => true],\n    Symfony\\UX\\StimulusBundle\\StimulusBundle::class => ['all' => true],\n    Symfony\\UX\\LiveComponent\\LiveComponentBundle::class => ['all' => true],\n    Symfony\\UX\\Autocomplete\\AutocompleteBundle::class => ['all' => true],\n];\n";
        file_put_contents($filePath, $content);

        $fix = $this->fixer->fix($this->createIssue('config/bundles.php'), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsBundlesPhpOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/bundles.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('config/services.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Kernel.php')));
    }

    #[Test]
    public function testAddsMissingBundles(): void
    {
        $filePath = $this->tempDir . '/config/bundles.php';
        file_put_contents($filePath, "<?php\n\nreturn [\n    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ['all' => true],\n];\n");

        $fix = $this->fixer->fix($this->createIssue('config/bundles.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('SyliusTwigHooksBundle', $fix->fixedContent);
        self::assertStringContainsString('TwigComponentBundle', $fix->fixedContent);
        self::assertStringContainsString('StimulusBundle', $fix->fixedContent);
    }
}
