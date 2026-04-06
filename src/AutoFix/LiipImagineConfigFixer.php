<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Corrige la configuration Liip Imagine en remplaçant les resolver/loader
 * nommés "default" par "sylius_image" dans les fichiers YAML.
 */
final class LiipImagineConfigFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Liip Imagine Config';

    public function getName(): string
    {
        return 'Liip Imagine Config Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        $file = $issue->getFile();

        return $file !== null && (bool) preg_match('/\.(yaml|yml)$/i', $file);
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

        /* Remplacement du resolver "default" par "sylius_image" dans les blocs liip_imagine */
        $fixedContent = (string) preg_replace(
            '/(resolvers:\s*\n\s+)default:/m',
            '${1}sylius_image:',
            $fixedContent,
        );

        /* Remplacement du loader "default" par "sylius_image" */
        $fixedContent = (string) preg_replace(
            '/(loaders:\s*\n\s+)default:/m',
            '${1}sylius_image:',
            $fixedContent,
        );

        /* Remplacement des références "default" dans les clés data_loader et cache */
        $fixedContent = (string) preg_replace(
            '/^(\s+data_loader:\s+)default\s*$/m',
            '${1}sylius_image',
            $fixedContent,
        );
        $fixedContent = (string) preg_replace(
            '/^(\s+cache:\s+)default\s*$/m',
            '${1}sylius_image',
            $fixedContent,
        );

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Remplacement du resolver/loader "default" par "sylius_image" dans %s.',
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
