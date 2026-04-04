<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur de surcharges de templates Twig.
 * Détecte les templates Sylius surchargés qui devront être migrés vers le système de hooks Twig.
 */
final class TwigTemplateOverrideAnalyzer implements AnalyzerInterface
{
    /**
     * Répertoires à scanner pour détecter les surcharges de templates.
     * Inclut les conventions Symfony 4/5 et les anciennes conventions Symfony 3.
     *
     * @var list<string>
     */
    private const SCAN_DIRECTORIES = [
        'templates/bundles/SyliusShopBundle',
        'templates/bundles/SyliusAdminBundle',
        'templates/bundles/SyliusUiBundle',
        'app/Resources/SyliusShopBundle/views',
    ];

    /**
     * Motif glob pour détecter les vues dans les bundles du projet.
     */
    private const BUNDLE_VIEWS_PATTERN = 'src/*/Resources/views';

    private readonly TwigHookMigrationMapper $mapper;

    public function __construct(?TwigHookMigrationMapper $mapper = null)
    {
        $this->mapper = $mapper ?? new TwigHookMigrationMapper();
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $templates = $this->findOverriddenTemplates($projectPath);

        foreach ($templates as $templateFile) {
            $relativePath = $this->getRelativePath($projectPath, $templateFile->getRealPath());
            $bundlePath = $this->extractBundlePath($relativePath);
            $content = $templateFile->getContents();

            /* Recherche de la correspondance avec un hook Sylius 2.x */
            $hookMapping = $this->mapper->mapTemplateToHook($bundlePath);

            /* Calcul de l'estimation basée sur le contenu */
            $contentMinutes = $this->mapper->getComplexityMinutes($bundlePath, $content);

            /* Utilisation de l'estimation du mapping si disponible, sinon celle du contenu */
            $estimatedMinutes = $hookMapping !== null
                ? max($hookMapping['minutes'], $contentMinutes)
                : $contentMinutes;

            $hookName = $hookMapping['hook'] ?? 'hook non répertorié';

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::TWIG,
                analyzer: $this->getName(),
                message: sprintf('Template Twig surchargé détecté : %s', $bundlePath),
                detail: sprintf(
                    'Ce template surcharge un template Sylius natif. '
                    . 'Dans Sylius 2.x, le système de templates a été remplacé par des hooks Twig. '
                    . 'Le hook correspondant est : %s',
                    $hookName,
                ),
                suggestion: sprintf(
                    'Migrer cette surcharge vers le système de hooks Twig. '
                    . 'Créer un hook Twig utilisant "%s" et supprimer la surcharge de template. '
                    . 'Consulter la documentation de migration Sylius pour les détails.',
                    $hookName,
                ),
                file: $relativePath,
                line: 1,
                codeSnippet: $this->extractSnippet($content),
                docUrl: 'https://docs.sylius.com/en/latest/the-book/frontend/twig-hooks.html',
                estimatedMinutes: $estimatedMinutes,
            ));
        }
    }

    public function getName(): string
    {
        return 'Twig Template Override';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Vérification de l'existence d'au moins un répertoire de surcharge */
        foreach (self::SCAN_DIRECTORIES as $directory) {
            $fullPath = $projectPath . '/' . $directory;
            if (is_dir($fullPath)) {
                return true;
            }
        }

        /* Vérification des vues dans les bundles du projet */
        $bundleViewsPath = $projectPath . '/' . self::BUNDLE_VIEWS_PATTERN;
        $bundleViewDirs = glob($bundleViewsPath);
        if ($bundleViewDirs !== false && count($bundleViewDirs) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Recherche tous les fichiers Twig surchargés dans les répertoires ciblés.
     *
     * @return \Symfony\Component\Finder\Finder<\SplFileInfo>
     */
    private function findOverriddenTemplates(string $projectPath): Finder
    {
        $finder = new Finder();
        $finder->files()->name('*.twig');

        $directories = [];

        /* Ajout des répertoires de surcharge standard */
        foreach (self::SCAN_DIRECTORIES as $directory) {
            $fullPath = $projectPath . '/' . $directory;
            if (is_dir($fullPath)) {
                $directories[] = $fullPath;
            }
        }

        /* Ajout des vues dans les bundles du projet */
        $bundleViewsPath = $projectPath . '/' . self::BUNDLE_VIEWS_PATTERN;
        $bundleViewDirs = glob($bundleViewsPath);
        if ($bundleViewDirs !== false) {
            foreach ($bundleViewDirs as $dir) {
                if (is_dir($dir)) {
                    $directories[] = $dir;
                }
            }
        }

        if (count($directories) === 0) {
            /* Retour d'un finder vide si aucun répertoire n'est trouvé */
            $finder->in(sys_get_temp_dir())->name('__impossible_match__');

            return $finder;
        }

        $finder->in($directories);

        return $finder;
    }

    /**
     * Calcule le chemin relatif d'un fichier par rapport à la racine du projet.
     */
    private function getRelativePath(string $projectPath, string $absolutePath): string
    {
        $projectPath = rtrim(str_replace('\\', '/', $projectPath), '/');
        $absolutePath = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($absolutePath, $projectPath)) {
            return ltrim(substr($absolutePath, strlen($projectPath)), '/');
        }

        return $absolutePath;
    }

    /**
     * Extrait le chemin du bundle depuis le chemin relatif du template.
     * Par exemple : templates/bundles/SyliusShopBundle/Product/show.html.twig
     * devient : SyliusShopBundle/Product/show.html.twig
     */
    private function extractBundlePath(string $relativePath): string
    {
        /* Normalisation des séparateurs */
        $normalized = str_replace('\\', '/', $relativePath);

        /* Extraction depuis templates/bundles/ */
        $bundlesPrefix = 'templates/bundles/';
        if (str_starts_with($normalized, $bundlesPrefix)) {
            return substr($normalized, strlen($bundlesPrefix));
        }

        /* Extraction depuis app/Resources/.../views/ */
        if (preg_match('#^app/Resources/(Sylius\w+Bundle)/views/(.+)$#', $normalized, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }

        /* Extraction depuis src/.../Resources/views/ */
        if (preg_match('#^src/[^/]+/Resources/views/(.+)$#', $normalized, $matches)) {
            return $matches[1];
        }

        return $normalized;
    }

    /**
     * Extrait un extrait de code des premières lignes significatives du template.
     */
    private function extractSnippet(string $content, int $maxLines = 5): string
    {
        $lines = explode("\n", $content);
        $significantLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $significantLines[] = $line;
            if (count($significantLines) >= $maxLines) {
                break;
            }
        }

        return implode("\n", $significantLines);
    }
}
