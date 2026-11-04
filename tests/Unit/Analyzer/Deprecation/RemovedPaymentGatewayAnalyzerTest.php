<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\RemovedPaymentGatewayAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des passerelles de paiement supprimees.
 * Verifie la detection de payum/stripe et payum/paypal-express-checkout.
 */
final class RemovedPaymentGatewayAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('gateway_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir((string) $item->getRealPath());
            } else {
                unlink((string) $item->getRealPath());
            }
        }

        rmdir($path);
    }

    private function createReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12',
            targetVersion: '2.0',
            projectPath: $this->tempDir,
        );
    }

    /**
     * Verifie que supports retourne false sans composer.json.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutComposerJson(): void
    {
        $analyzer = new RemovedPaymentGatewayAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne false quand composer.json ne contient pas les packages.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutPayum(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => ['sylius/sylius' => '^1.12'],
        ]));

        $analyzer = new RemovedPaymentGatewayAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand composer.json contient payum/stripe.
     */
    #[Test]
    public function testSupportsReturnsTrueWithPayumStripe(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => [
                'sylius/sylius' => '^1.12',
                'payum/stripe' => '^1.0',
            ],
        ]));

        $analyzer = new RemovedPaymentGatewayAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection de payum/stripe dans composer.json.
     */
    #[Test]
    public function testDetectsPayumStripePackage(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => [
                'payum/stripe' => '^1.0',
            ],
        ]));

        $analyzer = new RemovedPaymentGatewayAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $stripeIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'payum/stripe'),
        );
        self::assertNotEmpty($stripeIssues);
    }

    /**
     * Verifie que les problemes sont de severite BREAKING.
     */
    #[Test]
    public function testCreatesBreakingIssues(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => [
                'payum/stripe' => '^1.0',
            ],
        ]));

        $analyzer = new RemovedPaymentGatewayAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::BREAKING, $issue->getSeverity());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new RemovedPaymentGatewayAnalyzer();

        self::assertSame('Removed Payment Gateway', $analyzer->getName());
    }

    /**
     * Verifie que supports retourne true quand le package PayPal est declare en require-dev.
     */
    #[Test]
    public function testSupportsReturnsTrueWithPaypalInRequireDev(): void
    {
        $this->writeComposerJson([
            'require' => ['sylius/sylius' => '^1.12'],
            'require-dev' => ['payum/paypal-express-checkout' => '^1.6'],
        ]);

        self::assertTrue((new RemovedPaymentGatewayAnalyzer())->supports($this->createReport()));
    }

    /**
     * Verifie que supports retourne false quand composer.json est invalide.
     */
    #[Test]
    public function testSupportsReturnsFalseWithInvalidComposerJson(): void
    {
        $this->writeFile('composer.json', '{"require": {');

        self::assertFalse((new RemovedPaymentGatewayAnalyzer())->supports($this->createReport()));
    }

    /**
     * Verifie qu'aucune issue n'est produite quand composer.json est invalide.
     */
    #[Test]
    public function testReturnsNoIssuesWithInvalidComposerJson(): void
    {
        $this->writeFile('composer.json', 'not json');

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que chaque package supprime produit une issue indiquant sa contrainte de version.
     */
    #[Test]
    public function testDetectsBothRemovedPackagesWithVersion(): void
    {
        $composerPath = $this->writeComposerJson([
            'require' => [
                'sylius/sylius' => '~1.12.0',
                'payum/stripe' => '^1.7',
            ],
            'require-dev' => [
                'payum/paypal-express-checkout' => '^1.6',
            ],
        ]);

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        $stripe = $this->issuesWithMessage($report, 'Dependance payum/stripe (^1.7)');
        $paypal = $this->issuesWithMessage($report, 'Dependance payum/paypal-express-checkout (^1.6)');

        self::assertCount(1, $stripe);
        self::assertCount(1, $paypal);
        self::assertStringContainsString('Stripe via Payum', $stripe[0]->getDetail());
        self::assertStringContainsString('PayPal Express Checkout via Payum', $paypal[0]->getDetail());
        self::assertSame(realpath($composerPath), realpath((string) $stripe[0]->getFile()));
        self::assertSame(Category::DEPRECATION, $paypal[0]->getCategory());
    }

    /**
     * Verifie que l'issue de synthese estime 480 minutes par passerelle supprimee.
     */
    #[Test]
    public function testSummaryEstimatesEightHoursPerGateway(): void
    {
        $this->writeComposerJson([
            'require' => [
                'payum/stripe' => '^1.7',
                'payum/paypal-express-checkout' => '^1.6',
            ],
        ]);

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        $summary = $this->issuesWithMessage($report, '2 passerelle(s) de paiement supprimee(s) detectee(s)');

        self::assertCount(1, $summary);
        self::assertSame(960, $summary[0]->getEstimatedMinutes());
        self::assertSame(Severity::BREAKING, $summary[0]->getSeverity());
        self::assertCount(3, $report->getIssues());
    }

    /**
     * Verifie qu'aucune issue n'est produite pour un projet sans passerelle supprimee.
     */
    #[Test]
    public function testReturnsNoIssuesWithoutRemovedGateway(): void
    {
        $this->writeComposerJson(['require' => ['sylius/sylius' => '^1.12', 'sylius/paypal-plugin' => '^1.5']]);
        $this->writeFile('config/packages/payum.yaml', <<<'YAML'
payum:
    gateways:
        offline:
            factory: offline
YAML);
        $this->writeFile('src/Payment/OfflineAction.php', "<?php\nnamespace App\\Payment;\nfinal class OfflineAction {}\n");

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection de la factory paypal_express_checkout dans la configuration Payum.
     */
    #[Test]
    public function testDetectsPaypalExpressCheckoutFactoryInConfiguration(): void
    {
        $configFile = $this->writeFile('config/packages/payum.yaml', <<<'YAML'
payum:
    gateways:
        paypal:
            factory: paypal_express_checkout
            username: '%env(PAYPAL_USERNAME)%'
            password: '%env(PAYPAL_PASSWORD)%'
            signature: '%env(PAYPAL_SIGNATURE)%'
            sandbox: true
YAML);

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Configuration de gateway "paypal_express_checkout"');

        self::assertCount(1, $issues);
        self::assertStringContainsString('payum.yaml', $issues[0]->getMessage());
        self::assertSame(realpath($configFile), realpath((string) $issues[0]->getFile()));
        self::assertStringContainsString('paypal_express_checkout', $issues[0]->getSuggestion());
        self::assertCount(1, $this->issuesWithMessage($report, '1 passerelle(s) de paiement supprimee(s)'));
    }

    /**
     * Verifie que chaque factory Stripe presente dans un meme fichier produit sa propre issue.
     */
    #[Test]
    public function testDetectsEachStripeFactoryInSameFile(): void
    {
        $this->writeFile('config/packages/payum.yml', <<<'YAML'
payum:
    gateways:
        stripe_checkout_gateway:
            factory: stripe_checkout
            publishable_key: '%env(STRIPE_PUBLISHABLE_KEY)%'
            secret_key: '%env(STRIPE_SECRET_KEY)%'
        stripe_js_gateway:
            factory: stripe_js
            publishable_key: '%env(STRIPE_PUBLISHABLE_KEY)%'
            secret_key: '%env(STRIPE_SECRET_KEY)%'
YAML);

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Configuration de gateway "stripe_checkout"'));
        self::assertCount(1, $this->issuesWithMessage($report, 'Configuration de gateway "stripe_js"'));
        self::assertSame(
            960,
            $this->issuesWithMessage($report, '2 passerelle(s) de paiement supprimee(s)')[0]->getEstimatedMinutes(),
        );
    }

    /**
     * Verifie la detection d'une classe important un composant Payum Stripe, avec la ligne de l'import.
     */
    #[Test]
    public function testDetectsClassImportingPayumStripe(): void
    {
        $file = $this->writeFile('src/Payment/Stripe/ConvertPaymentAction.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Payment\Stripe;

use Payum\Core\Action\ActionInterface;
use Payum\Stripe\Constants;

final class ConvertPaymentAction implements ActionInterface
{
    public function execute($request): void
    {
    }

    public function supports($request): bool
    {
        return Constants::STATUS_SUCCEEDED !== '';
    }
}
PHP);

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Classe utilisant une passerelle Payum supprimee');

        self::assertCount(1, $issues);
        self::assertSame(8, $issues[0]->getLine());
        self::assertSame(realpath($file), realpath((string) $issues[0]->getFile()));
        self::assertStringContainsString('Payum\\Stripe\\Constants', $issues[0]->getDetail());
        self::assertStringContainsString('ligne 8', $issues[0]->getDetail());
    }

    /**
     * Verifie la detection d'une classe heritant d'une classe PayPal referencee par son nom complet.
     */
    #[Test]
    public function testDetectsClassExtendingPaypalClassWithoutImport(): void
    {
        $this->writeFile('src/Payment/PaypalApi.php', <<<'PHP'
<?php

namespace App\Payment;

class PaypalApi extends \Payum\Paypal\ExpressCheckout\Nvp\Api
{
}
PHP);

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Classe utilisant une passerelle Payum supprimee');

        self::assertCount(1, $issues);
        self::assertSame(5, $issues[0]->getLine());
        self::assertStringContainsString('Payum\\Paypal\\ExpressCheckout\\Nvp\\Api', $issues[0]->getDetail());
    }

    /**
     * Verifie qu'un fichier avec plusieurs usages Payum ne produit qu'une issue, sur le premier usage.
     */
    #[Test]
    public function testReportsOnlyFirstUsagePerFile(): void
    {
        $this->writeFile('src/Payment/PaypalCaptureAction.php', <<<'PHP'
<?php

namespace App\Payment;

use Payum\Paypal\ExpressCheckout\Nvp\Action\CaptureAction;
use Payum\Paypal\ExpressCheckout\Nvp\Api;

final class PaypalCaptureAction extends CaptureAction
{
}
PHP);

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Classe utilisant une passerelle Payum supprimee');

        self::assertCount(1, $issues);
        self::assertSame(5, $issues[0]->getLine());
        self::assertStringContainsString('CaptureAction', $issues[0]->getDetail());
        self::assertCount(1, $this->issuesWithMessage($report, '1 passerelle(s) de paiement supprimee(s)'));
    }

    /**
     * Verifie qu'un fichier PHP invalide est ignore sans interrompre l'analyse.
     */
    #[Test]
    public function testIgnoresUnparsablePhpFile(): void
    {
        $this->writeFile('src/Payment/Broken.php', "<?php\nuse Payum\\Stripe\\Api;\nclass Broken {\n");

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que la synthese cumule dependances, configurations et classes.
     */
    #[Test]
    public function testSummaryAggregatesComposerConfigurationAndClasses(): void
    {
        $this->writeComposerJson(['require' => ['payum/stripe' => '^1.7']]);
        $this->writeFile('config/packages/payum.yaml', "payum:\n    gateways:\n        stripe:\n            factory: stripe_checkout\n");
        $this->writeFile('src/Payment/StripeAction.php', "<?php\nnamespace App\\Payment;\nuse Payum\\Stripe\\Request\\Api\\CreateCharge;\nfinal class StripeAction {}\n");

        $report = $this->createReport();
        (new RemovedPaymentGatewayAnalyzer())->analyze($report);

        $summary = $this->issuesWithMessage($report, '3 passerelle(s) de paiement supprimee(s) detectee(s)');

        self::assertCount(1, $summary);
        self::assertSame(1440, $summary[0]->getEstimatedMinutes());
        self::assertCount(4, $report->getIssues());
    }

    /**
     * Ecrit composer.json a la racine du projet temporaire.
     *
     * @param array<string, mixed> $data Contenu du composer.json
     */
    private function writeComposerJson(array $data): string
    {
        return $this->writeFile('composer.json', (string) json_encode($data));
    }

    /**
     * Cree un fichier (et ses repertoires parents) dans le projet temporaire.
     * Retourne le chemin absolu du fichier cree.
     */
    private function writeFile(string $relativePath, string $content): string
    {
        $path = $this->tempDir . '/' . $relativePath;
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Retourne les issues dont le message contient le fragment donne.
     *
     * @return list<MigrationIssue>
     */
    private function issuesWithMessage(MigrationReport $report, string $fragment): array
    {
        return array_values(array_filter(
            $report->getIssues(),
            static fn (MigrationIssue $issue): bool => str_contains($issue->getMessage(), $fragment),
        ));
    }
}
