<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Commente les references aux routes supprimees dans Sylius 2.0.
 * Les routes supprimees n'ont pas de remplacement 1:1 — la ligne est commentee
 * avec un TODO pour le developpeur.
 */
final class RemovedRouteFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Removed Route';

    public function getName(): string
    {
        return 'Removed Route Fixer';
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

        return (bool) preg_match('/\.(php|twig)$/i', $file);
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
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $targetIndex = $line - 1;
        if (!isset($lines[$targetIndex])) {
            return null;
        }

        $commentPrefix = $extension === 'php' ? '// ' : '{# ';
        $commentSuffix = $extension === 'php' ? '' : ' #}';
        $lines[$targetIndex] = $commentPrefix . 'TODO: route supprimee dans Sylius 2.0 — '
            . trim($lines[$targetIndex]) . $commentSuffix;

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
                'Commentaire de la reference a une route supprimee dans %s ligne %d.',
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
