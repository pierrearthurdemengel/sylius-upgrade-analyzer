<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Corrige les noms de firewalls dépréciés dans security.yaml.
 * Renomme les firewalls new_api_* vers api_* et met à jour
 * les paramètres de sécurité associés.
 */
final class SecurityFirewallFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Security Firewall';

    /** Mapping des noms de firewalls dépréciés */
    private const FIREWALL_MAPPING = [
        'new_api_admin_user' => 'api_admin',
        'new_api_shop_user' => 'api_shop',
    ];

    /** Mapping des paramètres de sécurité dépréciés */
    private const PARAM_MAPPING = [
        'sylius.security.new_api_route' => 'sylius.security.api_route',
        'sylius.security.new_api_regex' => 'sylius.security.api_regex',
        'sylius.security.new_api_admin_route' => 'sylius.security.api_admin_route',
        'sylius.security.new_api_admin_regex' => 'sylius.security.api_admin_regex',
        'sylius.security.new_api_shop_route' => 'sylius.security.api_shop_route',
        'sylius.security.new_api_shop_regex' => 'sylius.security.api_shop_regex',
    ];

    public function getName(): string
    {
        return 'Security Firewall Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        $file = $issue->getFile();

        return $file !== null && (bool) preg_match('/security\.(yaml|yml)$/i', $file);
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

        /* Remplacement des noms de firewalls */
        foreach (self::FIREWALL_MAPPING as $oldName => $newName) {
            $fixedContent = str_replace($oldName, $newName, $fixedContent);
        }

        /* Remplacement des paramètres de sécurité */
        foreach (self::PARAM_MAPPING as $oldParam => $newParam) {
            $fixedContent = str_replace($oldParam, $newParam, $fixedContent);
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
                'Renommage des firewalls new_api_* vers api_* et des paramètres '
                . 'de sécurité associés dans %s.',
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
