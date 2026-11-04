<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\CommandHandlerRenameAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace les espaces de noms Message, MessageHandler et MessageDispatcher d'AdminBundle et
 * CoreBundle par Command, CommandHandler et CommandDispatcher dans les fichiers du projet.
 *
 * Les classes concernees gardent leur nom court en Sylius 2.0 : le remplacement de l'espace
 * de noms suffit. Le code du projet lui-meme n'est ni renomme ni deplace.
 */
final class CommandHandlerFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Command Handler Rename';

    public function getName(): string
    {
        return 'Command Handler Rename Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        return $issue->getAnalyzer() === self::TARGET_ANALYZER
            && $issue->getFile() !== null
            && str_ends_with(strtolower($issue->getFile()), '.php');
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

        foreach (CommandHandlerRenameAnalyzer::RENAMED_NAMESPACES as $old => $new) {
            /* Forme litterale du code (un antislash) et forme echappee des chaines YAML ou PHP */
            $fixedContent = str_replace($old, $new, $fixedContent);
            $fixedContent = str_replace(str_replace('\\', '\\\\', $old), str_replace('\\', '\\\\', $new), $fixedContent);
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
                'Remplacement des espaces de noms Message* de Sylius par Command* dans %s.',
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
