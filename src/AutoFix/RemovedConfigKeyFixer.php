<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Commente les cles de configuration supprimees dans les fichiers YAML.
 * Les cles obsoletes de Sylius 2.0 sont commentees avec un TODO.
 */
final class RemovedConfigKeyFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Removed Config Key';

    /** Tokens des cles a supprimer (dernier segment de la cle complete) */
    private const CONFIG_KEY_TOKENS = [
        'autoconfigure_with_attributes',
        'state_machine',
        'legacy_error_handling',
        'skip_adding_read_group',
        'skip_adding_index_and_show_groups',
        'mongodb_odm',
        'phpcr_odm',
    ];

    public function getName(): string
    {
        return 'Removed Config Key Fixer';
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

        return (bool) preg_match('/\.(yaml|yml)$/i', $file);
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

        $targetIndex = $line - 1;
        if (!isset($lines[$targetIndex])) {
            return null;
        }

        /* Verification que la ligne contient bien un des tokens */
        $found = false;
        foreach (self::CONFIG_KEY_TOKENS as $token) {
            if (str_contains($lines[$targetIndex], $token)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }

        /* Commentaire de la ligne YAML */
        $lines[$targetIndex] = '# TODO: cle supprimee dans Sylius 2.0 — ' . trim($lines[$targetIndex]);

        $fixedContent = implode("\n", $lines);
        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Commentaire de la cle de configuration supprimee dans %s ligne %d.',
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
