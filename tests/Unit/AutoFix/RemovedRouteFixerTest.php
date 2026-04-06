<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedRouteFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de routes supprimees dans Sylius 2.0.
 */
final class RemovedRouteFixerTest extends TestCase
{
    private RemovedRouteFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new RemovedRouteFixer();
        $this->tempDir = sys_get_temp_dir() . '/removed-route-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/templates', 0755, true);
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

    private function createIssue(string $file, int $line = 5): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'Removed Route',
            message: sprintf('Route supprimee detectee dans %s ligne %d', $file, $line),
            detail: '',
            suggestion: '',
            file: $file,
            line: $line,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 30,
        );
    }

    #[Test]
    public function testCommentsRouteInPhpFile(): void
    {
        $filePath = $this->tempDir . '/src/Controller/ShopController.php';
        mkdir(dirname($filePath), 0755, true);
        $content = "<?php\n\nclass ShopController\n{\n    \$route = 'sylius_shop_cart_summary';\n}\n";
        file_put_contents($filePath, $content);

        $fix = $this->fixer->fix($this->createIssue('src/Controller/ShopController.php', 5), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('// TODO: route supprimee dans Sylius 2.0', $fix->fixedContent);
    }

    #[Test]
    public function testCommentsTwigRouteWithTwigSyntax(): void
    {
        $filePath = $this->tempDir . '/templates/shop/cart.html.twig';
        mkdir(dirname($filePath), 0755, true);
        $content = "{% extends 'base.html.twig' %}\n\n{% block body %}\n\n{{ path('sylius_shop_cart_summary') }}\n\n{% endblock %}\n";
        file_put_contents($filePath, $content);

        $fix = $this->fixer->fix($this->createIssue('templates/shop/cart.html.twig', 5), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('{# TODO: route supprimee dans Sylius 2.0', $fix->fixedContent);
        self::assertStringContainsString('#}', $fix->fixedContent);
    }

    #[Test]
    public function testSupportsPhpAndTwigOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Controller/Test.php')));
        self::assertTrue($this->fixer->supports($this->createIssue('templates/shop/cart.html.twig')));
        self::assertFalse($this->fixer->supports($this->createIssue('config/routes.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('composer.json')));
    }
}
