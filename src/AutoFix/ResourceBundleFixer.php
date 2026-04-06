<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Met a jour la configuration SyliusResourceBundle pour Sylius 2.0.
 * Remplace les anciennes cles de configuration par les nouvelles.
 */
final class ResourceBundleFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Resource Bundle';

    public function getName(): string
    {
        return 'Resource Bundle Fixer';
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

        return (bool) preg_match('/\.(yaml|yml)$/i', $file);
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
        $fixedContent = $originalContent;

        /* Remplacement des anciens drivers */
        $fixedContent = str_replace('driver: doctrine/orm', 'driver: doctrine_orm', $fixedContent);
        $fixedContent = str_replace('driver: doctrine/phpcr-odm', '# TODO: PHPCR supprime — driver: doctrine/phpcr-odm', $fixedContent);
        $fixedContent = str_replace('driver: doctrine/mongodb-odm', '# TODO: MongoDB supprime — driver: doctrine/mongodb-odm', $fixedContent);

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::MEDIUM,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Mise a jour de la configuration ResourceBundle dans %s.',
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
