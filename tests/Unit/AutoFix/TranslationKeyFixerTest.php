<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\TranslationKeyFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de cles de traduction renommees.
 */
final class TranslationKeyFixerTest extends TestCase
{
    private TranslationKeyFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new TranslationKeyFixer();
        $this->tempDir = sys_get_temp_dir() . '/translation-key-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/translations', 0755, true);
        mkdir($this->tempDir . '/templates', 0755, true);
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
            severity: Severity::SUGGESTION,
            category: Category::DEPRECATION,
            analyzer: 'Translation Key',
            message: sprintf('Cle de traduction obsolete detectee dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 10,
        );
    }

    #[Test]
    public function testReplacesOldTranslationPrefixInYaml(): void
    {
        $file = 'translations/messages.fr.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius.ui.admin.dashboard: 'Tableau de bord'\nsylius.ui.admin.orders: 'Commandes'\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString('sylius.admin.dashboard', $fix->fixedContent);
        self::assertStringContainsString('sylius.admin.orders', $fix->fixedContent);
        self::assertStringNotContainsString('sylius.ui.admin', $fix->fixedContent);
    }

    #[Test]
    public function testReturnsNullWhenNoOldPrefixes(): void
    {
        $file = 'translations/messages.fr.yaml';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "sylius.admin.dashboard: 'Tableau de bord'\napp.custom.label: 'Mon label'\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNull($fix);
    }

    #[Test]
    public function testSupportsTwigFiles(): void
    {
        $issue = $this->createIssue('templates/shop/layout.html.twig');
        self::assertTrue($this->fixer->supports($issue));
    }

    #[Test]
    public function testSupportsYamlFiles(): void
    {
        $issue = $this->createIssue('translations/messages.fr.yaml');
        self::assertTrue($this->fixer->supports($issue));
    }

    #[Test]
    public function testReplacesEmailPrefixInTwig(): void
    {
        $file = 'templates/email/order.html.twig';
        $filePath = $this->tempDir . '/' . $file;
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, "{{ 'sylius.email.order_confirmation'|trans }}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('sylius.notification.order_confirmation', $fix->fixedContent);
        self::assertStringNotContainsString('sylius.email', $fix->fixedContent);
    }
}
