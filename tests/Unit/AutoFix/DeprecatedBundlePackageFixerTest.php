<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\DeprecatedBundlePackageFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de paquets deprecies dans composer.json.
 */
final class DeprecatedBundlePackageFixerTest extends TestCase
{
    private DeprecatedBundlePackageFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new DeprecatedBundlePackageFixer();
        $this->tempDir = sys_get_temp_dir() . '/deprecated-bundle-package-fixer-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
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
            analyzer: 'Deprecated Bundle Package',
            message: sprintf('Paquet deprecie detecte dans %s', $file),
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
    public function testRemovesDeprecatedPackageFromComposerJson(): void
    {
        $filePath = $this->tempDir . '/composer.json';
        file_put_contents($filePath, "{\n    \"require\": {\n        \"sylius/sylius\": \"^1.12\",\n        \"friendsofsymfony/rest-bundle\": \"^3.0\"\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue('composer.json'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringNotContainsString('friendsofsymfony/rest-bundle', $fix->fixedContent);
        self::assertStringContainsString('sylius/sylius', $fix->fixedContent);
        self::assertSame(['require' => ['sylius/sylius' => '^1.12']], json_decode($fix->fixedContent, true));
    }

    #[Test]
    public function testKeepsValidJsonWhenRemovingLastAndMiddleEntries(): void
    {
        $file = 'composer.json';
        file_put_contents($this->tempDir . '/' . $file, "{\n    \"require\": {\n        \"php\": \"^8.2\",\n        \"jms/serializer-bundle\": \"^4.0\",\n        \"sylius/sylius\": \"^1.14\",\n        \"sylius/calendar\": \"^0.5\"\n    },\n    \"minimum-stability\": \"stable\"\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(
            ['require' => ['php' => '^8.2', 'sylius/sylius' => '^1.14'], 'minimum-stability' => 'stable'],
            json_decode($fix->fixedContent, true),
        );
    }

    #[Test]
    public function testReturnsNullWhenNoDeprecatedPackages(): void
    {
        $filePath = $this->tempDir . '/composer.json';
        file_put_contents($filePath, "{\n    \"require\": {\n        \"sylius/sylius\": \"^2.0\",\n        \"symfony/framework-bundle\": \"^6.4\"\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue('composer.json'), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsComposerJsonOnly(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('composer.json')));
        self::assertFalse($this->fixer->supports($this->createIssue('config/services.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Kernel.php')));
    }

    #[Test]
    public function testRemovesMultipleDeprecatedPackages(): void
    {
        $filePath = $this->tempDir . '/composer.json';
        file_put_contents($filePath, "{\n    \"require\": {\n        \"sylius/sylius\": \"^1.12\",\n        \"friendsofsymfony/rest-bundle\": \"^3.0\",\n        \"jms/serializer-bundle\": \"^3.0\",\n        \"sylius/calendar\": \"^1.0\"\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue('composer.json'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringNotContainsString('friendsofsymfony/rest-bundle', $fix->fixedContent);
        self::assertStringNotContainsString('jms/serializer-bundle', $fix->fixedContent);
        self::assertStringNotContainsString('sylius/calendar', $fix->fixedContent);
        self::assertStringContainsString('sylius/sylius', $fix->fixedContent);
    }
}
