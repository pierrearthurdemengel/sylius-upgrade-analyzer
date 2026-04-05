<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Fixer pour le renommage des handlers de commandes.
 * Suggere le renommage du repertoire src/Message/ vers src/Command/
 * et met a jour les namespaces correspondants dans les fichiers PHP.
 */
final class CommandHandlerFixer implements AutoFixInterface
{
    /** Nom de l'analyseur cible */
    private const TARGET_ANALYZER = 'Command Handler Rename';

    public function getName(): string
    {
        return 'Command Handler Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        return $issue->getAnalyzer() === self::TARGET_ANALYZER;
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

        /* Detection du namespace racine du projet */
        $rootNamespace = $this->detectRootNamespace($projectPath);
        if ($rootNamespace === null) {
            return null;
        }

        $oldNamespace = $rootNamespace . 'Message';
        $newNamespace = $rootNamespace . 'Command';

        /* Remplacement des namespaces dans le fichier */
        $fixedContent = $this->replaceNamespaces($originalContent, $oldNamespace, $newNamespace);

        /* Si aucune modification n'a ete faite */
        if ($fixedContent === $originalContent) {
            return null;
        }

        /* Calcul du nouveau chemin du fichier */
        $newFilePath = $this->computeNewFilePath($absolutePath);

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $newFilePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Renommage du namespace %s vers %s dans %s. '
                . 'Le fichier doit etre deplace de src/Message/ vers src/Command/.',
                $oldNamespace,
                $newNamespace,
                basename($absolutePath),
            ),
        );
    }

    /**
     * Detecte le namespace racine du projet a partir de composer.json.
     * Cherche dans la section autoload.psr-4 le namespace correspondant a src/.
     */
    private function detectRootNamespace(string $projectPath): ?string
    {
        $composerJsonPath = $projectPath . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return null;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);
        if (!is_array($composerData)) {
            return null;
        }

        /* Recherche dans autoload.psr-4 */
        $psr4 = $composerData['autoload']['psr-4'] ?? [];
        if (!is_array($psr4)) {
            return null;
        }

        foreach ($psr4 as $namespace => $path) {
            $normalizedPath = rtrim((string) $path, '/');
            if ($normalizedPath === 'src' || $normalizedPath === 'src/') {
                /* Le namespace racine doit se terminer par un backslash */
                return rtrim($namespace, '\\') . '\\';
            }
        }

        return null;
    }

    /**
     * Remplace les occurrences de l'ancien namespace par le nouveau.
     * Gere les declarations de namespace, les imports use, et les references FQCN.
     */
    private function replaceNamespaces(string $content, string $oldNamespace, string $newNamespace): string
    {
        /* Remplacement de la declaration de namespace */
        $content = str_replace(
            'namespace ' . $oldNamespace,
            'namespace ' . $newNamespace,
            $content,
        );

        /* Remplacement des imports use */
        $content = str_replace(
            'use ' . $oldNamespace,
            'use ' . $newNamespace,
            $content,
        );

        /* Remplacement des sous-namespaces (ex: Message\Handler → Command\Handler) */
        $content = str_replace(
            $oldNamespace . '\\',
            $newNamespace . '\\',
            $content,
        );

        /* Remplacement des references dans les chaines (services YAML, annotations, etc.) */
        $escapedOld = str_replace('\\', '\\\\', $oldNamespace);
        $escapedNew = str_replace('\\', '\\\\', $newNamespace);
        $content = str_replace($escapedOld, $escapedNew, $content);

        return $content;
    }

    /**
     * Calcule le nouveau chemin du fichier apres renommage du repertoire.
     * Remplace src/Message/ par src/Command/ dans le chemin.
     */
    private function computeNewFilePath(string $filePath): string
    {
        $normalized = str_replace('\\', '/', $filePath);

        /* Remplacement du repertoire dans le chemin */
        $pattern = '/\/src\/Message\//';
        $replacement = '/src/Command/';

        $newPath = (string) preg_replace($pattern, $replacement, $normalized);

        /* Gestion des chemins Windows */
        if (DIRECTORY_SEPARATOR === '\\') {
            $newPath = str_replace('/', '\\', $newPath);
        }

        return $newPath;
    }

    /**
     * Resout le chemin absolu d'un fichier.
     */
    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
