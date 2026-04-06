<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Supprime les paquets deprecies du composer.json.
 * Les paquets retires de Sylius 2.0 sont supprimes de la section require.
 */
final class DeprecatedBundlePackageFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Deprecated Bundle Package';

    /** Paquets a supprimer de composer.json */
    private const DEPRECATED_PACKAGES = [
        'friendsofsymfony/rest-bundle',
        'jms/serializer-bundle',
        'willdurand/hateoas-bundle',
        'bazinga/hateoas-bundle',
        'sylius/calendar',
        'sylius-labs/polyfill-symfony-security',
    ];

    public function getName(): string
    {
        return 'Deprecated Bundle Package Fixer';
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

        return str_contains($file, 'composer.json');
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

        foreach (self::DEPRECATED_PACKAGES as $package) {
            /* Suppression de la ligne "vendor/package": "^x.y" avec la virgule eventuelle */
            $pattern = '/\s*"' . preg_quote($package, '/') . '"\s*:\s*"[^"]*"\s*,?\s*\n/';
            $fixedContent = (string) preg_replace($pattern, "\n", $fixedContent);
        }

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: 'Suppression des paquets deprecies du composer.json.',
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
