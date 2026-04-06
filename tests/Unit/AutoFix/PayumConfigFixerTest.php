<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\PayumConfigFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de configuration Payum.
 */
final class PayumConfigFixerTest extends TestCase
{
    private PayumConfigFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new PayumConfigFixer();
        $this->tempDir = sys_get_temp_dir() . '/payum-config-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config/packages', 0755, true);
        mkdir($this->tempDir . '/src/Payment', 0755, true);
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
            analyzer: 'Payum',
            message: sprintf('Reference Payum obsolete dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 60,
        );
    }

    #[Test]
    public function testCommentsPayumYamlRootKey(): void
    {
        $file = 'config/packages/payum.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "payum:\n    gateways:\n        stripe: ~\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('# TODO: Payum est remplace par Payment Requests', $fix->fixedContent);
    }

    #[Test]
    public function testCommentsPayumUseStatementsInPhp(): void
    {
        $file = 'src/Payment/PaymentProcessor.php';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "<?php\n\nuse Payum\Core\Gateway;\nuse Payum\Core\Request\Capture;\n\nclass PaymentProcessor\n{\n}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('// TODO: Payum supprime dans Sylius 2.0', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoPayumReferences(): void
    {
        $file = 'config/packages/framework.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "framework:\n    secret: '%env(APP_SECRET)%'\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlAndPhpFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/payum.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('src/Payment.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/payment.html.twig')));
    }
}
