<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Commente les instructions use de classes supprimees dans Sylius 2.0.
 * Les classes supprimees n'ont pas de remplacement direct — le use est commente
 * avec une indication pour le developpeur.
 */
final class RemovedClassFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Removed Class';

    public function getName(): string
    {
        return 'Removed Class Fixer';
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
        $line = $issue->getLine();
        if ($filePath === null || $line === null) {
            return null;
        }

        $absolutePath = $this->resolveAbsolutePath($filePath, $projectPath);
        if (!file_exists($absolutePath)) {
            return null;
        }

        $originalContent = (string) file_get_contents($absolutePath);
        $lines = explode("\n", $originalContent);

        /* Verification que la ligne cible est bien un use statement */
        $targetIndex = $line - 1;
        if (!isset($lines[$targetIndex]) || !str_contains($lines[$targetIndex], 'use ')) {
            return null;
        }

        /* Commentaire du use statement avec indication */
        $lines[$targetIndex] = '// TODO: classe supprimee dans Sylius 2.0 — ' . trim($lines[$targetIndex]);

        $fixedContent = implode("\n", $lines);
        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::MEDIUM,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Commentaire du use statement de classe supprimee dans %s ligne %d.',
                basename($absolutePath),
                $line,
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
