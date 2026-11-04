<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Analyseur de la configuration Liip Imagine pour Sylius 2.0.
 * Sylius 2.0 impose le renommage du resolver et du loader "default" en "sylius_image".
 * Cet analyseur detecte les configurations obsoletes dans les fichiers YAML de config/.
 */
final class LiipImagineConfigAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par modification de configuration necessaire */
    private const MINUTES_PER_CONFIG = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    public function getName(): string
    {
        return 'Liip Imagine Config';
    }

    public function supports(MigrationReport $report): bool
    {
        $configDir = $report->getProjectPath() . '/config';
        if (!is_dir($configDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());
            if (str_contains($content, 'liip_imagine')) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $issueCount = 0;

        /* Etape 1 : analyse des fichiers YAML contenant liip_imagine */
        $issueCount += $this->analyzeLiipImagineConfig($report, $projectPath);

        /* Etape 2 : detection de resolve_cache_relative dans les fichiers YAML */
        $issueCount += $this->analyzeResolveCacheRelative($report, $projectPath);

        /* Etape 3 : ajout d'un probleme de synthese */
        if ($issueCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d probleme(s) de configuration Liip Imagine detecte(s)',
                    $issueCount,
                ),
                detail: 'La configuration Liip Imagine doit etre adaptee pour Sylius 2.0. '
                    . 'Le resolver et le loader "default" doivent etre renommes en "sylius_image".',
                suggestion: 'Renommer les resolvers et loaders "default" en "sylius_image" '
                    . 'et supprimer les references a resolve_cache_relative.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $issueCount * self::MINUTES_PER_CONFIG,
            ));
        }
    }

    /**
     * Analyse les fichiers YAML pour detecter la configuration liip_imagine avec resolver/loader "default".
     * Retourne le nombre de problemes detectes.
     */
    private function analyzeLiipImagineConfig(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return 0;
        }

        $count = 0;
        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            try {
                $config = Yaml::parseFile($filePath, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($config) || !isset($config['liip_imagine'])) {
                continue;
            }

            $liipConfig = $config['liip_imagine'];
            if (!is_array($liipConfig)) {
                continue;
            }

            /* Detection du resolver "default" */
            if (isset($liipConfig['resolvers']['default'])) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Resolver "default" detecte dans %s',
                        $file->getRelativePathname(),
                    ),
                    detail: sprintf(
                        'Le fichier %s configure un resolver nomme "default". '
                        . 'Sylius 2.0 impose le nom "sylius_image" pour le resolver.',
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Renommer le resolver "default" en "sylius_image" dans la configuration liip_imagine.',
                    file: $filePath,
                    docUrl: self::DOC_URL,
                ));
            }

            /* Detection du loader "default" */
            if (isset($liipConfig['loaders']['default'])) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Loader "default" detecte dans %s',
                        $file->getRelativePathname(),
                    ),
                    detail: sprintf(
                        'Le fichier %s configure un loader nomme "default". '
                        . 'Sylius 2.0 impose le nom "sylius_image" pour le loader.',
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Renommer le loader "default" en "sylius_image" dans la configuration liip_imagine.',
                    file: $filePath,
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Detecte les references a resolve_cache_relative dans la configuration liip_imagine.
     * Retourne le nombre de references trouvees.
     */
    private function analyzeResolveCacheRelative(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return 0;
        }

        $count = 0;
        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            if (!str_contains($content, 'resolve_cache_relative')) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $index => $line) {
                if (!str_contains($line, 'resolve_cache_relative')) {
                    continue;
                }

                $count++;
                $lineNumber = $index + 1;

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Filtre resolve_cache_relative detecte dans %s',
                        $file->getRelativePathname(),
                    ),
                    detail: sprintf(
                        'Le fichier %s utilise le filtre resolve_cache_relative ligne %d. '
                        . 'Ce filtre est obsolete dans Sylius 2.0.',
                        $file->getRelativePathname(),
                        $lineNumber,
                    ),
                    suggestion: 'Supprimer le filtre resolve_cache_relative de la configuration liip_imagine. '
                        . 'Les chemins de cache sont geres automatiquement par le nouveau resolver.',
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
