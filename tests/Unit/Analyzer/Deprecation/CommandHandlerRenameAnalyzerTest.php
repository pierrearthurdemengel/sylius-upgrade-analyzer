<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\CommandHandlerRenameAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests de la detection des classes Message* de Sylius renommees en Command*.
 */
final class CommandHandlerRenameAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/command-rename-' . uniqid('', true);
        mkdir($this->tempDir . '/src/Message', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tempDir);
    }

    private function analyze(): MigrationReport
    {
        $report = new MigrationReport(new \DateTimeImmutable(), '1.14.0', '2.2', $this->tempDir);
        (new CommandHandlerRenameAnalyzer())->analyze($report);

        return $report;
    }

    #[Test]
    public function testFlagsReferencesToRenamedSyliusNamespaces(): void
    {
        file_put_contents($this->tempDir . '/src/OrderController.php', <<<'PHP'
<?php

namespace App\Controller;

use Sylius\Bundle\CoreBundle\Message\ResendOrderConfirmationEmail;
use Sylius\Bundle\CoreBundle\MessageDispatcher\ResendShipmentConfirmationEmailDispatcherInterface;

final class OrderController
{
    public function __construct(private ResendShipmentConfirmationEmailDispatcherInterface $dispatcher)
    {
        new ResendOrderConfirmationEmail('000042');
    }
}
PHP);

        $issues = $this->analyze()->getIssues();

        self::assertCount(2, $issues);
        self::assertSame(Severity::BREAKING, $issues[0]->getSeverity());
        self::assertSame(
            'Classe Sylius\Bundle\CoreBundle\Message\ResendOrderConfirmationEmail deplacee vers Sylius\Bundle\CoreBundle\Command\ResendOrderConfirmationEmail',
            $issues[0]->getMessage(),
        );
        self::assertSame(5, $issues[0]->getLine());
        self::assertStringContainsString('CoreBundle\CommandDispatcher\ResendShipmentConfirmationEmailDispatcherInterface', $issues[1]->getMessage());
    }

    #[Test]
    public function testIgnoresProjectOwnMessageNamespace(): void
    {
        /* Le dossier src/Message du projet n'est pas concerne par la montee de version */
        file_put_contents($this->tempDir . '/src/Message/SendNewsletter.php', <<<'PHP'
<?php

namespace App\Message;

final class SendNewsletter
{
}
PHP);
        file_put_contents($this->tempDir . '/src/SendNewsletterMessageHandler.php', <<<'PHP'
<?php

namespace App;

use App\Message\SendNewsletter;

final class SendNewsletterMessageHandler
{
    public function __invoke(SendNewsletter $message): void
    {
    }
}
PHP);

        self::assertSame([], $this->analyze()->getIssues());
    }

    #[Test]
    public function testRenamedKeepsShortClassName(): void
    {
        self::assertSame(
            'Sylius\Bundle\AdminBundle\CommandHandler\Notification\SendNotificationHandler',
            CommandHandlerRenameAnalyzer::renamed('Sylius\Bundle\AdminBundle\MessageHandler\Notification\SendNotificationHandler'),
        );
        self::assertSame('App\Message\Foo', CommandHandlerRenameAnalyzer::renamed('App\Message\Foo'));
    }

    #[Test]
    public function testSupportsProjectsWithSourceDirectory(): void
    {
        $report = new MigrationReport(new \DateTimeImmutable(), '1.14.0', '2.2', $this->tempDir);

        self::assertTrue((new CommandHandlerRenameAnalyzer())->supports($report));
        self::assertSame('Command Handler Rename', (new CommandHandlerRenameAnalyzer())->getName());
    }
}
