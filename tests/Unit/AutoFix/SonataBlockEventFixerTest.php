<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\SonataBlockEventFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer Sonata Block Event → hook().
 */
final class SonataBlockEventFixerTest extends TestCase
{
    private SonataBlockEventFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new SonataBlockEventFixer();
        $this->tempDir = sys_get_temp_dir() . '/sonata-fixer-test-' . uniqid();
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
            severity: Severity::BREAKING,
            category: Category::TWIG,
            analyzer: 'Sonata Block Event',
            message: sprintf('Appel a sonata_block_render_event() detecte dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 30,
        );
    }

    #[Test]
    public function testReplacesSonataBlockRenderEvent(): void
    {
        $file = 'templates/layout.html.twig';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "{% block body %}\n{{ sonata_block_render_event('sylius.shop.layout.header') }}\n{% endblock %}\n");

        $fix = $this->fixer->fix($this->createIssue($file), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
        self::assertStringContainsString("{{ hook('sylius.shop.layout.header') }}", $fix->fixedContent);
        self::assertStringNotContainsString('sonata_block_render_event', $fix->fixedContent);
    }

    #[Test]
    public function testReplacesSyliusTemplateEvent(): void
    {
        $file = 'templates/show.html.twig';
        $filePath = $this->tempDir . '/' . $file;
        file_put_contents($filePath, "{{ sylius_template_event('sylius.shop.product.show') }}\n");

        $issue = new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::TWIG,
            analyzer: 'Sonata Block Event',
            message: sprintf('Appel a sylius_template_event() detecte dans %s', $file),
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 30,
        );

        $fix = $this->fixer->fix($issue, $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString("{{ hook('sylius.shop.product.show') }}", $fix->fixedContent);
    }

    #[Test]
    public function testDoesNotSupportPhpFiles(): void
    {
        $issue = $this->createIssue('src/Controller.php');
        self::assertFalse($this->fixer->supports($issue));
    }

    #[Test]
    public function testSupportsTwigFiles(): void
    {
        $issue = $this->createIssue('templates/layout.html.twig');
        self::assertTrue($this->fixer->supports($issue));
    }
}
