<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Frontend;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Analyseur de la configuration Webpack Encore.
 * Sylius 2.x migre vers Symfony AssetMapper ou Vite, rendant Webpack Encore obsolete.
 * Cet analyseur detecte la presence de Webpack Encore dans le projet.
 */
final class WebpackEncoreAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes pour la migration du systeme de build */
    private const MINUTES_BUILD_MIGRATION = 180;

    /** Expression reguliere pour detecter la configuration Encore dans webpack.config.js */
    private const ENCORE_CONFIG_REGEX = '/Encore\b/';

    /** Nom du paquet npm Webpack Encore */
    private const ENCORE_NPM_PACKAGE = '@symfony/webpack-encore';

    public function getName(): string
    {
        return 'Webpack Encore';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification de l'existence de webpack.config.js */
        if (file_exists($projectPath . '/webpack.config.js')) {
            return true;
        }

        /* Verification de l'existence de package.json */
        if (file_exists($projectPath . '/package.json')) {
            return true;
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        /* Etape 1 : analyse de webpack.config.js */
        $webpackDetected = $this->analyzeWebpackConfig($report, $projectPath);

        /* Etape 2 : analyse de package.json */
        $packageDetected = $this->analyzePackageJson($report, $projectPath);

        $encoreDetected = $webpackDetected || $packageDetected;

        /* Etape 3 : ajout d'un probleme global si Webpack Encore est detecte */
        if ($encoreDetected) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::FRONTEND,
                analyzer: $this->getName(),
                message: 'Migration du systeme de build Webpack Encore requise',
                detail: 'Le projet utilise Webpack Encore comme systeme de build front-end. '
                    . 'Sylius 2.x recommande de migrer vers Symfony AssetMapper ou Vite '
                    . 'pour une meilleure integration avec l\'ecosysteme Symfony moderne.',
                suggestion: 'Migrer vers Symfony AssetMapper (solution sans bundler) '
                    . 'ou Vite (bundler moderne et rapide). '
                    . 'Supprimer webpack.config.js et la dependance '
                    . self::ENCORE_NPM_PACKAGE . ' apres migration.',
                docUrl: 'https://symfony.com/doc/current/frontend.html',
                estimatedMinutes: self::MINUTES_BUILD_MIGRATION,
            ));
        }
    }

    /**
     * Analyse webpack.config.js pour detecter la configuration Encore.
     * Retourne true si Encore est detecte, false sinon.
     */
    private function analyzeWebpackConfig(MigrationReport $report, string $projectPath): bool
    {
        $webpackConfigPath = $projectPath . '/webpack.config.js';

        if (!file_exists($webpackConfigPath)) {
            return false;
        }

        $content = (string) file_get_contents($webpackConfigPath);

        /* Verification de la presence de la configuration Encore */
        if (preg_match(self::ENCORE_CONFIG_REGEX, $content) !== 1) {
            return false;
        }

        /* Detection des points d'entree configures */
        $entryPoints = $this->detectEntryPoints($content);

        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::FRONTEND,
            analyzer: $this->getName(),
            message: 'Configuration Webpack Encore detectee dans webpack.config.js',
            detail: sprintf(
                'Le fichier webpack.config.js contient une configuration Encore '
                . 'avec %d point(s) d\'entree detecte(s)%s. '
                . 'Cette configuration devra etre migree vers AssetMapper ou Vite.',
                count($entryPoints),
                count($entryPoints) > 0
                    ? ' : ' . implode(', ', $entryPoints)
                    : '',
            ),
            suggestion: 'Remplacer webpack.config.js par la configuration '
                . 'Symfony AssetMapper (importmap.php) ou un fichier vite.config.js.',
            file: $webpackConfigPath,
        ));

        return true;
    }

    /**
     * Analyse package.json pour detecter la dependance Webpack Encore.
     * Retourne true si la dependance est trouvee, false sinon.
     */
    private function analyzePackageJson(MigrationReport $report, string $projectPath): bool
    {
        $packageJsonPath = $projectPath . '/package.json';

        if (!file_exists($packageJsonPath)) {
            return false;
        }

        $content = (string) file_get_contents($packageJsonPath);
        $packageData = json_decode($content, true);

        if (!is_array($packageData)) {
            return false;
        }

        /* Recherche dans devDependencies et dependencies */
        if (isset($packageData['devDependencies'][self::ENCORE_NPM_PACKAGE])) {
            $version = (string) $packageData['devDependencies'][self::ENCORE_NPM_PACKAGE];
        } elseif (isset($packageData['dependencies'][self::ENCORE_NPM_PACKAGE])) {
            $version = (string) $packageData['dependencies'][self::ENCORE_NPM_PACKAGE];
        } else {
            return false;
        }

        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::FRONTEND,
            analyzer: $this->getName(),
            message: sprintf(
                'Dependance %s detectee dans package.json',
                self::ENCORE_NPM_PACKAGE,
            ),
            detail: sprintf(
                'La dependance %s (version %s) est presente dans package.json. '
                . 'Cette dependance sera obsolete apres la migration vers '
                . 'Symfony AssetMapper ou Vite.',
                self::ENCORE_NPM_PACKAGE,
                $version,
            ),
            suggestion: 'Supprimer la dependance ' . self::ENCORE_NPM_PACKAGE
                . ' de package.json apres avoir migre vers AssetMapper ou Vite.',
            file: $packageJsonPath,
        ));

        return true;
    }

    /**
     * Detecte les points d'entree configures dans webpack.config.js.
     *
     * @return list<string> Liste des noms de points d'entree
     */
    private function detectEntryPoints(string $content): array
    {
        $entryPoints = [];

        /* Detection des appels addEntry('name', ...) */
        if (preg_match_all('/\.addEntry\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $entryPoint) {
                $entryPoints[] = $entryPoint;
            }
        }

        /* Detection des appels addStyleEntry('name', ...) */
        if (preg_match_all('/\.addStyleEntry\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $entryPoint) {
                $entryPoints[] = $entryPoint;
            }
        }

        return $entryPoints;
    }
}
