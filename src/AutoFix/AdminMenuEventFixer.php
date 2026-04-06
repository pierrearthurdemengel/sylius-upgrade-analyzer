<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Commente les event listeners de menu admin deprecies.
 * Le systeme d'evenements de menu admin a change dans Sylius 2.0.
 */
final class AdminMenuEventFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Admin Menu Event';

    /** Evenements de menu a remplacer */
    private const EVENT_MAPPING = [
        'sylius.menu.admin.main' => 'sylius_admin.menu.main',
        'sylius.menu.admin.customer.show' => 'sylius_admin.menu.customer.show',
        'sylius.menu.admin.order.show' => 'sylius_admin.menu.order.show',
    ];

    public function getName(): string
    {
        return 'Admin Menu Event Fixer';
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

        return (bool) preg_match('/\.(php|yaml|yml)$/i', $file);
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

        foreach (self::EVENT_MAPPING as $old => $new) {
            $fixedContent = str_replace($old, $new, $fixedContent);
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
                'Mise a jour des evenements de menu admin dans %s.',
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
