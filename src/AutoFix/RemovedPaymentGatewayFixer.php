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

        return (bool) preg_match('/\.(yaml|yml|php)$/i', $file);
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
            /* Commentaire des lignes contenant la gateway dans les YAML */
            $fixedContent = (string) preg_replace(
                '/^(\s*)(' . preg_quote($gateway, '/') . ')/m',
                '$1# TODO: gateway supprimee dans Sylius 2.0 — $2',
                $fixedContent,
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
