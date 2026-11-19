<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\TemplateDirectoryLocator;

/**
 * Tests de la localisation des répertoires de templates (templates/ et thèmes).
 */
final class TemplateDirectoryLocatorTest extends TestCase
{
    /** Chemin vers le répertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../Fixtures';

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir === null) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tempDir);
    }

    private function fixturePath(string $projectName): string
    {
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path);

        return $path;
    }

    /**
     * Crée un projet temporaire contenant les fichiers indiqués (chemin => contenu).
     *
     * @param array<string, string> $files
     */
    private function createProject(array $files): string
    {
        $this->tempDir = sys_get_temp_dir() . '/template-locator-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        foreach ($files as $relativePath => $content) {
            $path = $this->tempDir . '/' . $relativePath;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }

        $real = realpath($this->tempDir);
        self::assertNotFalse($real);

        return $real;
    }

    /**
     * Écrit une configuration sylius_theme déclarant un unique répertoire de thèmes.
     */
    private function themeConfig(string $directory): string
    {
        return "sylius_theme:\n    sources:\n        filesystem:\n            directories: ['" . $directory . "']\n";
    }

    /**
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private function relativize(string $projectPath, array $directories): array
    {
        return array_map(
            static fn (string $dir): string => TemplateDirectoryLocator::relativePath($projectPath, $dir),
            $directories,
        );
    }

    #[Test]
    public function testLocatesTemplatesAndConfiguredThemeDirectories(): void
    {
        $projectPath = $this->fixturePath('project-themed');

        self::assertSame(
            ['templates', 'themes', 'brand-themes'],
            $this->relativize($projectPath, TemplateDirectoryLocator::locate($projectPath)),
        );
    }

    #[Test]
    public function testReturnsNoDirectoryForProjectWithoutTemplates(): void
    {
        $projectPath = $this->createProject(['composer.json' => '{}']);

        self::assertSame([], TemplateDirectoryLocator::locate($projectPath));
        self::assertNull(TemplateDirectoryLocator::createTwigFinder($projectPath));
    }

    #[Test]
    public function testFindsDefaultThemesDirectoryWithoutConfiguration(): void
    {
        $projectPath = $this->createProject([
            'themes/AcmeTheme/templates/bundles/SyliusShopBundle/layout.html.twig' => '',
        ]);

        self::assertSame(
            ['themes'],
            $this->relativize($projectPath, TemplateDirectoryLocator::locateThemeRoots($projectPath)),
        );
    }

    #[Test]
    public function testIgnoresConfiguredDirectoryWithUnresolvedParameter(): void
    {
        $projectPath = $this->createProject([
            'config/packages/sylius_theme.yaml' => $this->themeConfig('%app.themes_dir%'),
            'app-themes/AcmeTheme/layout.html.twig' => '',
        ]);

        self::assertSame([], TemplateDirectoryLocator::locateThemeRoots($projectPath));
    }

    #[Test]
    public function testResolvesRelativeConfiguredDirectoryFromProjectRoot(): void
    {
        $projectPath = $this->createProject([
            'config/packages/sylius_theme.yaml' => $this->themeConfig('app-themes'),
            'app-themes/AcmeTheme/layout.html.twig' => '',
        ]);

        self::assertSame(
            ['app-themes'],
            $this->relativize($projectPath, TemplateDirectoryLocator::locateThemeRoots($projectPath)),
        );
    }

    #[Test]
    public function testDoesNotCountNestedThemeDirectoryTwice(): void
    {
        $projectPath = $this->createProject([
            'config/packages/sylius_theme.yaml' => $this->themeConfig('%kernel.project_dir%/themes/AcmeTheme'),
            'themes/AcmeTheme/layout.html.twig' => '',
        ]);

        self::assertSame(
            ['themes'],
            $this->relativize($projectPath, TemplateDirectoryLocator::locateThemeRoots($projectPath)),
        );
    }

    #[Test]
    public function testTwigFinderSkipsDependenciesBundledInThemes(): void
    {
        $projectPath = $this->fixturePath('project-themed');
        $finder = TemplateDirectoryLocator::createTwigFinder($projectPath);
        self::assertNotNull($finder);

        foreach ($finder as $file) {
            self::assertStringNotContainsString('node_modules', str_replace('\\', '/', $file->getPathname()));
        }
        self::assertCount(5, $finder);
    }

    #[Test]
    public function testKeepsHistoricalLabelForTemplatesDirectory(): void
    {
        $projectPath = $this->fixturePath('project-themed');

        self::assertSame(
            'Cart/summary.html.twig',
            TemplateDirectoryLocator::templateLabel($projectPath, $projectPath . '/templates/Cart/summary.html.twig'),
        );
        self::assertSame(
            'themes/LegacyTheme/layout.html.twig',
            TemplateDirectoryLocator::templateLabel($projectPath, $projectPath . '/themes/LegacyTheme/layout.html.twig'),
        );
    }
}
