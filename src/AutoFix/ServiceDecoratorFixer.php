<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Met a jour les decorateurs de services qui ciblent des services Sylius renommes.
 * Remplace les anciens identifiants de services dans les configurations de decoration.
 */
final class ServiceDecoratorFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Service Decorator';

    /** Mapping des anciens services decores vers les nouveaux */
    private const DECORATOR_MAPPING = [
        'sylius.controller.admin.' => 'sylius_admin.controller.',
        'sylius.controller.shop.' => 'sylius_shop.controller.',
        'sylius.repository.' => 'sylius.repository.',
        'sylius.factory.' => 'sylius.factory.',
    ];

    public function getName(): string
    {
        return 'Service Decorator Fixer';
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

        /* Remplacement des prefixes de services dans les blocs decorates */
        foreach (self::DECORATOR_MAPPING as $old => $new) {
            if ($old !== $new) {
                $fixedContent = str_replace(
                    "decorates: '" . $old,
                    "decorates: '" . $new,
                    $fixedContent,
                );
                $fixedContent = str_replace(
                    'decorates: ' . $old,
                    'decorates: ' . $new,
                    $fixedContent,
                );
            }
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
                'Mise a jour des decorateurs de services dans %s.',
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
