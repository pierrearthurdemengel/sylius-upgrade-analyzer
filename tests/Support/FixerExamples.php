<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Support;

use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\AutoFixInterface;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\MigrationFix;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Rejoue les exemples avant/après de tests/Fixtures/fixer-examples.php et génère
 * docs/fixer-examples.md, pour que la documentation montre toujours la sortie réelle
 * des correctifs.
 *
 * @phpstan-type FixerExample array{
 *     fixer: class-string<AutoFixInterface>,
 *     file: string,
 *     line: int|null,
 *     message: string|null,
 *     content: string,
 *     context?: array<string, string>,
 *     target: string,
 *     before: string,
 *     after: string,
 * }
 */
final class FixerExamples
{
    /** Répertoire de travail du dernier exemple rejoué, pour exprimer la cible en chemin relatif */
    private static string $lastProjectDir = '';

    /**
     * Charge les exemples figés.
     *
     * @return list<FixerExample>
     */
    public static function load(): array
    {
        /** @var list<FixerExample> $examples */
        $examples = require \dirname(__DIR__) . '/Fixtures/fixer-examples.php';

        return $examples;
    }

    /**
     * Écrit le fichier d'entrée, et les fichiers de contexte éventuels (composer.json...),
     * dans un projet temporaire puis passe l'issue correspondante au correctif. Le projet
     * temporaire est supprimé avant le retour.
     *
     * @param array{fixer: class-string<AutoFixInterface>, file: string, line: int|null, message: string|null, content: string, context?: array<string, string>} $example
     */
    public static function run(array $example): ?MigrationFix
    {
        $projectDir = sys_get_temp_dir() . '/fixer-example-' . uniqid('', true);
        foreach ([$example['file'] => $example['content']] + ($example['context'] ?? []) as $file => $content) {
            $path = $projectDir . '/' . $file;
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }
        self::$lastProjectDir = $projectDir;

        $fixer = new $example['fixer']();
        $issue = new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::DEPRECATION,
            analyzer: self::targetAnalyzer($fixer),
            message: $example['message'] ?? '',
            detail: '',
            suggestion: '',
            file: $example['file'],
            line: $example['line'],
        );

        try {
            return $fixer->supports($issue) ? $fixer->fix($issue, $projectDir) : null;
        } finally {
            self::removeDirectory($projectDir);
        }
    }

    /**
     * Chemin du fichier modifié, relatif au projet temporaire du dernier exemple rejoué.
     */
    public static function relativeTarget(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $base = str_replace('\\', '/', self::$lastProjectDir) . '/';

        return str_starts_with($normalized, $base) ? substr($normalized, \strlen($base)) : basename($normalized);
    }

    /**
     * Génère le document Markdown, regroupé par niveau de confiance.
     *
     * @param list<FixerExample> $examples
     */
    public static function render(array $examples): string
    {
        $levels = [
            FixConfidence::HIGH->value => 'High confidence',
            FixConfidence::MEDIUM->value => 'Medium confidence',
            FixConfidence::LOW->value => 'Low confidence',
        ];
        $sections = array_fill_keys(array_keys($levels), '');

        foreach ($examples as $example) {
            $fix = self::run($example);
            if ($fix === null) {
                continue;
            }

            $name = (new $example['fixer']())->getName();
            $language = self::language($example['target']);
            $sections[$fix->confidence->value] .= sprintf(
                "### %s\n\n`%s`%s\n\nBefore:\n\n```%s\n%s```\n\nAfter:\n\n```%s\n%s```\n\n",
                $name,
                $example['target'],
                $example['target'] !== $example['file'] ? sprintf(' (issue reported on `%s`)', $example['file']) : '',
                $language,
                self::withTrailingNewline($example['before'] === '' ? '(new file)' : $example['before']),
                $language,
                self::withTrailingNewline($example['after']),
            );
        }

        $document = "# Auto-Fix Examples\n\n"
            . 'Real input and output of each auto-fixer. This file is generated from `tests/Fixtures/fixer-examples.php`: '
            . "`FixerExamplesTest` replays every example against the fixer and fails if the output or this document drifts.\n\n"
            . "See [fixers.md](fixers.md) for what each confidence level means and how fixes are applied.\n\n";

        foreach ($levels as $level => $title) {
            if ($sections[$level] !== '') {
                $document .= sprintf("## %s\n\n%s", $title, $sections[$level]);
            }
        }

        return rtrim($document) . "\n";
    }

    /**
     * Lit l'analyseur ciblé par le correctif, déclaré dans sa constante privée TARGET_ANALYZER.
     */
    private static function targetAnalyzer(AutoFixInterface $fixer): string
    {
        $constant = (new \ReflectionClass($fixer))->getReflectionConstant('TARGET_ANALYZER');
        $value = $constant !== false ? $constant->getValue() : null;

        return \is_string($value) ? $value : '';
    }

    private static function language(string $file): string
    {
        return match (true) {
            str_ends_with($file, '.php') => 'php',
            str_ends_with($file, '.twig') => 'twig',
            str_ends_with($file, '.xml') => 'xml',
            str_ends_with($file, '.json') => 'json',
            str_ends_with($file, '.yaml'), str_ends_with($file, '.yml') => 'yaml',
            default => 'text',
        };
    }

    private static function withTrailingNewline(string $content): string
    {
        return str_ends_with($content, "\n") ? $content : $content . "\n";
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
