<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Supprime la configuration `use_webpack` des fichiers YAML
 * et les références dans les templates Twig.
 * Cette option a été supprimée dans Sylius 2.0.
 */
final class UseWebpackConfigFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Use Webpack Config';

    public function getName(): string
    {
        return 'Use Webpack Config Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        $file = $issue->getFile();

        return $file !== null && (bool) preg_match('/\.(yaml|yml|twig)$/i', $file);
    }

    public function fix(MigrationIssue $issue, string $projectPath): ?MigrationFix
    {
        $filePath = $issue->getFile();
        if ($filePath === null) {
            return null;
        }

        $absolutePath = $this->resolveAbsolutePath($filePath, $projectPath);
        if (!file_exists($absolutePath)) {
            return null;
        }

        $originalContent = (string) file_get_contents($absolutePath);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $fixedContent = match ($extension) {
            'yaml', 'yml' => $this->fixYamlFile($originalContent),
            'twig' => $this->fixTwigFile($originalContent),
            default => $originalContent,
        };

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Suppression de la configuration use_webpack dans %s.',
                basename($absolutePath),
            ),
        );
    }

    /**
     * Supprime les lignes use_webpack dans les fichiers YAML.
     */
    private function fixYamlFile(string $content): string
    {
        /* Supprime les lignes contenant use_webpack: true/false */
        return (string) preg_replace(
            '/^\s+use_webpack:\s+(true|false)\s*\n/m',
            '',
            $content,
        );
    }

    /**
     * Supprime les conditions Twig basées sur use_webpack.
     */
    private function fixTwigFile(string $content): string
    {
        /* Supprime les blocs {% if use_webpack %} ... {% endif %} simples */
        $content = (string) preg_replace(
            '/\{%\s*if\s+use_webpack\s*%\}(.*?)\{%\s*endif\s*%\}/s',
            '$1',
            $content,
        );

        /* Supprime les conditions inversées {% if not use_webpack %} ... {% endif %} */
        $content = (string) preg_replace(
            '/\{%\s*if\s+not\s+use_webpack\s*%\}(.*?)\{%\s*endif\s*%\}/s',
            '',
            $content,
        );

        return $content;
    }

    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
