<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\OrderProcessorPriorityAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des priorites de processeurs de commande.
 * Verifie la detection des implementations de OrderProcessorInterface
 * et des priorites dans la plage 40-60.
 */
final class OrderProcessorPriorityAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('orderproc_', true);
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
        $analyzer = new OrderProcessorPriorityAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand un fichier PHP reference OrderProcessorInterface.
     */
    #[Test]
    public function testSupportsReturnsTrueWithOrderProcessor(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomOrderProcessor.php', <<<'PHP'
<?php

namespace App;

use Sylius\Component\Order\Processor\OrderProcessorInterface;

class CustomOrderProcessor implements OrderProcessorInterface
{
    public function process($order): void {}
}
PHP);

        $analyzer = new OrderProcessorPriorityAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection d'une classe implementant OrderProcessorInterface.
     */
    #[Test]
    public function testDetectsOrderProcessorImplementation(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomOrderProcessor.php', <<<'PHP'
<?php

namespace App;

use Sylius\Component\Order\Processor\OrderProcessorInterface;

class CustomOrderProcessor implements OrderProcessorInterface
{
    public function process($order): void {}
}
PHP);

        $analyzer = new OrderProcessorPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $processorIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'CustomOrderProcessor'),
        );
        self::assertNotEmpty($processorIssues);
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomOrderProcessor.php', <<<'PHP'
<?php

namespace App;

use Sylius\Component\Order\Processor\OrderProcessorInterface;

class CustomOrderProcessor implements OrderProcessorInterface
{
    public function process($order): void {}
}
PHP);

        $analyzer = new OrderProcessorPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

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
        $analyzer = new OrderProcessorPriorityAnalyzer();

        self::assertSame('Order Processor Priority', $analyzer->getName());
    }
}
