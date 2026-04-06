<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\PaymentRequestEnvFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer des variables d'environnement Payment Request.
 */
final class PaymentRequestEnvFixerTest extends TestCase
{
    private PaymentRequestEnvFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new PaymentRequestEnvFixer();
        $this->tempDir = sys_get_temp_dir() . '/payment-request-env-fixer-test-' . uniqid();
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
            severity: Severity::WARNING,
            category: Category::DEPRECATION,
            analyzer: 'Payment Request Env',
            message: sprintf('Variables d\'environnement Payment Request manquantes dans %s', $file),
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
    public function testAddsMessengerVarsToEnvFile(): void
    {
        $filePath = $this->tempDir . '/.env';
        file_put_contents($filePath, "APP_ENV=dev\nAPP_SECRET=abc123\n");

        $fix = $this->fixer->fix($this->createIssue('.env'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=doctrine://default', $fix->fixedContent);
        self::assertStringContainsString('SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN=doctrine://default?queue_name=payment_request_failed', $fix->fixedContent);
        self::assertStringContainsString('###> sylius/payment-request ###', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenVarsAlreadyPresent(): void
    {
        $filePath = $this->tempDir . '/.env';
        file_put_contents($filePath, "APP_ENV=dev\nSYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=doctrine://default\nSYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN=doctrine://default?queue_name=payment_request_failed\n");

        $fix = $this->fixer->fix($this->createIssue('.env'), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsAnyFileWithIssue(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('.env')));
        self::assertTrue($this->fixer->supports($this->createIssue('config/packages/messenger.yaml')));
    }

    #[Test]
    public function testReturnsNullWhenEnvFileDoesNotExist(): void
    {
        /* Pas de fichier .env cree dans le tempDir */
        $fix = $this->fixer->fix($this->createIssue('.env'), $this->tempDir);

        self::assertNull($fix);
    }
}
