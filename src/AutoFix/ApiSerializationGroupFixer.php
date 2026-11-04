<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Ajoute le prefixe "sylius:" aux groupes de serialization dans les fichiers PHP et YAML.
 * Sylius 2.0 requiert le prefixe "sylius:" sur tous les groupes de serialization.
 */
final class ApiSerializationGroupFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'API Serialization Group';

    /**
     * Groupe admin: ou shop: entre guillemets, sans prefixe sylius: (meme motif que l'analyseur).
     * UPGRADE-API-2.0.md : admin:product:index devient sylius:admin:product:index.
     */
    private const UNPREFIXED_GROUP_PATTERN = '/([\'"])((?:admin|shop):[a-z_]+(?::[a-z_]+)*)\1/';

    public function getName(): string
    {
        return 'API Serialization Group Fixer';
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

        return (bool) preg_match('/\.(php|yaml|yml|xml)$/i', $file);
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
        /* Les groupes propres au projet sont prefixes eux aussi : le renommage reste coherent
           tant que tous les fichiers signales sont corriges ensemble. */
        $fixedContent = (string) preg_replace(self::UNPREFIXED_GROUP_PATTERN, '$1sylius:$2$1', $originalContent);

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::MEDIUM,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Ajout du prefixe sylius: aux groupes de serialization dans %s.',
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
