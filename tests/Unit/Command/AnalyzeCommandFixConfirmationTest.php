<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\AutoFixInterface;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixEngine;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\MigrationFix;
use PierreArthur\SyliusUpgradeAnalyzer\Command\AnalyzeCommand;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ConsoleReporter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests de l'application des correctifs automatiques : en terminal interactif, aucun
 * fichier n'est modifie sans confirmation ; en CI, --fix s'applique comme avant.
 */
final class AnalyzeCommandFixConfirmationTest extends TestCase
{
    private const ORIGINAL = "swiftmailer:\n    url: '%env(MAILER_URL)%'\n";
    private const FIXED = "framework:\n    mailer:\n        dsn: '%env(MAILER_DSN)%'\n";

    private string $projectDir;
    private string $configFile;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/fix-confirm-' . uniqid('', true);
        mkdir($this->projectDir . '/config/packages', 0755, true);
        file_put_contents($this->projectDir . '/composer.json', '{"name": "acme/sylius-shop"}');
        $this->configFile = $this->projectDir . '/config/packages/swiftmailer.yaml';
        file_put_contents($this->configFile, self::ORIGINAL);
    }

    protected function tearDown(): void
    {
        foreach ([$this->configFile, $this->projectDir . '/composer.json'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->projectDir . '/config/packages');
        rmdir($this->projectDir . '/config');
        rmdir($this->projectDir);
    }

    private function createTester(FixConfidence $confidence = FixConfidence::HIGH): CommandTester
    {
        $configFile = $this->configFile;

        $analyzer = new class ($configFile) implements AnalyzerInterface {
            public function __construct(private readonly string $file)
            {
            }

            public function getName(): string
            {
                return 'SwiftMailer';
            }

            public function supports(MigrationReport $report): bool
            {
                return true;
            }

            public function analyze(MigrationReport $report): void
            {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: 'Configuration SwiftMailer detectee',
                    detail: 'SwiftMailer est remplace par symfony/mailer.',
                    suggestion: 'Migrer vers framework.mailer.',
                    file: $this->file,
                ));
            }
        };

        $fixer = new class ($confidence) implements AutoFixInterface {
            public function __construct(private readonly FixConfidence $confidence)
            {
            }

            public function getName(): string
            {
                return 'SwiftMailer Fixer';
            }

            public function supports(MigrationIssue $issue): bool
            {
                return $issue->getFile() !== null;
            }

            public function fix(MigrationIssue $issue, string $projectPath): ?MigrationFix
            {
                return new MigrationFix(
                    confidence: $this->confidence,
                    filePath: (string) $issue->getFile(),
                    originalContent: AnalyzeCommandFixConfirmationTest::originalContent(),
                    fixedContent: AnalyzeCommandFixConfirmationTest::fixedContent(),
                    description: 'Migration SwiftMailer vers symfony/mailer',
                );
            }
        };

        return new CommandTester(new AnalyzeCommand([$analyzer], [new ConsoleReporter()], new FixEngine([$fixer])));
    }

    public static function originalContent(): string
    {
        return self::ORIGINAL;
    }

    public static function fixedContent(): string
    {
        return self::FIXED;
    }

    /** Verifie qu'un refus en terminal interactif laisse les fichiers intacts. */
    #[Test]
    public function testDoesNotWriteFilesWhenUserDeclinesInInteractiveMode(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['no']);

        $tester->execute(['project-path' => $this->projectDir, '--fix' => true, '--no-promo' => true], ['interactive' => true]);

        self::assertSame(self::ORIGINAL, file_get_contents($this->configFile));
        self::assertStringContainsString('Aucune modification appliquée', $tester->getDisplay());
    }

    /** Verifie que le patch complet est affiche avant la question de confirmation. */
    #[Test]
    public function testShowsPatchBeforeAskingForConfirmation(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['no']);

        $tester->execute(['project-path' => $this->projectDir, '--fix' => true, '--no-promo' => true], ['interactive' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('+    mailer:', $display);
        self::assertLessThan(strpos($display, 'Appliquer ces 1 correctif(s)'), strpos($display, '+    mailer:'));
    }

    /** Verifie que --min-confidence ecarte les correctifs moins fiables que le seuil. */
    #[Test]
    public function testSkipsFixesBelowMinimumConfidence(): void
    {
        $tester = $this->createTester(FixConfidence::MEDIUM);

        $tester->execute(
            ['project-path' => $this->projectDir, '--fix' => true, '--min-confidence' => 'high', '--no-promo' => true],
            ['interactive' => false],
        );

        self::assertSame(self::ORIGINAL, file_get_contents($this->configFile));
        self::assertStringContainsString('Aucun correctif de confiance high ou supérieure (1 écarté(s))', $tester->getDisplay());
    }

    /** Verifie qu'un correctif au niveau du seuil reste applique. */
    #[Test]
    public function testAppliesFixesAtMinimumConfidence(): void
    {
        $tester = $this->createTester(FixConfidence::MEDIUM);

        $tester->execute(
            ['project-path' => $this->projectDir, '--fix' => true, '--min-confidence' => 'medium', '--no-promo' => true],
            ['interactive' => false],
        );

        self::assertSame(self::FIXED, file_get_contents($this->configFile));
    }

    /** Verifie qu'une valeur inconnue de --min-confidence est refusee avant toute analyse. */
    #[Test]
    public function testRejectsUnknownMinimumConfidence(): void
    {
        $tester = $this->createTester();

        $code = $tester->execute(
            ['project-path' => $this->projectDir, '--fix' => true, '--min-confidence' => 'safe', '--no-promo' => true],
            ['interactive' => false],
        );

        self::assertSame(1, $code);
        self::assertSame(self::ORIGINAL, file_get_contents($this->configFile));
        self::assertStringContainsString('--min-confidence attend high, medium ou low', $tester->getDisplay());
    }

    /** Verifie qu'une confirmation en terminal interactif applique les correctifs. */
    #[Test]
    public function testWritesFilesWhenUserConfirmsInInteractiveMode(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['yes']);

        $tester->execute(['project-path' => $this->projectDir, '--fix' => true, '--no-promo' => true], ['interactive' => true]);

        self::assertSame(self::FIXED, file_get_contents($this->configFile));
        self::assertStringContainsString('1 correctif(s) appliqué(s)', $tester->getDisplay());
    }

    /** Verifie qu'en CI (sans interaction), --fix s'applique sans question, comme auparavant. */
    #[Test]
    public function testAppliesFixesWithoutQuestionInNonInteractiveMode(): void
    {
        $tester = $this->createTester();

        $tester->execute(['project-path' => $this->projectDir, '--fix' => true, '--no-promo' => true], ['interactive' => false]);

        self::assertSame(self::FIXED, file_get_contents($this->configFile));
    }

    /** Verifie que --dry-run n'ecrit jamais, meme sans interaction. */
    #[Test]
    public function testDryRunNeverWritesFiles(): void
    {
        $tester = $this->createTester();

        $tester->execute(['project-path' => $this->projectDir, '--dry-run' => true, '--no-promo' => true], ['interactive' => false]);

        self::assertSame(self::ORIGINAL, file_get_contents($this->configFile));
        self::assertStringContainsString('Mode dry-run', $tester->getDisplay());
    }
}
