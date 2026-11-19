<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\TemplateDirectoryLocator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Inventaire des surcharges de templates Sylius d'un projet (templates/bundles/,
 * vues des bundles du projet, thèmes SyliusThemeBundle) et comparaison de leur
 * contenu avec les templates d'origine présents dans vendor/.
 *
 * Partagé entre les analyseurs qui lisent les templates : la reprise d'une
 * surcharge est estimée une seule fois, par TwigTemplateOverrideAnalyzer. Les
 * analyseurs Semantic UI et Sonata Block Event s'appuient sur cet inventaire
 * pour ne pas compter une seconde fois le même fichier.
 */
final class TemplateOverrideInventory
{
    /**
     * Répertoires de surcharge standard, conventions Symfony 4/5 et Symfony 3.
     *
     * @var list<string>
     */
    private const SCAN_DIRECTORIES = [
        'templates/bundles/SyliusShopBundle',
        'templates/bundles/SyliusAdminBundle',
        'templates/bundles/SyliusUiBundle',
        'app/Resources/SyliusShopBundle/views',
    ];

    /** Motif glob des vues portées par les bundles du projet */
    private const BUNDLE_VIEWS_PATTERN = 'src/*/Resources/views';

    /**
     * Bundles Sylius dont les surcharges sont recherchées dans les thèmes.
     *
     * @var list<string>
     */
    private const OVERRIDABLE_BUNDLES = [
        'SyliusShopBundle',
        'SyliusAdminBundle',
        'SyliusUiBundle',
    ];

    /**
     * Emplacements possibles des templates d'origine dans vendor/, selon que le
     * projet installe le monorepo sylius/sylius ou les paquets séparés
     * (sylius/shop-bundle...). %s = nom court du bundle (Shop, Admin, Ui),
     * %p = nom du paquet séparé (shop-bundle...).
     *
     * @var list<string>
     */
    private const VENDOR_TEMPLATE_DIRECTORIES = [
        'vendor/sylius/sylius/src/Sylius/Bundle/%sBundle/Resources/views',
        'vendor/sylius/sylius/src/Sylius/Bundle/%sBundle/templates',
        'vendor/sylius/%p/Resources/views',
        'vendor/sylius/%p/templates',
    ];

    /**
     * Indique si le projet contient au moins un répertoire de surcharge.
     */
    public static function hasOverrideDirectories(string $projectPath): bool
    {
        return self::findOverrideDirectories($projectPath) !== [];
    }

    /**
     * Retourne les fichiers Twig surchargés du projet. Les fichiers hors thèmes
     * viennent en premier, puis ceux des thèmes, chacun trié par chemin : quand un
     * même template est surchargé à plusieurs endroits, la première occurrence
     * sert de référence aux suivantes.
     *
     * @return list<SplFileInfo>
     */
    public static function findOverrideFiles(string $projectPath): array
    {
        $directories = self::findOverrideDirectories($projectPath);
        if ($directories === []) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($directories)->name('*.twig');

        $themeRoots = TemplateDirectoryLocator::locateThemeRoots($projectPath);
        $files = [];
        foreach ($finder as $file) {
            $path = str_replace('\\', '/', (string) ($file->getRealPath() ?: $file->getPathname()));
            $inTheme = false;
            foreach ($themeRoots as $root) {
                if (str_starts_with($path, $root . '/')) {
                    $inTheme = true;
                    break;
                }
            }
            $files[] = ['file' => $file, 'theme' => $inTheme, 'path' => $path];
        }

        usort($files, static fn (array $a, array $b): int => [$a['theme'], $a['path']] <=> [$b['theme'], $b['path']]);

        return array_map(static fn (array $entry): SplFileInfo => $entry['file'], $files);
    }

    /**
     * Retourne l'ensemble des chemins absolus (normalisés) des surcharges du projet,
     * pour un test d'appartenance rapide depuis les autres analyseurs.
     *
     * @return array<string, true>
     */
    public static function overridePathSet(string $projectPath): array
    {
        $set = [];
        foreach (self::findOverrideFiles($projectPath) as $file) {
            $set[self::normalizePath((string) ($file->getRealPath() ?: $file->getPathname()))] = true;
        }

        return $set;
    }

    /**
     * Normalise un chemin absolu pour le comparer à ceux de overridePathSet().
     */
    public static function normalizePath(string $absolutePath): string
    {
        return str_replace('\\', '/', $absolutePath);
    }

    /**
     * Extrait le chemin du bundle depuis le chemin relatif du template.
     * Par exemple : templates/bundles/SyliusShopBundle/Product/show.html.twig
     * ou themes/MyTheme/templates/bundles/SyliusShopBundle/Product/show.html.twig
     * devient : SyliusShopBundle/Product/show.html.twig
     */
    public static function bundlePath(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);

        /* Extraction depuis templates/bundles/ (projet ou thème) */
        if (preg_match('#(?:^|/)templates/bundles/(Sylius\w+Bundle/.+)$#', $normalized, $matches)) {
            return $matches[1];
        }

        /* Extraction depuis src/.../Resources/views/ */
        if (preg_match('#^src/[^/]+/Resources/views/(.+)$#', $normalized, $matches)) {
            return $matches[1];
        }

        /* Extraction depuis .../SyliusXxxBundle/views/ (app/Resources ou thème 1.x) */
        if (preg_match('#(?:^|/)(Sylius\w+Bundle)/views/(.+)$#', $normalized, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }

        return $normalized;
    }

    /**
     * Retourne le contenu du template Sylius d'origine correspondant à un chemin
     * de bundle, ou null si vendor/ n'est pas installé ou si le template n'existe
     * pas dans Sylius (template propre au projet).
     */
    public static function findVendorOriginal(string $projectPath, string $bundlePath): ?string
    {
        if (preg_match('#^Sylius(\w+)Bundle/(.+)$#', $bundlePath, $matches) !== 1) {
            return null;
        }

        $shortName = $matches[1];
        $package = strtolower($shortName) . '-bundle';

        foreach (self::VENDOR_TEMPLATE_DIRECTORIES as $pattern) {
            $directory = str_replace(['%s', '%p'], [$shortName, $package], $pattern);
            $candidate = $projectPath . '/' . $directory . '/' . $matches[2];
            if (is_file($candidate)) {
                $content = file_get_contents($candidate);

                return $content === false ? null : $content;
            }
        }

        return null;
    }

    /**
     * Compte les lignes qui diffèrent entre deux versions d'un template.
     *
     * La comparaison ignore l'indentation et les lignes vides, et ne tient pas
     * compte de l'ordre des lignes : seul compte le volume de personnalisation à
     * reporter. Le résultat est le plus grand des deux volumes (lignes ajoutées,
     * lignes retirées), pour qu'une ligne modifiée ne compte qu'une fois.
     */
    public static function changedLines(string $content, string $reference): int
    {
        $remaining = array_count_values(self::significantLines($reference));
        $added = 0;

        foreach (self::significantLines($content) as $line) {
            if (($remaining[$line] ?? 0) > 0) {
                $remaining[$line]--;
                continue;
            }
            $added++;
        }

        return max($added, array_sum($remaining));
    }

    /**
     * Empreinte du contenu d'un template, insensible à l'indentation, aux lignes
     * vides et aux fins de ligne Windows. Deux fichiers de même empreinte sont des
     * copies l'un de l'autre et ne sont migrés qu'une fois.
     */
    public static function fingerprint(string $content): string
    {
        return md5(implode("\n", self::significantLines($content)));
    }

    /**
     * Lignes non vides du template, sans indentation.
     *
     * @return list<string>
     */
    private static function significantLines(string $content): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    /**
     * Liste les répertoires de surcharge présents dans le projet.
     *
     * @return list<string>
     */
    private static function findOverrideDirectories(string $projectPath): array
    {
        $directories = [];

        foreach (self::SCAN_DIRECTORIES as $directory) {
            $fullPath = $projectPath . '/' . $directory;
            if (is_dir($fullPath)) {
                $directories[] = $fullPath;
            }
        }

        $bundleViewDirs = glob($projectPath . '/' . self::BUNDLE_VIEWS_PATTERN);
        if ($bundleViewDirs !== false) {
            foreach ($bundleViewDirs as $dir) {
                if (is_dir($dir)) {
                    $directories[] = $dir;
                }
            }
        }

        foreach (self::findThemeOverrideDirectories($projectPath) as $dir) {
            $directories[] = $dir;
        }

        return $directories;
    }

    /**
     * Recherche, dans chaque thème, les répertoires de surcharge des bundles Sylius.
     * Deux structures coexistent selon la version de SyliusThemeBundle :
     * themes/<Theme>/templates/bundles/SyliusShopBundle/ (2.x)
     * et themes/<Theme>/SyliusShopBundle/views/ (1.x).
     *
     * @return list<string>
     */
    private static function findThemeOverrideDirectories(string $projectPath): array
    {
        $themeRoots = TemplateDirectoryLocator::locateThemeRoots($projectPath);
        if ($themeRoots === []) {
            return [];
        }

        $finder = new Finder();
        $finder->directories()
            ->in($themeRoots)
            ->exclude(TemplateDirectoryLocator::EXCLUDED_DIRECTORIES)
            ->name(self::OVERRIDABLE_BUNDLES);

        $directories = [];
        foreach ($finder as $dir) {
            $path = str_replace('\\', '/', $dir->getPathname());

            if (str_ends_with(dirname($path), '/templates/bundles')) {
                $directories[] = $path;
            } elseif (is_dir($path . '/views')) {
                $directories[] = $path . '/views';
            }
        }

        sort($directories);

        return $directories;
    }
}
