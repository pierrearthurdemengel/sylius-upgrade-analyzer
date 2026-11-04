<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur des services Behat de Sylius supprimes en 2.0.
 *
 * Le seul service Behat retire est sylius.behat.api_security, scinde en
 * sylius.behat.api_admin_security et sylius.behat.api_shop_security (UPGRADE-2.0.md,
 * Testing Suite). Les contextes calendar, doctrine_orm, managing_orders et checkout
 * existent toujours en Sylius 2.0 : ils ne sont pas signales.
 */
final class BehatContextDeprecationAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par contexte deprecie */
    private const MINUTES_PER_CONTEXT = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Services Behat supprimes en Sylius 2.0 */
    private const DEPRECATED_CONTEXTS = [
        'sylius.behat.api_security',
    ];

    public function getName(): string
    {
        return 'Behat Context Deprecation';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification de l'existence des repertoires features/ ou tests/ avec des configs Behat */
        $dirs = [];
        if (is_dir($projectPath . '/features')) {
            $dirs[] = $projectPath . '/features';
        }
        if (is_dir($projectPath . '/tests')) {
            $dirs[] = $projectPath . '/tests';
        }

        if ($dirs === []) {
            /* Verifier aussi la racine pour behat.yml */
            return file_exists($projectPath . '/behat.yml')
                || file_exists($projectPath . '/behat.yml.dist');
        }

        $finder = new Finder();
        $finder->files()->in($dirs)->name('*.yml')->name('*.yaml');

        return $finder->hasResults();
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $contextCount = 0;

        /* Etape 1 : analyse des fichiers de configuration Behat a la racine */
        $contextCount += $this->analyzeBehatConfig($report, $projectPath, $projectPath . '/behat.yml');
        $contextCount += $this->analyzeBehatConfig($report, $projectPath, $projectPath . '/behat.yml.dist');

        /* Etape 2 : analyse des fichiers de configuration dans features/ et tests/ */
        $contextCount += $this->analyzeBehatConfigsInDirs($report, $projectPath);

        /* Etape 3 : resume global */
        if ($contextCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d reference(s) a des services Behat supprimes detectee(s)',
                    $contextCount,
                ),
                detail: 'Sylius 2.0 remplace sylius.behat.api_security par sylius.behat.api_admin_security '
                    . 'et sylius.behat.api_shop_security : les contextes qui l\'injectent ne demarrent plus.',
                suggestion: 'Injecter sylius.behat.api_admin_security dans les contextes d\'administration '
                    . 'et sylius.behat.api_shop_security dans les contextes boutique.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $contextCount * self::MINUTES_PER_CONTEXT,
            ));
        }
    }

    /**
     * Analyse un fichier de configuration Behat specifique.
     * Retourne le nombre de contextes deprecies trouves.
     */
    private function analyzeBehatConfig(MigrationReport $report, string $projectPath, string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }

        $content = (string) file_get_contents($filePath);
        $count = 0;

        foreach (self::DEPRECATED_CONTEXTS as $context) {
            if (str_contains($content, $context)) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Service Behat supprime "%s" detecte', $context),
                    detail: sprintf(
                        'Le service Behat "%s" reference dans %s n\'existe plus dans Sylius 2.0.',
                        $context,
                        str_replace($projectPath . '/', '', $filePath),
                    ),
                    suggestion: sprintf(
                        'Remplacer "%s" par sylius.behat.api_admin_security ou sylius.behat.api_shop_security.',
                        $context,
                    ),
                    file: $filePath,
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Analyse les fichiers de configuration Behat dans features/ et tests/.
     * Retourne le nombre de contextes deprecies trouves.
     */
    private function analyzeBehatConfigsInDirs(MigrationReport $report, string $projectPath): int
    {
        $dirs = [];
        if (is_dir($projectPath . '/features')) {
            $dirs[] = $projectPath . '/features';
        }
        if (is_dir($projectPath . '/tests')) {
            $dirs[] = $projectPath . '/tests';
        }
        /* Les contextes du projet sont souvent declares dans config/services_test.yaml */
        if (is_dir($projectPath . '/config')) {
            $dirs[] = $projectPath . '/config';
        }

        if ($dirs === []) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($dirs)->name(['*.yml', '*.yaml', '*.xml']);

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);

            foreach (self::DEPRECATED_CONTEXTS as $context) {
                if (str_contains($content, $context)) {
                    $count++;
                    $report->addIssue(new MigrationIssue(
                        severity: Severity::WARNING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf('Service Behat supprime "%s" dans %s', $context, $file->getRelativePathname()),
                        detail: sprintf(
                            'Le service Behat "%s" reference dans %s n\'existe plus dans Sylius 2.0.',
                            $context,
                            $file->getRelativePathname(),
                        ),
                        suggestion: sprintf(
                            'Remplacer "%s" par sylius.behat.api_admin_security ou sylius.behat.api_shop_security.',
                            $context,
                        ),
                        file: $filePath,
                        docUrl: self::DOC_URL,
                    ));
                }
            }
        }

        return $count;
    }
}
