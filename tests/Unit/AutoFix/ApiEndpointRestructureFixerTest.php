<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiEndpointRestructureFixer;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de restructuration des endpoints API Sylius 2.0.
 */
final class ApiEndpointRestructureFixerTest extends TestCase
{
    private ApiEndpointRestructureFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new ApiEndpointRestructureFixer();
        $this->tempDir = sys_get_temp_dir() . '/api-endpoint-fixer-test-' . uniqid();
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
            category: Category::API,
            analyzer: 'API Endpoint Restructure',
            message: sprintf('Endpoint API obsolete detecte dans %s', $file),
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
    public function testReplacesOldAvatarImagesEndpoint(): void
    {
        $filePath = $this->tempDir . '/src/Controller/AdminController.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\n\$url = '/api/v2/admin/avatar-images/';\n");

        $fix = $this->fixer->fix($this->createIssue('src/Controller/AdminController.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('/api/v2/admin/administrators/{id}/avatar-image', $fix->fixedContent);
        self::assertStringNotContainsString('/api/v2/admin/avatar-images/', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOldEndpoints(): void
    {
        $filePath = $this->tempDir . '/src/Controller/ShopController.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\n\$url = '/api/v2/shop/products';\n");

        $fix = $this->fixer->fix($this->createIssue('src/Controller/ShopController.php'), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsAnyFileWithIssue(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('src/Controller/Test.php')));
        self::assertTrue($this->fixer->supports($this->createIssue('tests/Api/ApiTest.php')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/routes.yaml')));
    }

    #[Test]
    public function testReplacesResetPasswordEndpoint(): void
    {
        $filePath = $this->tempDir . '/src/Controller/AuthController.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\n\$url = '/api/v2/shop/reset-password-requests';\n");

        $fix = $this->fixer->fix($this->createIssue('src/Controller/AuthController.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('/api/v2/shop/reset-password', $fix->fixedContent);
        self::assertStringNotContainsString('/api/v2/shop/reset-password-requests', $fix->fixedContent);
    }
}
