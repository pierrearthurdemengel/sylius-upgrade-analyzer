<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\OrderProcessorAutoconfigurationAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests de la detection des processeurs de commande et contextes de panier que Sylius 2.0
 * n'enregistre plus par interface.
 */
final class OrderProcessorAutoconfigurationAnalyzerTest extends TestCase
{
    private const PROCESSOR = <<<'PHP'
<?php

namespace App\OrderProcessing;

use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

final class GiftWrappingProcessor implements OrderProcessorInterface
{
    public function process(OrderInterface $order): void
    {
    }
}
PHP;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/order-autoconfig-' . uniqid('', true);
        mkdir($this->tempDir . '/src/OrderProcessing', 0755, true);
        mkdir($this->tempDir . '/config', 0755, true);
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
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.14.0',
            targetVersion: '2.2',
            projectPath: $this->tempDir,
        );
        (new OrderProcessorAutoconfigurationAnalyzer())->analyze($report);

        return $report;
    }

    private function writeProcessor(string $content = self::PROCESSOR): void
    {
        file_put_contents($this->tempDir . '/src/OrderProcessing/GiftWrappingProcessor.php', $content);
    }

    #[Test]
    public function testFlagsProcessorRelyingOnInterfaceAutoconfiguration(): void
    {
        $this->writeProcessor();

        $issues = $this->analyze()->getIssues();

        self::assertCount(1, $issues);
        self::assertSame(Severity::BREAKING, $issues[0]->getSeverity());
        self::assertSame(
            'App\OrderProcessing\GiftWrappingProcessor ne sera plus enregistre : OrderProcessorInterface sans #[AsOrderProcessor] ni tag sylius.order_processor',
            $issues[0]->getMessage(),
        );
        self::assertSame(8, $issues[0]->getLine());
        self::assertSame(30, $issues[0]->getEstimatedMinutes());
    }

    #[Test]
    public function testIgnoresProcessorWithAttribute(): void
    {
        $this->writeProcessor(str_replace(
            'final class GiftWrappingProcessor',
            "#[\\Sylius\\Bundle\\OrderBundle\\Attribute\\AsOrderProcessor(priority: 15)]\nfinal class GiftWrappingProcessor",
            self::PROCESSOR,
        ));

        self::assertSame([], $this->analyze()->getIssues());
    }

    #[Test]
    public function testIgnoresProcessorTaggedInYaml(): void
    {
        $this->writeProcessor();
        file_put_contents($this->tempDir . '/config/services.yaml', <<<'YAML'
services:
    App\OrderProcessing\GiftWrappingProcessor:
        tags:
            - { name: sylius.order_processor, priority: 15 }
YAML);

        self::assertSame([], $this->analyze()->getIssues());
    }

    #[Test]
    public function testIgnoresProcessorTaggedWithShortYamlSyntaxAndServiceClass(): void
    {
        $this->writeProcessor();
        file_put_contents($this->tempDir . '/config/services.yaml', <<<'YAML'
services:
    app.order_processor.gift_wrapping:
        class: App\OrderProcessing\GiftWrappingProcessor
        tags:
            - sylius.order_processor: { priority: 15 }
YAML);

        self::assertSame([], $this->analyze()->getIssues());
    }

    #[Test]
    public function testIgnoresProcessorTaggedInXml(): void
    {
        $this->writeProcessor();
        file_put_contents($this->tempDir . '/config/services.xml', <<<'XML'
<container>
    <services>
        <service id="app.gift_wrapping" class="App\OrderProcessing\GiftWrappingProcessor">
            <tag name="sylius.order_processor" priority="15"/>
        </service>
    </services>
</container>
XML);

        self::assertSame([], $this->analyze()->getIssues());
    }

    #[Test]
    public function testIgnoresInterfaceCoveredByInstanceof(): void
    {
        $this->writeProcessor();
        file_put_contents($this->tempDir . '/config/services.yaml', <<<'YAML'
services:
    _instanceof:
        Sylius\Component\Order\Processor\OrderProcessorInterface:
            tags: [sylius.order_processor]
YAML);

        self::assertSame([], $this->analyze()->getIssues());
    }

    #[Test]
    public function testIgnoresAbstractProcessor(): void
    {
        $this->writeProcessor(str_replace('final class', 'abstract class', self::PROCESSOR));

        self::assertSame([], $this->analyze()->getIssues());
    }

    #[Test]
    public function testFlagsCartContextRelyingOnInterfaceAutoconfiguration(): void
    {
        file_put_contents($this->tempDir . '/src/ChannelCartContext.php', <<<'PHP'
<?php

namespace App;

use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Model\OrderInterface;

final class ChannelCartContext implements CartContextInterface
{
    public function getCart(): OrderInterface
    {
        throw new \LogicException();
    }
}
PHP);

        $issues = $this->analyze()->getIssues();

        self::assertCount(1, $issues);
        self::assertStringContainsString('#[AsCartContext] ni tag sylius.context.cart', $issues[0]->getMessage());
    }

    #[Test]
    public function testSupportsProjectsWithAutoconfiguredInterfaces(): void
    {
        $this->writeProcessor();
        $report = new MigrationReport(new \DateTimeImmutable(), '1.14.0', '2.2', $this->tempDir);

        self::assertTrue((new OrderProcessorAutoconfigurationAnalyzer())->supports($report));
    }

    #[Test]
    public function testDoesNotSupportProjectsWithoutSource(): void
    {
        rmdir($this->tempDir . '/src/OrderProcessing');
        rmdir($this->tempDir . '/src');
        $report = new MigrationReport(new \DateTimeImmutable(), '1.14.0', '2.2', $this->tempDir);

        self::assertFalse((new OrderProcessorAutoconfigurationAnalyzer())->supports($report));
    }
}
