<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Commente les configurations de passerelles de paiement supprimees dans Sylius 2.0.
 * Stripe et PayPal Express sont retires du coeur et doivent utiliser des plugins.
 */
final class RemovedPaymentGatewayFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Removed Payment Gateway';

    /** Noms de gateway a commenter */
    private const REMOVED_GATEWAYS = [
        'stripe_checkout',
        'paypal_express_checkout',
    ];

    public function getName(): string
    {
        return 'Removed Payment Gateway Fixer';
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

        /* Configuration YAML uniquement : aucun correctif ne touche au code PHP de paiement */
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

        foreach (self::REMOVED_GATEWAYS as $gateway) {
            /* Toute la definition de la passerelle est commentee, pas seulement sa cle */
            $fixedContent = YamlBlockCommenter::comment(
                $fixedContent,
                preg_quote($gateway, '/'),
                'passerelle supprimee du coeur dans Sylius 2.0, a remplacer par un plugin de paiement',
            );
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
                'Commentaire des passerelles de paiement supprimees dans %s.',
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
