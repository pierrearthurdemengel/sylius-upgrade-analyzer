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
use Symfony\Component\Yaml\Yaml;

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
        /* Tout le bloc est commente : factory_name ne doit pas remonter sous gateways */
        self::assertSame(
            "gateways:\n    # TODO: passerelle supprimee du coeur dans Sylius 2.0, a remplacer par un plugin de paiement\n"
            . "    # stripe_checkout:\n    #     factory_name: stripe_checkout\n",
            $fix->fixedContent,
        );
        self::assertSame(['gateways' => null], Yaml::parse($fix->fixedContent));
    }

    #[Test]
    public function testCommentsPaypalExpressCheckout(): void
    {
        $file = 'config/gateways.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "gateways:\n    paypal_express_checkout:\n        factory_name: paypal_express_checkout\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('# paypal_express_checkout:', $fix->fixedContent);
        self::assertStringContainsString('#     factory_name: paypal_express_checkout', $fix->fixedContent);
    }

    #[Test]
    public function testKeepsOtherGatewaysIntact(): void
    {
        $file = 'config/gateways.yaml';
        file_put_contents(
            $this->tempDir . '/' . $file,
            "gateways:\n    stripe_checkout:\n        factory_name: stripe_checkout\n\n        config: { publishable_key: pk }\n    offline:\n        factory_name: offline\n",
        );

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(['gateways' => ['offline' => ['factory_name' => 'offline']]], Yaml::parse($fix->fixedContent));
    }

    #[Test]
    public function testHandlesWindowsLineEndings(): void
    {
        $file = 'config/gateways.yaml';
        file_put_contents($this->tempDir . '/' . $file, "gateways:\r\n    stripe_checkout:\r\n        factory_name: stripe_checkout\r\n    offline:\r\n        factory_name: offline\r\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(['gateways' => ['offline' => ['factory_name' => 'offline']]], Yaml::parse($fix->fixedContent));
        self::assertStringContainsString("    # stripe_checkout:\r\n", $fix->fixedContent);
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
    public function testSupportsYamlFilesOnly(): void
    {
        /* Aucun correctif ne touche au code PHP de paiement */
        self::assertTrue($this->fixer->supports($this->createIssue('config/gateways.yaml')));
        self::assertFalse($this->fixer->supports($this->createIssue('src/Gateway.php')));
        self::assertFalse($this->fixer->supports($this->createIssue('templates/payment.html.twig')));
    }
}
