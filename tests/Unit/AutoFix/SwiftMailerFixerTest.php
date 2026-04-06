<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\SwiftMailerFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer SwiftMailer → Symfony Mailer.
 */
final class SwiftMailerFixerTest extends TestCase
{
    private SwiftMailerFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new SwiftMailerFixer();
        $this->tempDir = sys_get_temp_dir() . '/swiftmailer-fixer-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createIssue(string $file): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'SwiftMailer',
            message: sprintf('Configuration swiftmailer detectee dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 60,
        );
    }

    #[Test]
    public function testSupportsSwiftMailerIssuesWithPhpFile(): void
    {
        $issue = $this->createIssue('src/Service/Mailer.php');
        self::assertTrue($this->fixer->supports($issue));
    }

    #[Test]
    public function testDoesNotSupportOtherAnalyzer(): void
    {
        $issue = new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::DEPRECATION,
            analyzer: 'WinzouStateMachine',
            message: 'Test',
            detail: '',
            suggestion: '',
            file: 'src/test.php',
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 30,
        );
        self::assertFalse($this->fixer->supports($issue));
    }

    #[Test]
    public function testReplacesSwiftMessageInPhp(): void
    {
        $filePath = $this->tempDir . '/src/Service/Mailer.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\$msg = new Swift_Message('Hello');\n");

        $issue = $this->createIssue('src/Service/Mailer.php');
        $fix = $this->fixer->fix($issue, $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('Symfony\\Component\\Mime\\Email', $fix->fixedContent);
        self::assertStringNotContainsString('Swift_Message', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesSwiftmailerConfigInYaml(): void
    {
        $filePath = $this->tempDir . '/config/packages/swiftmailer.yaml';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "swiftmailer:\n    transport: smtp\n");

        $issue = $this->createIssue('config/packages/swiftmailer.yaml');
        $fix = $this->fixer->fix($issue, $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('framework:', $fix->fixedContent);
        self::assertStringContainsString('mailer:', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoChangesNeeded(): void
    {
        $filePath = $this->tempDir . '/src/Service/Clean.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n// Aucune reference a SwiftMailer\n");

        $issue = $this->createIssue('src/Service/Clean.php');
        $fix = $this->fixer->fix($issue, $this->tempDir);

        self::assertNull($fix);
    }
}
