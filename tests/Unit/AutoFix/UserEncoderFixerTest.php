<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\UserEncoderFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer encoders → password_hashers dans security.yaml.
 */
final class UserEncoderFixerTest extends TestCase
{
    private UserEncoderFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new UserEncoderFixer();
        $this->tempDir = sys_get_temp_dir() . '/user-encoder-fixer-test-' . uniqid();
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

    private function createIssue(string $file): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::DEPRECATION,
            analyzer: 'User Encoder',
            message: sprintf('Configuration encoder detectee dans %s', $file),
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
    public function testReplacesEncodersWithPasswordHashers(): void
    {
        $filePath = $this->tempDir . '/config/packages/security.yaml';
        file_put_contents($filePath, "security:\n    encoders:\n        App\\Entity\\User:\n            algorithm: bcrypt\n");

        $fix = $this->fixer->fix($this->createIssue('config/packages/security.yaml'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('password_hashers:', $fix->fixedContent);
        self::assertStringNotContainsString('encoders:', $fix->fixedContent);
        self::assertStringContainsString('algorithm: auto', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenAlreadyUsingPasswordHashers(): void
    {
        $filePath = $this->tempDir . '/config/packages/security.yaml';
        file_put_contents($filePath, "security:\n    password_hashers:\n        App\\Entity\\User:\n            algorithm: auto\n");

        $fix = $this->fixer->fix($this->createIssue('config/packages/security.yaml'), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlAndPhpFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/packages/security.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/packages/security.yml')));
        self::assertTrue($this->fixer->supports($this->createIssue('src/Entity/User.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/base.html.twig')));
    }

    #[Test]
    public function testReplacesArgon2iWithAuto(): void
    {
        $filePath = $this->tempDir . '/config/packages/security.yaml';
        file_put_contents($filePath, "security:\n    encoders:\n        App\\Entity\\Admin:\n            algorithm: argon2i\n");

        $fix = $this->fixer->fix($this->createIssue('config/packages/security.yaml'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('algorithm: auto', $fix->fixedContent);
        self::assertStringNotContainsString('algorithm: argon2i', $fix->fixedContent);
    }
}
