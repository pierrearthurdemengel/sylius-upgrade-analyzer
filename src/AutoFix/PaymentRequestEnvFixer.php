<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Ajoute les variables d'environnement manquantes pour le transport Payment Request
 * dans les fichiers .env du projet.
 */
final class PaymentRequestEnvFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Payment Request Env';

    /** Variables d'environnement a ajouter */
    private const ENV_VARS = [
        'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN' => 'doctrine://default',
        'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN' => 'doctrine://default?queue_name=payment_request_failed',
    ];

    public function getName(): string
    {
        return 'Payment Request Env Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        /* Ce fixer supporte les fichiers .env et messenger.yaml */
        $file = $issue->getFile();

        return $file !== null;
    }

    public function fix(MigrationIssue $issue, string $projectPath): ?MigrationFix
    {
        /* On cible le fichier .env principal */
        $envPath = rtrim($projectPath, '/') . '/.env';
        if (!file_exists($envPath)) {
            return null;
        }

        $originalContent = (string) file_get_contents($envPath);
        $fixedContent = $originalContent;

        $linesToAdd = [];
        foreach (self::ENV_VARS as $varName => $defaultValue) {
            if (!str_contains($fixedContent, $varName)) {
                $linesToAdd[] = sprintf('%s=%s', $varName, $defaultValue);
            }
        }

        if ($linesToAdd === []) {
            return null;
        }

        $fixedContent = rtrim($fixedContent) . "\n\n###> sylius/payment-request ###\n"
            . implode("\n", $linesToAdd) . "\n###< sylius/payment-request ###\n";

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $envPath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: 'Ajout des variables d\'environnement Payment Request dans .env.',
        );
    }
}
