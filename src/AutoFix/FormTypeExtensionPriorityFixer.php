<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Ajoute la methode getPriority() aux FormTypeExtensions qui n'en ont pas.
 * Sylius 2.0 requiert une priorite explicite pour eviter les conflits.
 */
final class FormTypeExtensionPriorityFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Form Type Extension Priority';

    public function getName(): string
    {
        return 'Form Type Extension Priority Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        $file = $issue->getFile();
        if ($file === null) {
            return false;
        }

        return (bool) preg_match('/\.php$/i', $file);
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

        /* Verification que la methode n'existe pas deja */
        if (str_contains($originalContent, 'getExtendedTypes')
            && !str_contains($originalContent, 'public static function getPriority')
        ) {
            /* Ajout de la methode getPriority() avant la derniere accolade fermante de la classe */
            $priorityMethod = "\n    /**\n     * Priorite de l'extension de type de formulaire.\n     */\n"
                . "    public static function getPriority(): int\n    {\n        return 0;\n    }\n";

            $fixedContent = (string) preg_replace(
                '/(\n}\s*)$/',
                $priorityMethod . '$1',
                $originalContent,
            );
        } else {
            $fixedContent = $originalContent;
        }

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::MEDIUM,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Ajout de la methode getPriority() dans %s.',
                basename($absolutePath),
            ),
        );
    }

    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
