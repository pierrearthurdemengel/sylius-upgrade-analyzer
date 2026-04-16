<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Frontend;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur de la configuration use_webpack.
 * Detecte :
 * - La cle `use_webpack` dans les fichiers YAML de configuration sous config/
 *   (dans les blocs sylius_ui)
 * - Les templates Twig utilisant la variable `use_webpack`
 * La configuration use_webpack est supprimee dans Sylius 2.0 car Webpack Encore
 * n'est plus le systeme de build par defaut.
 */
final class UseWebpackConfigAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par reference a corriger */
    private const MINUTES_PER_REFERENCE = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Motif de detection dans les fichiers YAML */
    private const YAML_PATTERN = 'use_webpack';

    /** Motif de detection dans les templates Twig */
    private const TWIG_PATTERN = 'use_webpack';

    public function getName(): string
    {
        return 'Use Webpack Config';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification dans les fichiers de configuration YAML */
        if ($this->hasPatternInDirectory($projectPath . '/config', ['*.yaml', '*.yml'])) {
            return true;
        }

        /* Verification dans les templates Twig */
        if ($this->hasPatternInDirectory($projectPath . '/templates', ['*.twig', '*.html.twig'])) {
            return true;
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $totalReferences = 0;

        /* Etape 1 : analyse des fichiers YAML de configuration */
        $totalReferences += $this->analyzeYamlConfigs($report, $projectPath);

        /* Etape 2 : analyse des templates Twig */
        $totalReferences += $this->analyzeTwigTemplates($report, $projectPath);

        /* Etape 3 : resume global */
        if ($totalReferences > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::FRONTEND,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d reference(s) a use_webpack detectee(s)',
                    $totalReferences,
                ),
                detail: 'Le projet utilise la configuration use_webpack qui est supprimee '
                    . 'dans Sylius 2.0. Cette configuration etait utilisee pour basculer '
                    . 'entre Webpack Encore et le systeme d\'assets classique.',
                suggestion: 'Supprimer toute reference a use_webpack dans la configuration '
                    . 'et les templates. Migrer vers Symfony AssetMapper ou Vite.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $totalReferences * self::MINUTES_PER_REFERENCE,
            ));
        }
    }

    /**
     * Verifie si un repertoire contient des fichiers avec le motif use_webpack.
     *
     * @param list<string> $filePatterns Motifs de noms de fichiers a rechercher
     */
    private function hasPatternInDirectory(string $directory, array $filePatterns): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($directory);
        foreach ($filePatterns as $pattern) {
            $finder->name($pattern);
        }

        foreach ($finder as $file) {
            if (str_contains($file->getContents(), self::YAML_PATTERN)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyse les fichiers YAML de configuration pour detecter use_webpack.
     * Retourne le nombre de references trouvees.
     */
    private function analyzeYamlConfigs(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = $file->getContents();
            if (!str_contains($content, self::YAML_PATTERN)) {
                continue;
            }

            $lines = explode("\n", $content);
            $relativePath = 'config/' . $file->getRelativePathname();

            foreach ($lines as $index => $line) {
                if (!str_contains($line, self::YAML_PATTERN)) {
                    continue;
                }

                $count++;
                $lineNumber = $index + 1;

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::FRONTEND,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Configuration use_webpack detectee dans %s ligne %d',
                        $relativePath,
                        $lineNumber,
                    ),
                    detail: sprintf(
                        'Le fichier %s contient la cle use_webpack a la ligne %d. '
                        . 'Cette configuration est supprimee dans Sylius 2.0.',
                        $relativePath,
                        $lineNumber,
                    ),
                    suggestion: 'Supprimer la cle use_webpack de la configuration sylius_ui. '
                        . 'Migrer le systeme de build vers Symfony AssetMapper ou Vite.',
                    file: $filePath,
                    line: $lineNumber,
                    codeSnippet: trim($line),
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Analyse les templates Twig pour detecter l'utilisation de use_webpack.
     * Retourne le nombre de references trouvees.
     */
    private function analyzeTwigTemplates(MigrationReport $report, string $projectPath): int
    {
        $templatesDir = $projectPath . '/templates';
        if (!is_dir($templatesDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($templatesDir)->name('*.twig')->name('*.html.twig');

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = $file->getContents();
            if (!str_contains($content, self::TWIG_PATTERN)) {
                continue;
            }

            $lines = explode("\n", $content);
            $relativePath = 'templates/' . $file->getRelativePathname();

            foreach ($lines as $index => $line) {
                if (!str_contains($line, self::TWIG_PATTERN)) {
                    continue;
                }

                $count++;
                $lineNumber = $index + 1;

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::FRONTEND,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Variable use_webpack utilisee dans le template %s ligne %d',
                        $relativePath,
                        $lineNumber,
                    ),
                    detail: sprintf(
                        'Le template %s utilise la variable use_webpack a la ligne %d. '
                        . 'Cette variable n\'existe plus dans Sylius 2.0.',
                        $relativePath,
                        $lineNumber,
                    ),
                    suggestion: 'Supprimer la condition use_webpack du template et '
                        . 'utiliser directement le nouveau systeme d\'assets.',
                    file: $filePath,
                    line: $lineNumber,
                    codeSnippet: trim($line),
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }
}
