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

    /** Mapping des anciens identifiants vers les nouveaux */
    private const SERVICE_ID_MAPPING = [
        'sylius.twig.extension.sort_by' => 'sylius_twig_extra.twig.extension.sort_by',
        'sylius.twig.extension.test_form_attribute' => 'sylius_twig_extra.twig.extension.test_form_attribute',
        'sylius.twig.extension.test_html_attribute' => 'sylius_twig_extra.twig.extension.test_html_attribute',
        'sylius.console.command.cancel_unpaid_orders' => 'sylius_order.console.command.cancel_unpaid_orders',
        'sylius.console.command.install' => 'sylius_core.console.command.install',
        'sylius.console.command.setup' => 'sylius_core.console.command.setup',
        'sylius.console.command.show_available_plugins' => 'sylius_core.console.command.show_available_plugins',
        'sylius.console.command.debug_currency' => 'sylius_currency.console.command.debug_currency',
        'sylius.mailer.shipment_email_manager' => 'sylius_core.mailer.shipment_email_manager',
        'sylius.mailer.order_email_manager' => 'sylius_core.mailer.order_email_manager',
        'sylius.mailer.contact_email_manager' => 'sylius_core.mailer.contact_email_manager',
    ];

    /** Mapping des préfixes de services renommés */
    private const PREFIX_MAPPING = [
        'sylius.controller.admin.' => 'sylius_admin.controller.',
        'sylius.controller.shop.' => 'sylius_shop.controller.',
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

        /* Remplacement des IDs exacts */
        foreach (self::SERVICE_ID_MAPPING as $oldId => $newId) {
            $fixedContent = str_replace($oldId, $newId, $fixedContent);
        }

        /* Remplacement des préfixes */
        foreach (self::PREFIX_MAPPING as $oldPrefix => $newPrefix) {
            $fixedContent = str_replace($oldPrefix, $newPrefix, $fixedContent);
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
