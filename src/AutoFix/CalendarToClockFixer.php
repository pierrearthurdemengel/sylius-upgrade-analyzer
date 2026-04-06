<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace les references a sylius/calendar par symfony/clock.
 * DateTimeProviderInterface est remplace par ClockInterface dans Sylius 2.0.
 */
final class CalendarToClockFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Calendar to Clock';

    /** Mapping des classes Calendar vers Clock */
    private const CLASS_MAPPING = [
        'Sylius\\Calendar\\Provider\\DateTimeProviderInterface' => 'Symfony\\Component\\Clock\\ClockInterface',
        'Sylius\\Calendar\\Provider\\DateTimeProvider' => 'Symfony\\Component\\Clock\\NativeClock',
    ];

    public function getName(): string
    {
        return 'Calendar to Clock Fixer';
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

        return (bool) preg_match('/\.(php|yaml|yml|json)$/i', $file);
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
            'php' => $this->fixPhpFile($originalContent),
            'json' => $this->fixComposerJson($originalContent),
            default => $originalContent,
        };

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Remplacement des references sylius/calendar par symfony/clock dans %s.',
                basename($absolutePath),
            ),
        );
    }

    /**
     * Remplace les use statements et references dans les fichiers PHP.
     */
    private function fixPhpFile(string $content): string
    {
        foreach (self::CLASS_MAPPING as $old => $new) {
            $content = str_replace($old, $new, $content);
        }

        return $content;
    }

    /**
     * Remplace sylius/calendar par symfony/clock dans composer.json.
     */
    private function fixComposerJson(string $content): string
    {
        $content = str_replace('"sylius/calendar"', '"symfony/clock"', $content);

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
