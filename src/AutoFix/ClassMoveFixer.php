<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace les anciens FQCN de classes deplacees par les nouveaux dans les fichiers PHP.
 * Les 14 classes deplacees entre bundles dans Sylius 2.0 sont corrigees automatiquement.
 */
final class ClassMoveFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Class Move';

    /** @var array<string, string> Mapping des anciens FQCN vers les nouveaux */
    private const CLASS_MOVES = [
        'Sylius\\Bundle\\ShopBundle\\EmailManager\\ContactEmailManager' => 'Sylius\\Bundle\\CoreBundle\\Mailer\\ContactEmailManager',
        'Sylius\\Bundle\\AdminBundle\\EmailManager\\ShipmentEmailManager' => 'Sylius\\Bundle\\CoreBundle\\Mailer\\ShipmentEmailManager',
        'Sylius\\Bundle\\AdminBundle\\EmailManager\\ShipmentEmailManagerInterface' => 'Sylius\\Bundle\\CoreBundle\\Mailer\\ShipmentEmailManagerInterface',
        'Sylius\\Bundle\\CoreBundle\\Theme\\ChannelBasedThemeContext' => 'Sylius\\Bundle\\ShopBundle\\Theme\\ChannelBasedThemeContext',
        'Sylius\\Component\\Promotion\\Checker\\Rule\\ItemTotalRuleChecker' => 'Sylius\\Component\\Core\\Promotion\\Checker\\Rule\\ItemTotalRuleChecker',
        'Sylius\\Bundle\\PayumBundle\\Validator\\GatewayFactoryExistsValidator' => 'Sylius\\Bundle\\PaymentBundle\\Validator\\Constraints\\GatewayFactoryExistsValidator',
        'Sylius\\Bundle\\PayumBundle\\Validator\\GroupsGenerator\\GatewayConfigGroupsGenerator' => 'Sylius\\Bundle\\PaymentBundle\\Validator\\Constraints\\GatewayConfigGroupsGenerator',
        'Sylius\\Bundle\\UiBundle\\Storage\\FilterStorageInterface' => 'Sylius\\Bundle\\GridBundle\\Storage\\FilterStorageInterface',
        'Sylius\\Bundle\\UiBundle\\Storage\\FilterStorage' => 'Sylius\\Bundle\\GridBundle\\Storage\\FilterStorage',
    ];

    public function getName(): string
    {
        return 'Class Move Fixer';
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

        foreach (self::CLASS_MOVES as $oldFqcn => $newFqcn) {
            $fixedContent = str_replace($oldFqcn, $newFqcn, $fixedContent);
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
                'Remplacement des FQCN de classes deplacees dans %s.',
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
