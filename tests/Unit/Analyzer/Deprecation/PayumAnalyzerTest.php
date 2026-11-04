<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\PayumAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de Payum.
 * Vérifie la détection des dépendances, configurations et usages de Payum.
 */
final class PayumAnalyzerTest extends TestCase
{
    /** Chemin vers le répertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /** Répertoire temporaire servant de projet Sylius pour les scénarios isolés */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('payum_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Crée un rapport de migration pointant vers le projet de fixture spécifié.
     */
    private function createReportForFixture(string $projectName): MigrationReport
    {
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le répertoire de fixture "%s" est introuvable.', $projectName));

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: $path,
        );
    }

    /**
     * Vérifie que supports retourne true pour le projet complexe qui contient Payum.
     * Le composer.json du projet complexe déclare payum/core et payum/payum-bundle.
     */
    #[Test]
    public function testSupportsReturnsTrueForProjectWithPayum(): void
    {
        $analyzer = new PayumAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Vérifie que supports retourne false pour le projet trivial sans Payum.
     * Le composer.json du projet trivial ne déclare aucun paquet Payum.
     */
    #[Test]
    public function testSupportsReturnsFalseForProjectWithoutPayum(): void
    {
        $analyzer = new PayumAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Vérifie que les dépendances Payum ne sont pas signalées : sylius/sylius 2.0 requiert
     * lui-même payum/payum-bundle, les retirer casserait le projet.
     */
    #[Test]
    public function testDoesNotFlagPayumDependency(): void
    {
        $analyzer = new PayumAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problèmes mentionnant les dépendances Payum */
        $composerIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Dependance')
                && str_contains($issue->getMessage(), 'detectee dans composer.json'),
        );

        self::assertCount(0, $composerIssues);
    }

    /**
     * Vérifie la détection des gateways Payum dans la configuration YAML.
     * Le projet complexe définit 2 gateways : offline et stripe_checkout.
     */
    #[Test]
    public function testDetectsPayumGatewayConfig(): void
    {
        $analyzer = new PayumAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problèmes mentionnant les gateways */
        $gatewayIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Gateway Payum'),
        );

        /* Le fichier payum.yaml définit 2 gateways : offline et stripe_checkout */
        self::assertCount(2, $gatewayIssues);
    }

    /**
     * Vérifie que tous les problèmes créés sont des suggestions : Payum reste supporté par
     * Sylius 2.0 et la migration vers les Payment Requests est optionnelle.
     */
    #[Test]
    public function testCreatesOnlySuggestionsBecausePayumRemainsSupported(): void
    {
        $analyzer = new PayumAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        self::assertNotEmpty($report->getIssues());
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::SUGGESTION, $issue->getSeverity());
        }
        /* Les suggestions n'entrent pas dans le total d'heures du rapport */
        self::assertSame(0.0, $report->getTotalEstimatedHours());
    }

    /**
     * Vérifie que getName retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new PayumAnalyzer();

        self::assertSame('Payum', $analyzer->getName());
    }

    /**
     * Vérifie que le problème global contient l'estimation de migration.
     * Le projet complexe a 2 gateways standard et 0 gateways personnalisées.
     */
    #[Test]
    public function testGlobalIssueContainsMigrationEstimate(): void
    {
        $analyzer = new PayumAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche du problème global résumant la migration */
        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Migration optionnelle vers les Payment Requests'),
        ));

        self::assertNotEmpty($globalIssues, 'Le problème global devrait être présent.');

        /* 2 gateways standard × 120 minutes = 240 minutes */
        self::assertSame(240, $globalIssues[0]->getEstimatedMinutes());
    }

    /**
     * Vérifie que supports retourne false quand le projet n'a pas de composer.json.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutComposerJson(): void
    {
        $analyzer = new PayumAnalyzer();

        self::assertFalse($analyzer->supports($this->createTempReport()));
    }

    /**
     * Vérifie que supports retourne false pour un composer.json invalide.
     */
    #[Test]
    public function testSupportsReturnsFalseForInvalidComposerJson(): void
    {
        $this->writeFile('composer.json', '{"require": {"payum/core": "^1.7"');

        $analyzer = new PayumAnalyzer();

        self::assertFalse($analyzer->supports($this->createTempReport()));
    }

    /**
     * Vérifie que supports retourne false quand composer.json n'a pas de section require,
     * même si Payum apparaît dans require-dev.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutRequireSection(): void
    {
        $this->writeComposerJson(['require-dev' => ['payum/core' => '^1.7']]);

        $analyzer = new PayumAnalyzer();

        self::assertFalse($analyzer->supports($this->createTempReport()));
    }

    /**
     * Vérifie que supports retourne true quand seul payum/payum-bundle est requis.
     */
    #[Test]
    public function testSupportsReturnsTrueWithPayumBundleOnly(): void
    {
        $this->writeComposerJson(['require' => [
            'sylius/sylius' => '~1.12.0',
            'payum/payum-bundle' => '^2.5',
        ]]);

        $analyzer = new PayumAnalyzer();

        self::assertTrue($analyzer->supports($this->createTempReport()));
    }

    /**
     * Vérifie que l'analyse d'un projet vide ne produit aucun problème ni résumé global.
     */
    #[Test]
    public function testProducesNoIssueForEmptyProject(): void
    {
        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Vérifie qu'un projet qui ne fait que dépendre de Payum ne produit aucun problème.
     */
    #[Test]
    public function testDependencyAloneProducesNoIssue(): void
    {
        $this->writeComposerJson(['require' => [
            'sylius/sylius' => '~1.12.0',
            'payum/core' => '^1.7.3',
        ]]);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Vérifie qu'un composer.json sans section require est ignoré par l'analyse.
     */
    #[Test]
    public function testIgnoresComposerJsonWithoutRequireSection(): void
    {
        $this->writeComposerJson(['name' => 'acme/sylius-shop']);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Vérifie la détection d'une gateway Payum dans un fichier .yml de config/packages.
     */
    #[Test]
    public function testDetectsGatewayInYmlFile(): void
    {
        $filePath = $this->writeFile('config/packages/payum.yml', <<<'YAML'
payum:
    gateways:
        paypal_express_checkout:
            factory: paypal_express_checkout
            username: '%env(PAYPAL_USERNAME)%'
YAML);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame('Gateway Payum "paypal_express_checkout" convertible en Payment Request', $issues[0]->getMessage());
        self::assertStringContainsString('payum.yml', $issues[0]->getDetail());
        self::assertSame(realpath($filePath), $issues[0]->getFile());
        self::assertSame(
            'Migration optionnelle vers les Payment Requests : 1 gateway(s) standard, 0 gateway(s) personnalisee(s) et 0 autre(s) fichier(s) PHP dependant de Payum',
            $issues[1]->getMessage(),
        );
        self::assertSame(120, $issues[1]->getEstimatedMinutes());
    }

    /**
     * Vérifie que les fichiers YAML invalides, sans clé payum, avec une clé payum
     * scalaire ou sans gateways sont ignorés.
     */
    #[Test]
    public function testSkipsYamlFilesWithoutUsablePayumGateways(): void
    {
        $this->writeFile('config/packages/broken.yaml', "payum:\n  gateways: [unclosed\n");
        $this->writeFile('config/packages/framework.yaml', "framework:\n    secret: '%env(APP_SECRET)%'\n");
        $this->writeFile('config/packages/payum_null.yaml', 'payum: ~');
        $this->writeFile('config/packages/payum_security.yaml', <<<'YAML'
payum:
    security:
        token_storage:
            Sylius\Bundle\PayumBundle\Model\PaymentSecurityToken: { doctrine: orm }
YAML);
        $this->writeFile('config/packages/scalar.yaml', 'payum');

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Vérifie que seules les configurations de config/packages sont analysées.
     */
    #[Test]
    public function testIgnoresGatewaysOutsideConfigPackages(): void
    {
        $this->writeFile('config/services.yaml', <<<'YAML'
payum:
    gateways:
        offline:
            factory: offline
YAML);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Vérifie la détection d'une gateway factory personnalisée et son estimation de 8 heures.
     */
    #[Test]
    public function testDetectsCustomGatewayFactory(): void
    {
        $filePath = $this->writeFile('src/Payum/Stripe/StripeGatewayFactory.php', <<<'PHP'
<?php

namespace App\Payum\Stripe;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

final class StripeGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
    }
}
PHP);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        $factoryIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_starts_with($issue->getMessage(), 'Gateway factory personnalisee'),
        ));
        self::assertCount(1, $factoryIssues);
        self::assertSame(
            'Gateway factory personnalisee StripeGatewayFactory detectee (etend GatewayFactory)',
            $factoryIssues[0]->getMessage(),
        );
        self::assertSame(8, $factoryIssues[0]->getLine());
        self::assertSame(realpath($filePath), $factoryIssues[0]->getFile());

        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_starts_with($issue->getMessage(), 'Migration optionnelle vers les Payment Requests'),
        ));
        self::assertCount(1, $globalIssues);
        self::assertSame(
            'Migration optionnelle vers les Payment Requests : 0 gateway(s) standard, 1 gateway(s) personnalisee(s) et 0 autre(s) fichier(s) PHP dependant de Payum',
            $globalIssues[0]->getMessage(),
        );
        self::assertSame(480, $globalIssues[0]->getEstimatedMinutes());
    }

    /**
     * Vérifie que chaque import d'une classe Payum produit un problème avec sa ligne,
     * suivi d'un résumé estimant le fichier comme dépendant de Payum.
     */
    #[Test]
    public function testDetectsPayumImports(): void
    {
        $this->writeFile('src/Payum/Action/CaptureAction.php', <<<'PHP'
<?php

namespace App\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Request\Capture;
use Sylius\Component\Core\Model\PaymentInterface;

final class CaptureAction implements ActionInterface
{
}
PHP);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(3, $issues);
        self::assertSame('Utilisation de la classe Payum Payum\Core\Action\ActionInterface detectee', $issues[0]->getMessage());
        self::assertSame(5, $issues[0]->getLine());
        self::assertSame('Utilisation de la classe Payum Payum\Core\Request\Capture detectee', $issues[1]->getMessage());
        self::assertSame(6, $issues[1]->getLine());
        self::assertStringContainsString('CaptureAction.php', $issues[1]->getDetail());
        self::assertSame(120, $issues[2]->getEstimatedMinutes());
    }

    /**
     * Vérifie qu'une classe anonyme étendant une classe Payum est signalée avec un libellé générique.
     */
    #[Test]
    public function testLabelsAnonymousGatewayFactory(): void
    {
        $this->writeFile('src/Payum/GatewayFactoryBuilder.php', <<<'PHP'
<?php

namespace App\Payum;

final class GatewayFactoryBuilder
{
    public function build(): object
    {
        return new class () extends \Payum\Core\GatewayFactory {
        };
    }
}
PHP);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());
        self::assertContains(
            'Gateway factory personnalisee Classe anonyme detectee (etend Payum\Core\GatewayFactory)',
            $messages,
        );
    }

    /**
     * Vérifie que les classes sans lien avec Payum et les fichiers PHP invalides sont ignorés.
     */
    #[Test]
    public function testIgnoresUnrelatedAndUnparsablePhpFiles(): void
    {
        $this->writeFile('src/Broken.php', "<?php\n\nuse Payum\\Core\\GatewayFactory;\nclass Broken extends GatewayFactory {\n");
        $this->writeFile('src/Entity/Payment/Payment.php', <<<'PHP'
<?php

namespace App\Entity\Payment;

use Sylius\Component\Core\Model\Payment as BasePayment;

class Payment extends BasePayment
{
}

final class PaymentFactory
{
}
PHP);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Vérifie que le résumé global cumule gateways standard et personnalisées.
     * 1 standard × 120 + 1 personnalisée × 480 = 600 minutes.
     */
    #[Test]
    public function testGlobalEstimateCombinesStandardAndCustomGateways(): void
    {
        $this->writeFile('config/packages/payum.yaml', <<<'YAML'
payum:
    gateways:
        offline:
            factory: offline
YAML);
        $this->writeFile('src/Payum/Mollie/MollieGatewayFactory.php', <<<'PHP'
<?php

namespace App\Payum\Mollie;

final class MollieGatewayFactory extends \Payum\Core\GatewayFactory
{
}
PHP);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_starts_with($issue->getMessage(), 'Migration optionnelle vers les Payment Requests'),
        ));
        self::assertCount(1, $globalIssues);
        self::assertSame(
            'Migration optionnelle vers les Payment Requests : 1 gateway(s) standard, 1 gateway(s) personnalisee(s) et 0 autre(s) fichier(s) PHP dependant de Payum',
            $globalIssues[0]->getMessage(),
        );
        self::assertSame(600, $globalIssues[0]->getEstimatedMinutes());
        self::assertSame(Severity::SUGGESTION, $globalIssues[0]->getSeverity());
    }

    /**
     * Vérifie qu'un projet n'ayant que des actions Payum (sans gateway factory ni configuration)
     * reçoit un résumé global estimé : 2 fichiers × 120 = 240 minutes.
     */
    #[Test]
    public function testEstimatesProjectWithOnlyPayumActions(): void
    {
        $this->writeFile('src/Payum/Action/CaptureAction.php', <<<'PHP'
<?php

namespace App\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Request\Capture;

final class CaptureAction implements ActionInterface
{
}
PHP);
        $this->writeFile('src/Payum/Action/StatusAction.php', <<<'PHP'
<?php

namespace App\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Request\GetStatusInterface;

final class StatusAction implements ActionInterface
{
}
PHP);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_starts_with($issue->getMessage(), 'Migration optionnelle vers les Payment Requests'),
        ));
        self::assertCount(1, $globalIssues);
        self::assertSame(
            'Migration optionnelle vers les Payment Requests : 0 gateway(s) standard, 0 gateway(s) personnalisee(s) '
            . 'et 2 autre(s) fichier(s) PHP dependant de Payum',
            $globalIssues[0]->getMessage(),
        );
        self::assertSame(240, $globalIssues[0]->getEstimatedMinutes());
    }

    /**
     * Vérifie que les imports groupés (use Payum\Core\{...}) sont détectés avec leur nom complet.
     */
    #[Test]
    public function testDetectsGroupedPayumImports(): void
    {
        $this->writeFile('src/Payum/Action/NotifyAction.php', <<<'PHP'
<?php

namespace App\Payum\Action;

use Payum\Core\{
    Action\ActionInterface,
    Request\Notify
};

final class NotifyAction implements ActionInterface
{
}
PHP);

        $analyzer = new PayumAnalyzer();
        $report = $this->createTempReport();

        $analyzer->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());
        self::assertContains('Utilisation de la classe Payum Payum\Core\Action\ActionInterface detectee', $messages);
        self::assertContains('Utilisation de la classe Payum Payum\Core\Request\Notify detectee', $messages);
    }

    /**
     * Crée un rapport de migration pointant vers le répertoire temporaire.
     */
    private function createTempReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12',
            targetVersion: '2.0',
            projectPath: $this->tempDir,
        );
    }

    /**
     * Écrit un composer.json dans le projet temporaire et retourne son chemin.
     *
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): string
    {
        return $this->writeFile('composer.json', (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Écrit un fichier dans le projet temporaire en créant les répertoires parents.
     * Retourne le chemin complet du fichier créé.
     */
    private function writeFile(string $relativePath, string $content): string
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $directory = \dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }

    /**
     * Supprime récursivement un répertoire temporaire.
     */
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
}
