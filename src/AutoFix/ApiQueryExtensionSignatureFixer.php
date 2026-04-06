<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Met a jour les signatures des QueryExtensions pour API Platform 3.x.
 * Remplace le parametre $operationName par Operation $operation dans les methodes.
 */
final class ApiQueryExtensionSignatureFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'API Query Extension Signature';

    public function getName(): string
    {
        return 'API Query Extension Signature Fixer';
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

        /* Remplacement du parametre string $operationName par Operation $operation */
        $fixedContent = (string) preg_replace(
            '/string\s+\$operationName/',
            'Operation $operation',
            $fixedContent,
        );

        /* Ajout du use statement si manquant */
        if (str_contains($fixedContent, 'Operation $operation')
            && !str_contains($fixedContent, 'use ApiPlatform\\Metadata\\Operation')
        ) {
            $fixedContent = (string) preg_replace(
                '/(namespace\s+[^;]+;\s*\n)/',
                "$1\nuse ApiPlatform\\Metadata\\Operation;\n",
                $fixedContent,
                1,
            );
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
                'Mise a jour de la signature QueryExtension dans %s.',
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
