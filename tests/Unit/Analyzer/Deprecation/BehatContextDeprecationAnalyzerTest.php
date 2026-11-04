<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\BehatContextDeprecationAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des contextes Behat deprecies.
 * Verifie la detection des references aux contextes Behat supprimes dans Sylius 2.0.
 */
final class BehatContextDeprecationAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('behat_', true);
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
     * Verifie que supports retourne false sans features/ ni tests/ ni behat.yml.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutBehatFiles(): void
    {
        $analyzer = new BehatContextDeprecationAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand behat.yml existe.
     */
    #[Test]
    public function testSupportsReturnsTrueWithBehatYml(): void
    {
        file_put_contents($this->tempDir . '/behat.yml', "default:\n    suites: ~\n");

        $analyzer = new BehatContextDeprecationAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection du service sylius.behat.api_security, supprime en Sylius 2.0,
     * dans la declaration des contextes du projet.
     */
    #[Test]
    public function testDetectsRemovedApiSecurityService(): void
    {
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/services_test.yaml', <<<'YAML'
services:
    App\Tests\Behat\Context\Api\Admin\ManagingGiftCardsContext:
        arguments:
            - '@sylius.behat.api_security'
YAML);

        $analyzer = new BehatContextDeprecationAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());
        self::assertContains('Service Behat supprime "sylius.behat.api_security" dans services_test.yaml', $messages);
    }

    /**
     * Verifie que les contextes toujours presents en Sylius 2.0 (calendar, doctrine_orm,
     * managing_orders, checkout) ne sont pas signales.
     */
    #[Test]
    public function testIgnoresContextsStillShippedWithSylius2(): void
    {
        file_put_contents($this->tempDir . '/behat.yml', <<<'YAML'
default:
    suites:
        ui:
            contexts:
                - sylius.behat.context.hook.calendar
                - sylius.behat.context.hook.doctrine_orm
                - sylius.behat.context.setup.calendar
                - sylius.behat.context.ui.admin.managing_orders
                - sylius.behat.context.ui.shop.checkout
YAML);

        $analyzer = new BehatContextDeprecationAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        file_put_contents($this->tempDir . '/behat.yml', <<<'YAML'
default:
    suites:
        api:
            contexts:
                - App\Tests\Behat\Context\Api\ShopContext:
                    security: '@sylius.behat.api_security'
YAML);

        $analyzer = new BehatContextDeprecationAnalyzer();
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
        $analyzer = new BehatContextDeprecationAnalyzer();

        self::assertSame('Behat Context Deprecation', $analyzer->getName());
    }
}
