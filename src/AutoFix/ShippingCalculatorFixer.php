<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\ShippingCalculatorAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Renomme les references aux services de calcul des frais de port du coeur.
 *
 * Seules les references ("@id", decorates, alias) sont modifiees : le tag
 * sylius.shipping_calculator garde son nom en Sylius 2.0 et ne doit pas etre touche.
 */
final class ShippingCalculatorFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Shipping Calculator';

    public function getName(): string
    {
        return 'Shipping Calculator Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        $file = $issue->getFile();

        return $file !== null && (bool) preg_match('/\.(yaml|yml)$/i', $file);
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

        foreach (ShippingCalculatorAnalyzer::RENAMED_SERVICES as $oldId => $newId) {
            $fixedContent = (string) preg_replace_callback(
                sprintf(ShippingCalculatorAnalyzer::REFERENCE_PATTERN, preg_quote($oldId, '/')),
                static fn (array $match): string => str_replace($oldId, $newId, $match[0]),
                $fixedContent,
            );
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
                'Renommage des services de calcul des frais de port dans %s.',
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
