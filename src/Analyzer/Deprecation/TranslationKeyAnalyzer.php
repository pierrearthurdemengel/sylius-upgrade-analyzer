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
 * Analyseur des cles de traduction Sylius.
 * Sylius 2.0 renomme certaines cles de traduction. Cet analyseur detecte les cles
 * personnalisees et les usages dans les templates qui pourraient etre impactes.
 */
final class TranslationKeyAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par groupe de traductions */
    private const MINUTES_PER_GROUP = 30;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Cles de traduction connues comme ayant change dans Sylius 2.0 */
    private const CHANGED_KEY_PREFIXES = [
        'sylius.ui.admin' => 'sylius.admin',
        'sylius.ui.shop' => 'sylius.shop',
        'sylius.form.channel' => 'sylius.admin.channel',
        'sylius.form.product' => 'sylius.admin.product',
        'sylius.email' => 'sylius.notification',
    ];

    public function getName(): string
    {
        return 'Translation Key';
    }

    public function supports(MigrationReport $report): bool
    {
        $translationsDir = $report->getProjectPath() . '/translations';

        return is_dir($translationsDir);
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $groupCount = 0;

        /* Etape 1 : analyse des fichiers de traduction */
        $groupCount += $this->analyzeTranslationFiles($report, $projectPath);

        /* Etape 2 : analyse des templates Twig pour les filtres trans */
        $groupCount += $this->analyzeTemplateTransFilters($report, $projectPath);

        /* Etape 3 : resume global */
        if ($groupCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::SUGGESTION,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d groupe(s) de cles de traduction Sylius detecte(s)',
                    $groupCount,
                ),
                detail: 'Certaines cles de traduction Sylius ont ete renommees dans Sylius 2.0. '
                    . 'Les traductions personnalisees utilisant les anciens prefixes doivent etre mises a jour.',
                suggestion: 'Verifier et mettre a jour les cles de traduction pour correspondre '
                    . 'aux nouveaux prefixes de Sylius 2.0.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $groupCount * self::MINUTES_PER_GROUP,
            ));
        }
    }

    /**
     * Analyse les fichiers de traduction dans translations/ pour les cles sylius.*.
     * Retourne le nombre de groupes de cles detectes.
     */
    private function analyzeTranslationFiles(MigrationReport $report, string $projectPath): int
    {
        $translationsDir = $projectPath . '/translations';
        if (!is_dir($translationsDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($translationsDir)->name('*.yaml')->name('*.yml')->name('*.xlf')->name('*.xliff');

        $groupCount = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $extension = $file->getExtension();

            if ($extension === 'yaml' || $extension === 'yml') {
                $groupCount += $this->analyzeYamlTranslationFile($report, $filePath, $file->getRelativePathname());
            } elseif ($extension === 'xlf' || $extension === 'xliff') {
                $groupCount += $this->analyzeXlfTranslationFile($report, $filePath, $file->getRelativePathname());
            }
        }

        return $groupCount;
    }

    /**
     * Analyse un fichier de traduction YAML.
     * Retourne le nombre de groupes de cles impactees.
     */
    private function analyzeYamlTranslationFile(MigrationReport $report, string $filePath, string $relativePath): int
    {
        try {
            $translations = Yaml::parseFile($filePath, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable) {
            return 0;
        }

        if (!is_array($translations)) {
            return 0;
        }

        $flatKeys = $this->flattenKeys($translations);
        $impactedPrefixes = [];

        foreach ($flatKeys as $key) {
            foreach (self::CHANGED_KEY_PREFIXES as $oldPrefix => $newPrefix) {
                if (str_starts_with($key, $oldPrefix) && !isset($impactedPrefixes[$oldPrefix])) {
                    $impactedPrefixes[$oldPrefix] = $newPrefix;
                }
            }
        }

        $count = 0;
        foreach ($impactedPrefixes as $oldPrefix => $newPrefix) {
            $count++;
            $report->addIssue(new MigrationIssue(
                severity: Severity::SUGGESTION,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    'Cles de traduction avec prefixe "%s" dans %s',
                    $oldPrefix,
                    $relativePath,
                ),
                detail: sprintf(
                    'Le fichier %s contient des cles de traduction avec le prefixe "%s" '
                    . 'qui a ete renomme en "%s" dans Sylius 2.0.',
                    $relativePath,
                    $oldPrefix,
                    $newPrefix,
                ),
                suggestion: sprintf(
                    'Remplacer le prefixe "%s" par "%s" dans les cles de traduction.',
                    $oldPrefix,
                    $newPrefix,
                ),
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Analyse un fichier de traduction XLF/XLIFF.
     * Retourne le nombre de groupes de cles impactees.
     */
    private function analyzeXlfTranslationFile(MigrationReport $report, string $filePath, string $relativePath): int
    {
        $content = (string) file_get_contents($filePath);
        $impactedPrefixes = [];

        foreach (self::CHANGED_KEY_PREFIXES as $oldPrefix => $newPrefix) {
            if (str_contains($content, $oldPrefix) && !isset($impactedPrefixes[$oldPrefix])) {
                $impactedPrefixes[$oldPrefix] = $newPrefix;
            }
        }

        $count = 0;
        foreach ($impactedPrefixes as $oldPrefix => $newPrefix) {
            $count++;
            $report->addIssue(new MigrationIssue(
                severity: Severity::SUGGESTION,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    'Cles de traduction avec prefixe "%s" dans %s',
                    $oldPrefix,
                    $relativePath,
                ),
                detail: sprintf(
                    'Le fichier %s contient des cles de traduction avec le prefixe "%s" '
                    . 'qui a ete renomme en "%s" dans Sylius 2.0.',
                    $relativePath,
                    $oldPrefix,
                    $newPrefix,
                ),
                suggestion: sprintf(
                    'Remplacer le prefixe "%s" par "%s" dans les cles de traduction.',
                    $oldPrefix,
                    $newPrefix,
                ),
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Analyse les templates Twig pour les filtres trans avec des cles sylius.*.
     * Retourne le nombre de groupes detectes.
     */
    private function analyzeTemplateTransFilters(MigrationReport $report, string $projectPath): int
    {
        $templatesDir = $projectPath . '/templates';
        if (!is_dir($templatesDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($templatesDir)->name('*.html.twig')->name('*.txt.twig');

        $impactedPrefixesByFile = [];

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);

            foreach (self::CHANGED_KEY_PREFIXES as $oldPrefix => $newPrefix) {
                if (str_contains($content, $oldPrefix)) {
                    $key = $filePath . '|' . $oldPrefix;
                    if (!isset($impactedPrefixesByFile[$key])) {
                        $impactedPrefixesByFile[$key] = [
                            'filePath' => $filePath,
                            'relativePath' => $file->getRelativePathname(),
                            'oldPrefix' => $oldPrefix,
                            'newPrefix' => $newPrefix,
                        ];
                    }
                }
            }
        }

        $count = 0;
        foreach ($impactedPrefixesByFile as $entry) {
            $count++;
            $report->addIssue(new MigrationIssue(
                severity: Severity::SUGGESTION,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    'Cle de traduction "%s" utilisee dans le template %s',
                    $entry['oldPrefix'],
                    $entry['relativePath'],
                ),
                detail: sprintf(
                    'Le template %s utilise des cles de traduction avec le prefixe "%s" '
                    . 'qui a ete renomme en "%s" dans Sylius 2.0.',
                    $entry['relativePath'],
                    $entry['oldPrefix'],
                    $entry['newPrefix'],
                ),
                suggestion: sprintf(
                    'Mettre a jour les references "%s" en "%s" dans le template.',
                    $entry['oldPrefix'],
                    $entry['newPrefix'],
                ),
                file: $entry['filePath'],
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Aplatit un tableau multidimensionnel de traductions en cles a points.
     *
     * @param array<string, mixed> $array  Tableau a aplatir
     * @param string               $prefix Prefixe courant
     *
     * @return list<string> Liste des cles aplaties
     */
    private function flattenKeys(array $array, string $prefix = ''): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }

        return $keys;
    }
}
