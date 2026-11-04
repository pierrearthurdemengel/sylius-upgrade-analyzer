<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Tests\Support\FixerExamples;

/**
 * Rejoue les exemples avant/apres de chaque correctif et verifie que docs/fixer-examples.md
 * montre toujours la sortie reelle.
 *
 * @phpstan-import-type FixerExample from FixerExamples
 */
final class FixerExamplesTest extends TestCase
{
    /**
     * @return iterable<string, array{FixerExample}>
     */
    public static function examples(): iterable
    {
        foreach (FixerExamples::load() as $example) {
            yield (new \ReflectionClass($example['fixer']))->getShortName() => [$example];
        }
    }

    /**
     * @param FixerExample $example
     */
    #[Test]
    #[DataProvider('examples')]
    public function testFixerProducesDocumentedOutput(array $example): void
    {
        $fix = FixerExamples::run($example);

        self::assertNotNull($fix, 'Le correctif ne produit plus rien pour son exemple.');
        self::assertSame($example['target'], FixerExamples::relativeTarget($fix->filePath));
        self::assertSame($example['before'], $fix->originalContent);
        self::assertSame($example['after'], $fix->fixedContent);
    }

    #[Test]
    public function testEveryRegisteredFixerHasAnExample(): void
    {
        $documented = array_map(static fn (array $example): string => $example['fixer'], FixerExamples::load());
        $fixers = array_map(
            static fn (string $file): string => 'PierreArthur\\SyliusUpgradeAnalyzer\\AutoFix\\' . basename($file, '.php'),
            glob(\dirname(__DIR__, 3) . '/src/AutoFix/*Fixer.php') ?: [],
        );

        self::assertSame([], array_values(array_diff($fixers, $documented)));
    }

    #[Test]
    public function testDocumentationIsUpToDate(): void
    {
        self::assertSame(
            FixerExamples::render(FixerExamples::load()),
            str_replace("\r\n", "\n", (string) file_get_contents(\dirname(__DIR__, 3) . '/docs/fixer-examples.md')),
            'docs/fixer-examples.md est perime : lancer php .github/scripts/generate-fixer-examples.php',
        );
    }
}
