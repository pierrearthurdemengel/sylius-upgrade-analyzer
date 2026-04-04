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
     * Verifie la detection d'un contexte Behat deprecie dans behat.yml.
     */
    #[Test]
    public function testDetectsDeprecatedBehatContext(): void
    {
        file_put_contents($this->tempDir . '/behat.yml', <<<'YAML'
default:
    suites:
        ui:
            contexts:
                - sylius.behat.context.hook.calendar
YAML);

        $analyzer = new BehatContextDeprecationAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $contextIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sylius.behat.context.hook.calendar'),
        );
        self::assertNotEmpty($contextIssues);
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
        ui:
            contexts:
                - sylius.behat.context.hook.calendar
YAML);

        $analyzer = new BehatContextDeprecationAnalyzer();
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
        $analyzer = new BehatContextDeprecationAnalyzer();

        self::assertSame('Behat Context Deprecation', $analyzer->getName());
    }
}
