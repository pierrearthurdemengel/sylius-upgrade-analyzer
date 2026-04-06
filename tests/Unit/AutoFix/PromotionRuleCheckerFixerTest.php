<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\PromotionRuleCheckerFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de PromotionRuleChecker.
 */
final class PromotionRuleCheckerFixerTest extends TestCase
{
    private PromotionRuleCheckerFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new PromotionRuleCheckerFixer();
        $this->tempDir = sys_get_temp_dir() . '/promotion-rule-checker-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src/Promotion', 0755, true);
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
            analyzer: 'Promotion Rule Checker',
            message: sprintf('CartQuantityRuleChecker obsolete dans %s', $file),
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
    public function testReplacesCartQuantityRuleCheckerNamespace(): void
    {
        $file = 'src/Promotion/CustomChecker.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Sylius\Component\Promotion\Checker\Rule\CartQuantityRuleChecker;\n\nclass CustomChecker extends CartQuantityRuleChecker\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('Sylius\Component\Core\Promotion\Checker\Rule\CartQuantityRuleChecker', $fix->fixedContent);
        self::assertStringNotContainsString('Sylius\Component\Promotion\Checker\Rule\CartQuantityRuleChecker', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenAlreadyUsingNewNamespace(): void
    {
        $file = 'src/Promotion/ModernChecker.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Sylius\Component\Core\Promotion\Checker\Rule\CartQuantityRuleChecker;\n\nclass ModernChecker extends CartQuantityRuleChecker\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsPhpAndYamlFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Checker.php')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/services.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/promo.html.twig')));
    }
}
