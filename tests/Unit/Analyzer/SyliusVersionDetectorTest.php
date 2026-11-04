<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\SyliusVersionDetector;

/**
 * Tests de la detection de la version de Sylius d'un projet.
 */
final class SyliusVersionDetectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/version-detector-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            if (is_file($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
        }
        rmdir($this->dir);
    }

    /** Verifie que la version installee est lue dans composer.lock en priorite. */
    #[Test]
    public function testReadsInstalledVersionFromLockFile(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"require": {"sylius/sylius": "~1.14.0"}}');
        file_put_contents($this->dir . '/composer.lock', '{"packages": [{"name": "symfony/console", "version": "v6.4.1"}, {"name": "sylius/sylius", "version": "v1.14.21"}]}');

        self::assertSame('v1.14.21', (new SyliusVersionDetector())->detect($this->dir));
    }

    /** Verifie le repli sur la contrainte de composer.json pour un depot sans lock (squelette, plugin). */
    #[Test]
    public function testFallsBackToComposerJsonConstraintWithoutLockFile(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"require": {"php": "^8.1", "sylius/core-bundle": "^1.13 || ^1.14"}}');

        self::assertSame('^1.13 || ^1.14 (composer.json)', (new SyliusVersionDetector())->detect($this->dir));
    }

    /** Verifie qu'un projet sans Sylius ni fichier Composer lisible ne renvoie aucune version. */
    #[Test]
    public function testReturnsNullWithoutSyliusPackage(): void
    {
        self::assertNull((new SyliusVersionDetector())->detect($this->dir));

        file_put_contents($this->dir . '/composer.json', '{"require": {"symfony/framework-bundle": "^7.2"}}');
        file_put_contents($this->dir . '/composer.lock', 'not json');

        self::assertNull((new SyliusVersionDetector())->detect($this->dir));
    }
}
