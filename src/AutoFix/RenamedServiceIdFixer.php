<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Corrige les identifiants de services renommés dans les fichiers de configuration YAML.
 * Remplace les anciens IDs par les nouveaux tels que définis dans l'UPGRADE-2.0.md.
 */
final class RenamedServiceIdFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Renamed Service ID';

    /**
     * Renommages repris mot pour mot des tableaux de UPGRADE-2.0.md (ancien => nouveau).
     * Les identifiants sylius.console.command.* sont deja les noms de Sylius 2.0 : ils ne
     * doivent pas etre renommes.
     */
    public const SERVICE_ID_MAPPING = [
        'sylius.twig.extension.sort_by' => 'sylius_twig_extra.twig.extension.sort_by',
        'sylius.province_naming_provider' => 'sylius.provider.province_naming',
        'sylius.zone_matcher' => 'sylius.matcher.zone',
        'sylius.address_comparator' => 'sylius.comparator.address',
        'sylius.controller.admin.dashboard' => 'sylius_admin.controller.dashboard',
        'sylius.controller.shop.contact' => 'sylius_shop.controller.contact',
        'sylius.controller.shop.currency_switch' => 'sylius_shop.controller.currency_switch',
        'sylius.controller.shop.locale_switch' => 'sylius_shop.controller.locale_switch',
        'sylius.controller.shop.register_thank_you' => 'sylius_shop.controller.register_thank_you',
        'sylius.form_registry.payum_gateway_config' => 'sylius.form_registry.payment_gateway_config',
    ];

    public function getName(): string
    {
        return 'Renamed Service ID Fixer';
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

        return (bool) preg_match('/\.(yaml|yml|xml)$/i', $file);
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

        /* Identifiant complet uniquement : sylius.zone_matcher_custom n'est pas sylius.zone_matcher */
        foreach (self::SERVICE_ID_MAPPING as $oldId => $newId) {
            $fixedContent = (string) preg_replace(
                '/(?<![\w.])' . preg_quote($oldId, '/') . '(?![\w.])/',
                $newId,
                $fixedContent,
            );
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
                'Remplacement des identifiants de services obsolètes dans %s.',
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
