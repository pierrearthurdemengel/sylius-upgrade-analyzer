<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Met a jour les imports de routing obsoletes dans les fichiers YAML.
 * Remplace les anciens chemins de bundle et parametres de route par les nouveaux.
 */
final class RoutingImportFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Routing Import';

    /** Mapping des anciens imports vers les nouveaux */
    private const IMPORT_MAPPING = [
        '@SyliusShopBundle/Resources/config/routing/payum.yml' => '@SyliusPayumBundle/Resources/config/routing/integrations/sylius_shop.yaml',
        '%sylius.security.new_api_route%' => '%sylius.security.api_route%',
        '%sylius.security.new_api_regex%' => '%sylius.security.api_regex%',
        '%sylius.security.new_api_admin_route%' => '%sylius.security.api_admin_route%',
        '%sylius.security.new_api_admin_regex%' => '%sylius.security.api_admin_regex%',
        '%sylius.security.new_api_shop_route%' => '%sylius.security.api_shop_route%',
        '%sylius.security.new_api_shop_regex%' => '%sylius.security.api_shop_regex%',
    ];

    public function getName(): string
    {
        return 'Routing Import Fixer';
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

        foreach (self::IMPORT_MAPPING as $old => $new) {
            $fixedContent = str_replace($old, $new, $fixedContent);
        }

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Mise a jour des imports de routing dans %s.',
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
