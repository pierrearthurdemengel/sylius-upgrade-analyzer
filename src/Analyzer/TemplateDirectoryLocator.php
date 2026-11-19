<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Localise les répertoires contenant les templates Twig d'un projet Sylius.
 *
 * Sur une boutique Sylius 1.x, l'essentiel des surcharges du shop vit souvent
 * dans les thèmes SyliusThemeBundle (themes/<Theme>/...) plutôt que dans templates/.
 * Les analyseurs qui lisent les templates passent par cette classe pour ne pas
 * ignorer ces thèmes.
 */
final class TemplateDirectoryLocator
{
    /** Répertoire de thèmes par défaut de SyliusThemeBundle */
    private const DEFAULT_THEMES_DIRECTORY = 'themes';

    /**
     * Répertoires exclus du parcours des thèmes : un thème peut embarquer
     * ses propres dépendances front ou PHP, qui ne sont pas du code du projet.
     *
     * @var list<string>
     */
    public const EXCLUDED_DIRECTORIES = ['node_modules', 'vendor'];

    /**
     * Retourne templates/ (s'il existe) suivi des répertoires racines de thèmes.
     *
     * @return list<string> Chemins absolus, sans doublon
     */
    public static function locate(string $projectPath): array
    {
        $directories = [];

        $templatesDir = $projectPath . '/templates';
        if (is_dir($templatesDir)) {
            $directories[] = $templatesDir;
        }

        return self::deduplicate([...$directories, ...self::locateThemeRoots($projectPath)]);
    }

    /**
     * Retourne les répertoires racines de thèmes : themes/ par défaut, plus ceux
     * déclarés dans sylius_theme.sources.filesystem.directories.
     *
     * @return list<string> Chemins absolus, sans doublon
     */
    public static function locateThemeRoots(string $projectPath): array
    {
        $candidates = [$projectPath . '/' . self::DEFAULT_THEMES_DIRECTORY];

        foreach (self::readConfiguredThemeDirectories($projectPath) as $configured) {
            $candidates[] = $configured;
        }

        $roots = [];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }

        return self::deduplicate($roots);
    }

    /**
     * Crée un Finder sur tous les fichiers Twig du projet (templates/ et thèmes).
     * Retourne null si le projet ne contient aucun de ces répertoires.
     *
     * @param list<string> $patterns Motifs de noms de fichiers
     */
    public static function createTwigFinder(string $projectPath, array $patterns = ['*.twig']): ?Finder
    {
        $directories = self::locate($projectPath);
        if ($directories === []) {
            return null;
        }

        $finder = new Finder();
        $finder->files()->in($directories)->exclude(self::EXCLUDED_DIRECTORIES)->name($patterns);

        return $finder;
    }

    /**
     * Calcule le chemin d'un fichier relatif à la racine du projet,
     * avec des séparateurs normalisés. Utilisé pour les messages des issues,
     * afin de distinguer templates/... de themes/<Theme>/...
     */
    public static function relativePath(string $projectPath, string $absolutePath): string
    {
        $projectPath = rtrim(str_replace('\\', '/', $projectPath), '/');
        $absolutePath = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($absolutePath, $projectPath . '/')) {
            return substr($absolutePath, strlen($projectPath) + 1);
        }

        return $absolutePath;
    }

    /**
     * Libellé d'un template dans les messages des analyseurs qui citaient jusqu'ici
     * les chemins relatifs à templates/ : ce libellé est conservé pour templates/,
     * car le message entre dans la clé des baselines déjà enregistrées.
     * Les fichiers des thèmes gardent leur chemin depuis la racine du projet.
     */
    public static function templateLabel(string $projectPath, string $absolutePath): string
    {
        $relative = self::relativePath($projectPath, $absolutePath);

        return str_starts_with($relative, 'templates/') ? substr($relative, strlen('templates/')) : $relative;
    }

    /**
     * Lit les répertoires de thèmes déclarés dans la configuration du projet.
     * Seul %kernel.project_dir% est résolu : les autres paramètres ne sont pas
     * connus sans démarrer le kernel, ces entrées sont donc ignorées.
     *
     * @return list<string>
     */
    private static function readConfiguredThemeDirectories(string $projectPath): array
    {
        $configDir = $projectPath . '/config/packages';
        if (!is_dir($configDir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name(['*.yaml', '*.yml']);

        $directories = [];
        foreach ($finder as $file) {
            $content = $file->getContents();
            if (!str_contains($content, 'sylius_theme')) {
                continue;
            }

            try {
                $config = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
            } catch (ParseException) {
                continue;
            }

            if (!is_array($config)) {
                continue;
            }

            $configured = $config['sylius_theme']['sources']['filesystem']['directories'] ?? null;
            if (!is_array($configured)) {
                continue;
            }

            foreach ($configured as $directory) {
                if (!is_string($directory)) {
                    continue;
                }

                $resolved = str_replace('%kernel.project_dir%', $projectPath, $directory);
                if (str_contains($resolved, '%')) {
                    continue;
                }

                /* Un chemin relatif est interprété depuis la racine du projet */
                if (!str_starts_with($resolved, '/') && preg_match('#^[A-Za-z]:[/\\\\]#', $resolved) !== 1) {
                    $resolved = $projectPath . '/' . $resolved;
                }

                $directories[] = $resolved;
            }
        }

        return $directories;
    }

    /**
     * Supprime les doublons et les répertoires contenus dans un autre répertoire
     * de la liste, pour qu'aucun fichier ne soit compté deux fois.
     *
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private static function deduplicate(array $directories): array
    {
        $normalized = [];
        foreach ($directories as $directory) {
            $real = realpath($directory);
            $normalized[] = rtrim(str_replace('\\', '/', $real !== false ? $real : $directory), '/');
        }
        $normalized = array_values(array_unique($normalized));

        $result = [];
        foreach ($normalized as $candidate) {
            foreach ($normalized as $other) {
                if ($other !== $candidate && str_starts_with($candidate . '/', $other . '/')) {
                    continue 2;
                }
            }
            $result[] = $candidate;
        }

        return $result;
    }
}
