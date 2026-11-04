<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\MessageBusRenameAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de renommage des bus de messages.
 * Verifie la detection des references aux anciens noms de bus Sylius.
 */
final class MessageBusRenameAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('msgbus_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Supprime recursivement un repertoire temporaire.
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

    /**
     * Cree un rapport de migration pointant vers le repertoire temporaire.
     */
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
     * Verifie que supports retourne false pour un projet vide sans src/ ni config/.
     */
    #[Test]
    public function testSupportsReturnsFalseForEmptyProject(): void
    {
        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte les references a sylius_default.bus dans un YAML.
     */
    #[Test]
    public function testDetectsBusReferences(): void
    {
        /* Creation d'un fichier de configuration contenant l'ancien nom de bus */
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/messenger.yaml', <<<'YAML'
framework:
    messenger:
        buses:
            sylius_default.bus:
                middleware:
                    - doctrine_transaction
YAML);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));

        $analyzer->analyze($report);

        /* Verification qu'au moins un probleme a ete detecte */
        self::assertNotEmpty($report->getIssues());

        /* Verification qu'un probleme mentionne sylius_default.bus */
        $busIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sylius_default.bus'),
        );
        self::assertNotEmpty($busIssues);
    }

    /**
     * Verifie que les problemes crees sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/messenger.yaml', <<<'YAML'
framework:
    messenger:
        buses:
            sylius_default.bus: ~
YAML);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
        }
    }

    /**
     * Verifie l'estimation de 60 minutes par reference.
     * Le resume global doit avoir estimatedMinutes = nombre_de_references * 60.
     */
    #[Test]
    public function testEstimatesOneHourPerReference(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/messenger.yaml', <<<'YAML'
framework:
    messenger:
        buses:
            sylius_default.bus: ~
            sylius_event.bus: ~
YAML);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        /* Recherche du probleme global de resume */
        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'reference(s) aux anciens noms de bus'),
        ));

        self::assertNotEmpty($globalIssues, 'Le probleme global de resume devrait etre present.');

        /* Chaque reference = 60 minutes, 2 references = 120 minutes */
        self::assertSame(120, $globalIssues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que getName retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new MessageBusRenameAnalyzer();

        self::assertSame('Message Bus Rename', $analyzer->getName());
    }

    /**
     * Verifie que supports retourne true quand seule une classe PHP de src/ reference l'ancien bus.
     */
    #[Test]
    public function testSupportsReturnsTrueForReferenceInPhpSource(): void
    {
        $this->writeFile('src/MessageHandler/SendOrderConfirmationHandler.php', <<<'PHP'
<?php

namespace App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'sylius_event.bus')]
final class SendOrderConfirmationHandler
{
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();

        self::assertTrue($analyzer->supports($this->createReport()));
    }

    /**
     * Verifie que supports retourne false quand src/ et config/ ne contiennent que les nouveaux noms.
     */
    #[Test]
    public function testSupportsReturnsFalseWhenOnlyNewBusNamesAreUsed(): void
    {
        $this->writeFile('config/packages/messenger.yaml', <<<'YAML'
framework:
    messenger:
        default_bus: sylius.command_bus
        buses:
            sylius.command_bus: ~
            sylius.event_bus: ~
YAML);
        $this->writeFile('src/Kernel.php', "<?php\n\nnamespace App;\n\nfinal class Kernel\n{\n}\n");

        $analyzer = new MessageBusRenameAnalyzer();

        self::assertFalse($analyzer->supports($this->createReport()));
    }

    /**
     * Verifie qu'un fichier XML referencant sylius_event.bus est signale avec le bon remplacement.
     */
    #[Test]
    public function testDetectsEventBusReferenceInXmlConfig(): void
    {
        $filePath = $this->writeFile('config/services/handlers.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="http://symfony.com/schema/dic/services">
    <services>
        <service id="app.handler.order_placed" class="App\MessageHandler\OrderPlacedHandler">
            <tag name="messenger.message_handler" bus="sylius_event.bus"/>
        </service>
    </services>
</container>
XML);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertStringStartsWith('Reference a "sylius_event.bus" detectee dans services', $issues[0]->getMessage());
        self::assertStringContainsString('handlers.xml', $issues[0]->getMessage());
        self::assertSame('Remplacer "sylius_event.bus" par "sylius.event_bus".', $issues[0]->getSuggestion());
        self::assertSame(realpath($filePath), $issues[0]->getFile());
        self::assertSame(Category::DEPRECATION, $issues[0]->getCategory());
    }

    /**
     * Verifie qu'un fichier de configuration referencant les deux bus produit deux problemes.
     */
    #[Test]
    public function testReportsBothBusesFromSingleConfigFile(): void
    {
        $this->writeFile('config/packages/messenger.yml', <<<'YAML'
framework:
    messenger:
        default_bus: sylius_default.bus
        buses:
            sylius_default.bus: ~
            sylius_event.bus:
                default_middleware: allow_no_handlers
YAML);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = array_map(static fn ($issue): string => $issue->getSuggestion(), $report->getIssues());
        self::assertContains('Remplacer "sylius_default.bus" par "sylius.command_bus".', $suggestions);
        self::assertContains('Remplacer "sylius_event.bus" par "sylius.event_bus".', $suggestions);
        self::assertCount(3, $report->getIssues());
    }

    /**
     * Verifie la detection d'une chaine d'ancien bus dans un fichier PHP de src/.
     */
    #[Test]
    public function testDetectsBusStringInPhpSource(): void
    {
        $filePath = $this->writeFile('src/MessageHandler/ResendOrderConfirmationHandler.php', <<<'PHP'
<?php

namespace App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'sylius_default.bus')]
final class ResendOrderConfirmationHandler
{
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertStringStartsWith('Reference a "sylius_default.bus" dans MessageHandler', $issues[0]->getMessage());
        self::assertStringContainsString('Le fichier PHP', $issues[0]->getDetail());
        self::assertSame('Remplacer "sylius_default.bus" par "sylius.command_bus".', $issues[0]->getSuggestion());
        self::assertSame(realpath($filePath), $issues[0]->getFile());
        self::assertNull($issues[0]->getLine());
    }

    /**
     * Verifie la detection d'une variable locale $syliusDefaultBus avec sa ligne.
     */
    #[Test]
    public function testDetectsDefaultBusVariableWithLine(): void
    {
        $this->writeFile('src/Console/ResendConfirmationCommand.php', <<<'PHP'
<?php

namespace App\Console;

final class ResendConfirmationCommand
{
    public function run(object $container): void
    {
        $syliusDefaultBus = $container->get('messenger.default_bus');
    }
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame('Variable "$syliusDefaultBus" detectee ligne 9', $issues[0]->getMessage());
        self::assertSame(9, $issues[0]->getLine());
        self::assertSame('Renommer $syliusDefaultBus en $syliusCommandBus.', $issues[0]->getSuggestion());
        self::assertStringContainsString('ResendConfirmationCommand.php', $issues[0]->getDetail());
    }

    /**
     * Verifie qu'un parametre de constructeur $syliusEventBus n'est pas signale :
     * le bus sylius.event_bus de Sylius 2.0 produit le meme alias d'autowiring
     * que l'ancien sylius_event.bus, le nom d'argument reste donc valide.
     */
    #[Test]
    public function testIgnoresEventBusArgumentWhoseAutowiringNameIsUnchanged(): void
    {
        $this->writeFile('src/EventListener/OrderCompleteListener.php', <<<'PHP'
<?php

namespace App\EventListener;

use Symfony\Component\Messenger\MessageBusInterface;

final class OrderCompleteListener
{
    public function __construct(
        private MessageBusInterface $syliusEventBus,
    ) {
    }
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un parametre de constructeur $syliusDefaultBus produit une seule issue
     * (et non une pour le parametre et une pour sa variable), soit 60 minutes au total.
     */
    #[Test]
    public function testReportsDefaultBusConstructorParameterOnlyOnce(): void
    {
        $this->writeFile('src/Controller/ReorderAction.php', <<<'PHP'
<?php

namespace App\Controller;

use Symfony\Component\Messenger\MessageBusInterface;

final class ReorderAction
{
    public function __construct(
        private MessageBusInterface $syliusDefaultBus,
    ) {
    }
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame('Variable "$syliusDefaultBus" detectee ligne 10', $issues[0]->getMessage());
        self::assertSame('Renommer $syliusDefaultBus en $syliusCommandBus.', $issues[0]->getSuggestion());
        self::assertSame(60, $issues[1]->getEstimatedMinutes());
    }

    /**
     * Verifie que supports retourne true quand seul un argument $syliusDefaultBus
     * reference l'ancien bus par autowiring, sans chaine de bus explicite.
     */
    #[Test]
    public function testSupportsReturnsTrueForDefaultBusArgumentOnly(): void
    {
        $this->writeFile('src/Controller/ReorderAction.php', <<<'PHP'
<?php

namespace App\Controller;

use Symfony\Component\Messenger\MessageBusInterface;

final class ReorderAction
{
    public function __construct(private MessageBusInterface $syliusDefaultBus)
    {
    }
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();

        self::assertTrue($analyzer->supports($this->createReport()));
    }

    /**
     * Verifie que les autres noms de variables ne sont pas signales.
     */
    #[Test]
    public function testIgnoresUnrelatedVariables(): void
    {
        $this->writeFile('src/Service/OrderNotifier.php', <<<'PHP'
<?php

namespace App\Service;

use Symfony\Component\Messenger\MessageBusInterface;

final class OrderNotifier
{
    public function notify(MessageBusInterface $commandBus, object $order): void
    {
        $syliusCommandBus = $commandBus;
        $$order = null;
    }
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un fichier PHP invalide reste signale pour ses chaines de bus,
     * meme si l'analyse AST des variables est impossible.
     */
    #[Test]
    public function testKeepsStringReferenceWhenPhpFileIsUnparsable(): void
    {
        $this->writeFile('src/Broken.php', "<?php\n\$bus = 'sylius_event.bus';\n\$syliusEventBus = \n");

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame('Reference a "sylius_event.bus" dans Broken.php', $issues[0]->getMessage());
        self::assertSame(60, $issues[1]->getEstimatedMinutes());
    }

    /**
     * Verifie que le resume global cumule les references de configuration, les chaines PHP
     * et les variables. 1 + 1 + 1 references = 180 minutes.
     */
    #[Test]
    public function testGlobalEstimateCumulatesConfigAndPhpReferences(): void
    {
        $this->writeFile('config/packages/messenger.yaml', <<<'YAML'
framework:
    messenger:
        buses:
            sylius_default.bus: ~
YAML);
        $this->writeFile('src/Controller/ReorderController.php', <<<'PHP'
<?php

namespace App\Controller;

final class ReorderController
{
    public function __invoke(object $locator): void
    {
        $syliusDefaultBus = $locator->get('sylius_default.bus');
    }
}
PHP);

        $analyzer = new MessageBusRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(4, $issues);

        $globalIssue = $issues[3];
        self::assertSame('3 reference(s) aux anciens noms de bus de messages detectee(s)', $globalIssue->getMessage());
        self::assertSame(180, $globalIssue->getEstimatedMinutes());
        self::assertSame(Severity::WARNING, $globalIssue->getSeverity());
    }

    /**
     * Ecrit un fichier dans le projet temporaire en creant les repertoires parents.
     * Retourne le chemin complet du fichier cree.
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
}
