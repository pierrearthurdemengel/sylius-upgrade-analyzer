<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Ajoute l'attribut de priorite aux OrderProcessors qui n'en ont pas.
 * Sylius 2.0 requiert des priorites explicites dans la plage 40-60.
 */
final class OrderProcessorPriorityFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Order Processor Priority';

    public function getName(): string
    {
        return 'Order Processor Priority Fixer';
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

        return (bool) preg_match('/\.(php|yaml|yml)$/i', $file);
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
                'Ajout de la priorite explicite pour l\'OrderProcessor dans %s.',
                basename($absolutePath),
            ),
        );
    }

    /**
     * Ajoute un tag priority dans les services YAML.
     */
    private function fixYamlFile(string $content): string
    {
        /* Ajout d'une priorite au tag sylius.order_processor si absente */
        $content = (string) preg_replace(
            '/(sylius\.order_processor)(\s*\n\s*(?!priority))/m',
            "$1\n            priority: 0$2",
            $content,
        );

        return $content;
    }

    /**
     * Ajoute le getOrder() / getPriority() dans les fichiers PHP si absent.
     */
    private function fixPhpFile(string $content): string
    {
        if (str_contains($content, 'OrderProcessorInterface')
            && !str_contains($content, 'public static function getPriority')
            && !str_contains($content, 'getOrder()')
        ) {
            $priorityMethod = "\n    /**\n     * Priorite de traitement de la commande.\n     */\n"
                . "    public static function getPriority(): int\n    {\n        return 0;\n    }\n";

            $content = (string) preg_replace(
                '/(\n}\s*)$/',
                $priorityMethod . '$1',
                $content,
            );
        }

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
