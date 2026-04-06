<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedPaymentGatewayFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de passerelles de paiement supprimees.
 */
final class RemovedPaymentGatewayFixerTest extends TestCase
{
    private RemovedPaymentGatewayFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new RemovedPaymentGatewayFixer();
        $this->tempDir = sys_get_temp_dir() . '/removed-payment-gateway-fixer-test-' . uniqid();
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
            analyzer: 'Removed Payment Gateway',
            message: sprintf('Passerelle de paiement supprimee dans %s', $file),
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
    public function testCommentsStripeCheckoutGateway(): void
    {
        $file = 'config/gateways.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "gateways:\n    stripe_checkout:\n        factory_name: stripe_checkout\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('# TODO: gateway supprimee dans Sylius 2.0', $fix->fixedContent);
    }

    #[Test]
    public function testCommentsPaypalExpressCheckout(): void
    {
        $file = 'config/gateways.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "gateways:\n    paypal_express_checkout:\n        factory_name: paypal_express_checkout\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('# TODO: gateway supprimee dans Sylius 2.0', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoRemovedGateways(): void
    {
        $file = 'config/gateways.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "gateways:\n    mollie:\n        factory_name: mollie\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsYamlAndPhpFiles(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/gateways.yaml')));
        self::assertTrue($this->fixer->supports($this->createIssue('src/Gateway.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/payment.html.twig')));
    }
}
