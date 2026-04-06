<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Commente les references aux contextes Behat deprecies dans la configuration.
 * Les contextes supprimes dans Sylius 2.0 sont commentes avec un TODO.
 */
final class BehatContextFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Behat Context Deprecation';

    /** Mapping des anciens contextes Calendar vers Clock */
    private const CONTEXT_MAPPING = [
        'sylius.behat.context.hook.calendar' => 'sylius.behat.context.hook.clock',
        'sylius.behat.context.setup.calendar' => 'sylius.behat.context.setup.clock',
        'sylius.behat.context.transform.calendar' => 'sylius.behat.context.transform.clock',
        'Sylius\\Behat\\Context\\Hook\\CalendarContext' => 'Sylius\\Behat\\Context\\Hook\\ClockContext',
        'Sylius\\Behat\\Context\\Setup\\CalendarContext' => 'Sylius\\Behat\\Context\\Setup\\ClockContext',
        'Sylius\\Behat\\Context\\Transform\\CalendarContext' => 'Sylius\\Behat\\Context\\Transform\\ClockContext',
    ];

    public function getName(): string
    {
        return 'Behat Context Fixer';
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
        if ($filePath === null) {
            return null;
        }

        $absolutePath = $this->resolveAbsolutePath($filePath, $projectPath);
        if (!file_exists($absolutePath)) {
            return null;
        }

        $originalContent = (string) file_get_contents($absolutePath);
        $fixedContent = $originalContent;

        /* Remplacement des contextes Calendar vers Clock */
        foreach (self::CONTEXT_MAPPING as $old => $new) {
            $fixedContent = str_replace($old, $new, $fixedContent);
        }

        /* Commentaire des contextes sans remplacement (DoctrineORM) */
        $fixedContent = (string) preg_replace(
            '/^(\s*-\s*)(sylius\.behat\.context\.hook\.doctrine_orm)/m',
            '$1# TODO: contexte supprime dans Sylius 2.0 — $2',
            $fixedContent,
        );
        $fixedContent = (string) preg_replace(
            '/^(\s*-\s*)(Sylius\\\\Behat\\\\Context\\\\Hook\\\\DoctrineORMContext)/m',
            '$1# TODO: contexte supprime dans Sylius 2.0 — $2',
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
                'Mise a jour des contextes Behat deprecies dans %s.',
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
