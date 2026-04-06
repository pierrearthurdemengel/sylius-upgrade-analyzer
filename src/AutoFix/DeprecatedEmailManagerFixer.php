<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace les references aux EmailManagers deprecies dans les fichiers PHP.
 * OrderEmailManager et ContactEmailManager sont deplaces dans CoreBundle.
 */
final class DeprecatedEmailManagerFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Deprecated Email Manager';

    /** Mapping des anciennes interfaces vers les nouvelles */
    private const INTERFACE_MAPPING = [
        'Sylius\\Bundle\\ShopBundle\\EmailManager\\OrderEmailManagerInterface' => 'Sylius\\Bundle\\CoreBundle\\Mailer\\OrderEmailManagerInterface',
        'Sylius\\Bundle\\ShopBundle\\EmailManager\\ContactEmailManagerInterface' => 'Sylius\\Bundle\\CoreBundle\\Mailer\\ContactEmailManagerInterface',
        'Sylius\\Bundle\\AdminBundle\\EmailManager\\OrderEmailManagerInterface' => 'Sylius\\Bundle\\CoreBundle\\Mailer\\OrderEmailManagerInterface',
        'Sylius\\Bundle\\AdminBundle\\EmailManager\\ShipmentEmailManagerInterface' => 'Sylius\\Bundle\\CoreBundle\\Mailer\\ShipmentEmailManagerInterface',
    ];

    public function getName(): string
    {
        return 'Deprecated Email Manager Fixer';
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

        return (bool) preg_match('/\.php$/i', $file);
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

        foreach (self::INTERFACE_MAPPING as $old => $new) {
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
                'Mise a jour des references EmailManager dans %s.',
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
