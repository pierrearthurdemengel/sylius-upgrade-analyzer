<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Twig;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig\TemplateOverrideInventory;

/**
 * Tests unitaires de l'inventaire des surcharges et de la comparaison avec vendor/.
 */
final class TemplateOverrideInventoryTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /**
     * Vérifie qu'une copie réindentée, en CRLF et avec des lignes vides reste identique à l'original.
     */
    #[Test]
    public function testIgnoresIndentationBlankLinesAndLineEndingsWhenComparing(): void
    {
        $original = "{% block content %}\n    <div class=\"ui segment\">\n    </div>\n{% endblock %}\n";
        $copy = "{% block content %}\r\n\r\n<div class=\"ui segment\">\r\n</div>\r\n{% endblock %}";

        self::assertSame(0, TemplateOverrideInventory::changedLines($copy, $original));
        self::assertSame(
            TemplateOverrideInventory::fingerprint($original),
            TemplateOverrideInventory::fingerprint($copy),
        );
    }

    /**
     * Vérifie qu'une ligne modifiée compte pour une seule ligne personnalisée.
     */
    #[Test]
    public function testCountsModifiedLineOnce(): void
    {
        $original = "{% block content %}\n<h1>{{ product.name }}</h1>\n{% endblock %}";
        $modified = "{% block content %}\n<h1 class=\"title\">{{ product.name }}</h1>\n{% endblock %}";

        self::assertSame(1, TemplateOverrideInventory::changedLines($modified, $original));
    }

    /**
     * Vérifie que les lignes ajoutées comme les lignes retirées sont comptées.
     */
    #[Test]
    public function testCountsAddedAndRemovedLines(): void
    {
        $original = "a\nb\nc";

        self::assertSame(2, TemplateOverrideInventory::changedLines("a\nb\nc\nd\ne", $original));
        self::assertSame(2, TemplateOverrideInventory::changedLines('a', $original));
    }

    /**
     * Vérifie que l'original est retrouvé dans vendor/sylius/sylius.
     */
    #[Test]
    public function testFindsVendorOriginalInSyliusMonorepo(): void
    {
        $projectPath = (string) realpath(self::FIXTURES_PATH . '/project-vendor-copies');

        $original = TemplateOverrideInventory::findVendorOriginal($projectPath, 'SyliusShopBundle/Product/show.html.twig');

        self::assertNotNull($original);
        self::assertStringContainsString('sylius.shop.product.show.content', $original);
    }

    /**
     * Vérifie l'absence d'original pour un template inconnu de Sylius ou propre au projet.
     */
    #[Test]
    public function testReturnsNullWithoutVendorOriginal(): void
    {
        $projectPath = (string) realpath(self::FIXTURES_PATH . '/project-vendor-copies');

        self::assertNull(TemplateOverrideInventory::findVendorOriginal($projectPath, 'SyliusShopBundle/Missing/index.html.twig'));
        self::assertNull(TemplateOverrideInventory::findVendorOriginal($projectPath, 'Product/custom.html.twig'));
    }

    /**
     * Vérifie que les surcharges de templates/ précèdent celles des thèmes, qui servent de variantes.
     */
    #[Test]
    public function testListsProjectOverridesBeforeThemeOverrides(): void
    {
        $projectPath = (string) realpath(self::FIXTURES_PATH . '/project-vendor-copies');

        $paths = array_map(
            static fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()),
            TemplateOverrideInventory::findOverrideFiles($projectPath),
        );

        self::assertSame([
            'Order/show.html.twig',
            'Cart/summary.html.twig',
            'Product/show.html.twig',
            'Cart/summary.html.twig',
            'Product/show.html.twig',
        ], $paths);
        self::assertCount(5, TemplateOverrideInventory::overridePathSet($projectPath));
    }
}
