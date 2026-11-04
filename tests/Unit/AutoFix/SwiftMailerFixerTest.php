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
use Symfony\Component\Yaml\Yaml;

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
        /* L'API d'Email differe de Swift_Message : le correctif n'est qu'un point de depart */
        self::assertSame(FixConfidence::LOW, $fix->confidence);
        self::assertStringContainsString('new \\Symfony\\Component\\Mime\\Email(', $fix->fixedContent);
        self::assertStringNotContainsString('Swift_Message', $fix->fixedContent);
    }

    #[Test]
    public function testUsesFullyQualifiedNamesInNamespacedFile(): void
    {
        $filePath = $this->tempDir . '/src/Service/Mailer.php';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "<?php\n\nnamespace App\\Service;\n\nuse Swift_Mailer;\n\nfinal class Mailer\n{\n    public function __construct(private Swift_Mailer \$mailer, private \\Swift_MessageFactory \$factory)\n    {\n    }\n}\n");

        $fix = $this->fixer->fix($this->createIssue('src/Service/Mailer.php'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('use \\Symfony\\Component\\Mailer\\MailerInterface;', $fix->fixedContent);
        self::assertStringContainsString('private \\Symfony\\Component\\Mailer\\MailerInterface $mailer', $fix->fixedContent);
        /* Une classe dont le nom commence comme une classe SwiftMailer n'est pas touchee */
        self::assertStringContainsString('\\Swift_MessageFactory $factory', $fix->fixedContent);
    }

    #[Test]
    public function testGeneratesValidMailerConfigurationFromSwiftmailerBlock(): void
    {
        $filePath = $this->tempDir . '/config/packages/swiftmailer.yaml';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "swiftmailer:\n    url: '%env(MAILER_URL)%'\n    spool: { type: memory }\n\nservices:\n    App\\Mailer:\n        arguments: ['@swiftmailer.mailer']\n");

        $fix = $this->fixer->fix($this->createIssue('config/packages/swiftmailer.yaml'), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertSame(
            [
                'framework' => ['mailer' => ['dsn' => '%env(MAILER_DSN)%']],
                'services' => ['App\\Mailer' => ['arguments' => ['@mailer']]],
            ],
            Yaml::parse($fix->fixedContent),
        );
        self::assertStringContainsString("#     url: '%env(MAILER_URL)%'", $fix->fixedContent);
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
