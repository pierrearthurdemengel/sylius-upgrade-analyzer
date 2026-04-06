<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace les appels à sonata_block_render_event() et sylius_template_event()
 * par les équivalents hook() de Twig Hooks dans les templates Twig.
 */
final class SonataBlockEventFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Sonata Block Event';

    public function getName(): string
    {
        return 'Sonata Block Event Fixer';
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

        /* Supporte les templates Twig */
        return (bool) preg_match('/\.twig$/i', $file);
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

        /* Remplacement de sonata_block_render_event('event.name') par {{ hook('event.name') }} */
        $fixedContent = (string) preg_replace(
            '/\{\{\s*sonata_block_render_event\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*\{[^}]*\})?\s*\)\s*\}\}/',
            "{{ hook('$1') }}",
            $fixedContent,
        );

        /* Remplacement de sylius_template_event('event.name') */
        $fixedContent = (string) preg_replace(
            '/\{\{\s*sylius_template_event\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*\{[^}]*\})?\s*\)\s*\}\}/',
            "{{ hook('$1') }}",
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
                'Remplacement de sonata_block_render_event() / sylius_template_event() '
                . 'par hook() dans %s. Vérifier les noms d\'événements et les paramètres.',
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
