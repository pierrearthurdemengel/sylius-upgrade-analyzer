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
            $fixedContent = $this->removePackageLine($fixedContent, $package);
        }

        /* Un composer.json invalide bloquerait toute commande Composer : dans le doute, aucun correctif */
        if ($fixedContent === $originalContent || json_decode($fixedContent) === null) {
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

    /**
     * Supprime la ligne "vendor/package": "^x.y" en conservant un JSON valide : quand le paquet
     * etait la derniere entree de son objet, la virgule de l'entree precedente est retiree.
     */
    private function removePackageLine(string $content, string $package): string
    {
        $pattern = '/^[ \t]*"' . preg_quote($package, '/') . '"[ \t]*:[ \t]*"[^"]*"[ \t]*(,?)[ \t]*\r?\n/m';

        if (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $content;
        }

        $offset = $match[0][1];
        $before = substr($content, 0, $offset);
        $after = substr($content, $offset + \strlen($match[0][0]));

        if ($match[1][0] === '') {
            $before = (string) preg_replace('/,([ \t]*\r?\n)$/', '$1', $before);
        }

        return $before . $after;
    }

    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
