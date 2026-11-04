<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\TwigHookFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests du correctif qui enregistre un Twig Hook a la place d'une surcharge de template.
 */
final class TwigHookFixerTest extends TestCase
{
    private const TEMPLATE = 'templates/bundles/SyliusShopBundle/Product/show.html.twig';

    private TwigHookFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new TwigHookFixer();
        $this->tempDir = sys_get_temp_dir() . '/twig-hook-fixer-' . uniqid('', true);
        mkdir($this->tempDir . '/templates/bundles/SyliusShopBundle/Product', 0755, true);
        mkdir($this->tempDir . '/config/packages', 0755, true);
        file_put_contents($this->tempDir . '/' . self::TEMPLATE, "{% extends '@!SyliusShop/Product/show.html.twig' %}\n");
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

    private function createIssue(): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::TWIG,
            analyzer: 'Twig Template Override',
            message: 'Surcharge de template detectee',
            detail: '',
            suggestion: '',
            file: self::TEMPLATE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hooksOf(string $yaml): array
    {
        $config = Yaml::parse($yaml);
        self::assertIsArray($config);
        self::assertIsArray($config['sylius_twig_hooks']['hooks'] ?? null);

        return $config['sylius_twig_hooks']['hooks'];
    }

    #[Test]
    public function testRegistersHookUnderSyliusTwigHooksInNewFile(): void
    {
        $fix = $this->fixer->fix($this->createIssue(), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringEndsWith('config/packages/sylius_twig_hooks.yaml', str_replace('\\', '/', $fix->filePath));
        self::assertSame(
            ['template' => 'hooks/SyliusShopBundle_Product_show.html.twig'],
            $this->hooksOf($fix->fixedContent)['sylius_shop.product.show.content']['app.sylius_shop_bundle_product_show'],
        );
    }

    #[Test]
    public function testIsMediumConfidenceBecauseHookTemplateMustBeCreated(): void
    {
        $fix = $this->fixer->fix($this->createIssue(), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::MEDIUM, $fix->confidence);
    }

    #[Test]
    public function testKeepsExistingHooksWhenAppending(): void
    {
        file_put_contents(
            $this->tempDir . '/config/packages/sylius_twig_hooks.yaml',
            "sylius_twig_hooks:\n    hooks:\n        sylius_shop.homepage.index:\n            app.banner:\n                template: 'hooks/banner.html.twig'\n",
        );

        $fix = $this->fixer->fix($this->createIssue(), $this->tempDir);

        self::assertNotNull($fix);
        $hooks = $this->hooksOf($fix->fixedContent);
        self::assertArrayHasKey('sylius_shop.homepage.index', $hooks);
        self::assertArrayHasKey('sylius_shop.product.show.content', $hooks);
    }

    #[Test]
    public function testReturnsNullWhenExistingFileHasAnotherStructure(): void
    {
        /* Une section hooks suivie d'une autre cle : l'ajout en fin de fichier tomberait au mauvais endroit */
        file_put_contents(
            $this->tempDir . '/config/packages/sylius_twig_hooks.yaml',
            "sylius_twig_hooks:\n    hooks:\n        sylius_shop.homepage.index: ~\n\nframework:\n    secret: test\n",
        );

        self::assertNull($this->fixer->fix($this->createIssue(), $this->tempDir));
    }
}
