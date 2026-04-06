<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Corrige le fichier config/bundles.php en supprimant les bundles obsoletes
 * et en ajoutant les bundles manquants pour Sylius 2.0.
 */
final class BundleConfigFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Bundle Configuration';

    /** Bundles a supprimer de config/bundles.php */
    private const BUNDLES_TO_REMOVE = [
        'Sylius\\Calendar\\SyliusCalendarBundle',
        'winzou\\Bundle\\StateMachineBundle\\winzouStateMachineBundle',
        'Bazinga\\Bundle\\HateoasBundle\\BazingaHateoasBundle',
        'JMS\\SerializerBundle\\JMSSerializerBundle',
        'FOS\\RestBundle\\FOSRestBundle',
        'ApiPlatform\\Core\\Bridge\\Symfony\\Bundle\\ApiPlatformBundle',
        'SyliusLabs\\Polyfill\\Symfony\\Security\\Bundle\\SyliusLabsPolyfillSymfonySecurityBundle',
    ];

    /** Bundles a ajouter avec leur environnement */
    private const BUNDLES_TO_ADD = [
        'Sylius\\TwigHooks\\SyliusTwigHooksBundle' => "['all' => true]",
        'Symfony\\UX\\TwigComponent\\TwigComponentBundle' => "['all' => true]",
        'Symfony\\UX\\StimulusBundle\\StimulusBundle' => "['all' => true]",
        'Symfony\\UX\\LiveComponent\\LiveComponentBundle' => "['all' => true]",
        'Symfony\\UX\\Autocomplete\\AutocompleteBundle' => "['all' => true]",
    ];

    public function getName(): string
    {
        return 'Bundle Configuration Fixer';
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

        return str_contains($file, 'bundles.php');
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

        /* Suppression des bundles obsoletes */
        foreach (self::BUNDLES_TO_REMOVE as $bundleClass) {
            $escapedClass = preg_quote($bundleClass, '/');
            $fixedContent = (string) preg_replace(
                '/^\s*' . $escapedClass . '::class\s*=>\s*\[.*?\],?\s*\n/m',
                '',
                $fixedContent,
            );
        }

        /* Ajout des bundles manquants avant le ]; de fermeture */
        foreach (self::BUNDLES_TO_ADD as $bundleClass => $envConfig) {
            $escapedClass = preg_quote($bundleClass, '/');
            if (preg_match('/' . $escapedClass . '/', $fixedContent) === 1) {
                continue;
            }

            $fixedContent = (string) preg_replace(
                '/^(\s*)\];/m',
                sprintf("$1    %s::class => %s,\n$1];", $bundleClass, $envConfig),
                $fixedContent,
                1,
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
            description: 'Mise a jour de config/bundles.php : bundles obsoletes supprimes, bundles requis ajoutes.',
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
