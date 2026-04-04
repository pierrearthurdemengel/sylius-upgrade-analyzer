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
 * Analyseur des contextes Behat deprecies.
 * Sylius 2.0 supprime ou renomme certains contextes Behat internes.
 * Cet analyseur detecte les references aux contextes deprecies dans la configuration Behat.
 */
final class BehatContextDeprecationAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par contexte deprecie */
    private const MINUTES_PER_CONTEXT = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Contextes Behat deprecies dans Sylius 2.0 */
    private const DEPRECATED_CONTEXTS = [
        'sylius.behat.context.hook.calendar',
        'sylius.behat.context.hook.doctrine_orm',
        'sylius.behat.context.setup.calendar',
        'sylius.behat.context.transform.calendar',
        'sylius.behat.context.ui.admin.managing_orders',
        'sylius.behat.context.ui.shop.checkout',
        'Sylius\\Behat\\Context\\Hook\\CalendarContext',
        'Sylius\\Behat\\Context\\Hook\\DoctrineORMContext',
        'Sylius\\Behat\\Context\\Setup\\CalendarContext',
        'Sylius\\Behat\\Context\\Transform\\CalendarContext',
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
                    '%d reference(s) a des contextes Behat deprecies detectee(s)',
                    $contextCount,
                ),
                detail: 'Sylius 2.0 supprime ou renomme certains contextes Behat internes. '
                    . 'Les suites de tests utilisant ces contextes doivent etre mises a jour.',
                suggestion: 'Remplacer les references aux contextes deprecies par les nouveaux '
                    . 'contextes Sylius 2.0 ou creer des contextes personnalises.',
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
                    message: sprintf('Contexte Behat deprecie "%s" detecte', $context),
                    detail: sprintf(
                        'Le contexte Behat "%s" reference dans %s est deprecie dans Sylius 2.0.',
                        $context,
                        str_replace($projectPath . '/', '', $filePath),
                    ),
                    suggestion: sprintf(
                        'Remplacer le contexte "%s" par son equivalent dans Sylius 2.0.',
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

        if ($dirs === []) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($dirs)->name('*.yml')->name('*.yaml');

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
                        message: sprintf('Contexte Behat deprecie "%s" dans %s', $context, $file->getRelativePathname()),
                        detail: sprintf(
                            'Le contexte Behat "%s" reference dans %s est deprecie dans Sylius 2.0.',
                            $context,
                            $file->getRelativePathname(),
                        ),
                        suggestion: sprintf(
                            'Remplacer le contexte "%s" par son equivalent dans Sylius 2.0 '
                            . 'ou creer un contexte personnalise.',
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
