<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\AdminMenuEventAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des evenements de menu admin.
 * Verifie la detection des listeners/subscribers sur sylius.menu.admin.*.
 */
final class AdminMenuEventAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('adminmenu_', true);
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
     * Verifie que supports retourne false pour un projet sans src/.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutSrcDir(): void
    {
        $analyzer = new AdminMenuEventAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand sylius.menu.admin est reference.
     */
    #[Test]
    public function testSupportsReturnsTrueWithMenuReference(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/AdminMenuListener.php', <<<'PHP'
<?php

namespace App\EventListener;

class AdminMenuListener
{
    public static function getSubscribedEvents(): array
    {
        return ['sylius.menu.admin.main' => 'addMenuItems'];
    }

    public function addMenuItems($event): void {}
}
PHP);

        $analyzer = new AdminMenuEventAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection d'un subscriber sur un menu supprime en Sylius 2.0.
     */
    #[Test]
    public function testDetectsMenuEventSubscriber(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/AdminMenuListener.php', <<<'PHP'
<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AdminMenuListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['sylius.menu.admin.order.show' => 'addMenuItems'];
    }

    public function addMenuItems($event): void {}
}
PHP);

        $analyzer = new AdminMenuEventAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $menuIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sylius.menu.admin.order.show'),
        );
        self::assertNotEmpty($menuIssues);
    }

    /**
     * Verifie que le menu principal, conserve en Sylius 2.0, n'est pas signale.
     */
    #[Test]
    public function testIgnoresMainMenuWhichStillExistsInSylius2(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/AdminMenuListener.php', <<<'PHP'
<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

#[AsEventListener(event: 'sylius.menu.admin.main')]
class AdminMenuListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['sylius.menu.admin.main' => 'addMenuItems', 'sylius.menu.shop.account' => 'addAccountItems'];
    }
}
PHP);

        $report = $this->createReport();
        (new AdminMenuEventAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection d'un attribut #[AsEventListener] sur un menu supprime.
     */
    #[Test]
    public function testDetectsEventListenerAttributeOnRemovedMenu(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/ProductFormMenuListener.php', <<<'PHP'
<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'sylius.menu.admin.product.form')]
final class ProductFormMenuListener
{
}
PHP);

        $report = $this->createReport();
        (new AdminMenuEventAnalyzer())->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());
        self::assertContains('Attribut #[AsEventListener] sur l\'evenement "sylius.menu.admin.product.form" detecte', $messages);
        self::assertContains('1 gestionnaire(s) d\'evenements de menu admin supprime(s) detecte(s)', $messages);
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/AdminMenuListener.php', <<<'PHP'
<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AdminMenuListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['sylius.menu.admin.customer.show' => 'addMenuItems'];
    }

    public function addMenuItems($event): void {}
}
PHP);

        $analyzer = new AdminMenuEventAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertNotEmpty($report->getIssues());
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new AdminMenuEventAnalyzer();

        self::assertSame('Admin Menu Event', $analyzer->getName());
    }
}
