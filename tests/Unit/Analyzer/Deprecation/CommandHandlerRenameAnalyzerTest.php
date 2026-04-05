<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\CommandHandlerRenameAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;

/**
 * Tests unitaires pour l'analyseur de renommage Command/Handler.
 * Verifie la detection du repertoire src/Message/ et des classes *MessageHandler.
 */
final class CommandHandlerRenameAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('cmdhandler_', true);
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
     * Verifie que supports retourne false pour un projet sans src/Message/ ni *MessageHandler.
     */
    #[Test]
    public function testSupportsReturnsFalseForEmptyProject(): void
    {
        $analyzer = new CommandHandlerRenameAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand src/Message/ existe.
     */
    #[Test]
    public function testSupportsReturnsTrueWithMessageDirectory(): void
    {
        $messageDir = $this->tempDir . '/src/Message';
        mkdir($messageDir, 0755, true);
        file_put_contents($messageDir . '/SendOrder.php', '<?php class SendOrder {}');

        $analyzer = new CommandHandlerRenameAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection des fichiers dans src/Message/.
     */
    #[Test]
    public function testDetectsMessageDirectoryFiles(): void
    {
        $messageDir = $this->tempDir . '/src/Message';
        mkdir($messageDir, 0755, true);
        file_put_contents($messageDir . '/SendOrder.php', "<?php\nnamespace App\\Message;\nclass SendOrder {}\n");

        $analyzer = new CommandHandlerRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        /* Un probleme devrait mentionner le deplacement depuis src/Message/ */
        $moveIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'src/Message/'),
        );
        self::assertNotEmpty($moveIssues);
    }

    /**
     * Verifie la detection des classes *MessageHandler.
     */
    #[Test]
    public function testDetectsMessageHandlerClasses(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/SendOrderMessageHandler.php', "<?php\nnamespace App;\nclass SendOrderMessageHandler {}\n");

        $analyzer = new CommandHandlerRenameAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        /* Un probleme devrait mentionner le renommage en CommandHandler */
        $renameIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'CommandHandler'),
        );
        self::assertNotEmpty($renameIssues);
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new CommandHandlerRenameAnalyzer();

        self::assertSame('Command Handler Rename', $analyzer->getName());
    }
}
