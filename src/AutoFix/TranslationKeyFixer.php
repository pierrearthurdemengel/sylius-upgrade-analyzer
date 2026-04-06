<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace les prefixes de cles de traduction renommes dans Sylius 2.0.
 * Applicable aux fichiers YAML de traduction et aux templates Twig.
 */
final class TranslationKeyFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Translation Key';

    /** Mapping des anciens prefixes vers les nouveaux */
    private const PREFIX_MAPPING = [
        'sylius.ui.admin' => 'sylius.admin',
        'sylius.ui.shop' => 'sylius.shop',
        'sylius.form.channel' => 'sylius.admin.channel',
        'sylius.form.product' => 'sylius.admin.product',
        'sylius.email' => 'sylius.notification',
    ];

    public function getName(): string
    {
        return 'Translation Key Fixer';
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

        return (bool) preg_match('/\.(yaml|yml|twig|xlf|xliff)$/i', $file);
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

        foreach (self::PREFIX_MAPPING as $oldPrefix => $newPrefix) {
            $fixedContent = str_replace($oldPrefix, $newPrefix, $fixedContent);
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
                'Mise a jour des prefixes de cles de traduction dans %s.',
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
