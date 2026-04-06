<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace les anciens chemins d'endpoints API par les nouveaux dans Sylius 2.0.
 * Seuls les endpoints avec un remplacement connu sont corriges automatiquement.
 */
final class ApiEndpointRestructureFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'API Endpoint Restructure';

    /** Mapping des anciens endpoints vers les nouveaux (hors endpoints supprimes) */
    private const ENDPOINT_MAPPING = [
        '/api/v2/admin/avatar-images/' => '/api/v2/admin/administrators/{id}/avatar-image',
        '/api/v2/shop/reset-password-requests' => '/api/v2/shop/reset-password',
        '/api/v2/shop/account-verification-requests' => '/api/v2/shop/verify-shop-user',
    ];

    public function getName(): string
    {
        return 'API Endpoint Restructure Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        $file = $issue->getFile();

        return $file !== null;
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

        foreach (self::ENDPOINT_MAPPING as $oldEndpoint => $newEndpoint) {
            $fixedContent = str_replace($oldEndpoint, $newEndpoint, $fixedContent);
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
                'Mise a jour des chemins d\'endpoints API dans %s.',
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
