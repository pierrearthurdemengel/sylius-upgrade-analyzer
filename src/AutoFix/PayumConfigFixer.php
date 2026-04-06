<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Commente les configurations Payum obsoletes dans les fichiers YAML.
 * Payum est remplace par le systeme Payment Requests dans Sylius 2.0.
 */
final class PayumConfigFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Payum';

    public function getName(): string
    {
        return 'Payum Config Fixer';
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

        return (bool) preg_match('/\.(yaml|yml|php)$/i', $file);
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
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $fixedContent = match ($extension) {
            'yaml', 'yml' => $this->fixYamlFile($originalContent),
            'php' => $this->fixPhpFile($originalContent),
            default => $originalContent,
        };

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::MEDIUM,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Commentaire des references Payum dans %s.',
                basename($absolutePath),
            ),
        );
    }

    /**
     * Commente le bloc payum_bundle dans les fichiers YAML.
     */
    private function fixYamlFile(string $content): string
    {
        /* Commentaire de la cle racine payum: */
        $content = (string) preg_replace(
            '/^(payum:)/m',
            '# TODO: Payum est remplace par Payment Requests dans Sylius 2.0' . "\n" . '# $1',
            $content,
        );

        return $content;
    }

    /**
     * Commente les use statements Payum dans les fichiers PHP.
     */
    private function fixPhpFile(string $content): string
    {
        /* Remplacement des use statements Payum par un commentaire */
        $content = (string) preg_replace(
            '/^(use\s+Payum\\\\[^;]+;)$/m',
            '// TODO: Payum supprime dans Sylius 2.0 — $1',
            $content,
        );

        return $content;
    }

    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
