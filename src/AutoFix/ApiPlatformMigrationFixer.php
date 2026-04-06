<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Met a jour les references API Platform de l'ancien namespace Core vers le nouveau.
 * Remplace ApiPlatform\Core par les nouveaux namespaces API Platform 3.x.
 */
final class ApiPlatformMigrationFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'API Platform Migration';

    /** Mapping des anciens namespaces vers les nouveaux */
    private const NAMESPACE_MAPPING = [
        'ApiPlatform\\Core\\Annotation\\ApiResource' => 'ApiPlatform\\Metadata\\ApiResource',
        'ApiPlatform\\Core\\Annotation\\ApiFilter' => 'ApiPlatform\\Metadata\\ApiFilter',
        'ApiPlatform\\Core\\Annotation\\ApiProperty' => 'ApiPlatform\\Metadata\\ApiProperty',
        'ApiPlatform\\Core\\Annotation\\ApiSubresource' => 'ApiPlatform\\Metadata\\ApiProperty',
        'ApiPlatform\\Core\\Bridge\\Symfony\\Bundle\\ApiPlatformBundle' => 'ApiPlatform\\Symfony\\Bundle\\ApiPlatformBundle',
        'ApiPlatform\\Core\\DataProvider\\' => 'ApiPlatform\\State\\ProviderInterface',
        'ApiPlatform\\Core\\DataPersister\\' => 'ApiPlatform\\State\\ProcessorInterface',
    ];

    public function getName(): string
    {
        return 'API Platform Migration Fixer';
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

        foreach (self::NAMESPACE_MAPPING as $old => $new) {
            $fixedContent = str_replace($old, $new, $fixedContent);
        }

        /* Remplacement des annotations @ApiResource par l'attribut #[ApiResource] */
        $fixedContent = (string) preg_replace(
            '/@ApiResource\s*\(/',
            '#[ApiResource(',
            $fixedContent,
        );

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::MEDIUM,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Migration des references API Platform vers 3.x dans %s.',
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
