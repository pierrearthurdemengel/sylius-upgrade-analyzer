<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\FormTypeExtensionPriorityAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des extensions de types de formulaire.
 * Verifie la detection des extensions sans priorite explicite.
 */
final class FormTypeExtensionPriorityAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('formext_', true);
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
        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand un fichier PHP reference AbstractTypeExtension.
     */
    #[Test]
    public function testSupportsReturnsTrueWithAbstractTypeExtension(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomFormExtension.php', <<<'PHP'
<?php

namespace App\Form;

use Symfony\Component\Form\AbstractTypeExtension;

class CustomFormExtension extends AbstractTypeExtension
{
}
PHP);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection d'une extension sans priorite explicite.
     */
    #[Test]
    public function testDetectsExtensionWithoutPriority(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomFormExtension.php', <<<'PHP'
<?php

namespace App\Form;

use Symfony\Component\Form\AbstractTypeExtension;

class CustomFormExtension extends AbstractTypeExtension
{
    public function buildForm($builder, array $options): void {}
}
PHP);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $extensionIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'CustomFormExtension'),
        );
        self::assertNotEmpty($extensionIssues);
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomFormExtension.php', <<<'PHP'
<?php

namespace App\Form;

use Symfony\Component\Form\AbstractTypeExtension;

class CustomFormExtension extends AbstractTypeExtension
{
}
PHP);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
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
        $analyzer = new FormTypeExtensionPriorityAnalyzer();

        self::assertSame('Form Type Extension Priority', $analyzer->getName());
    }
}
