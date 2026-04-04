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
 * Analyseur des decorateurs de services Sylius.
 * Sylius 2.0 renomme certaines interfaces internes. Les decorateurs de services
 * referencant des interfaces renommees doivent etre mis a jour.
 */
final class ServiceDecoratorAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par decorateur impacte */
    private const MINUTES_PER_DECORATOR = 120;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Mapping des interfaces renommees : ancien nom => nouveau nom */
    private const RENAMED_INTERFACES = [
        'RepositoryInterface' => 'ZoneRepositoryInterface',
        'ProductVariantPriceCalculatorInterface' => 'ProductVariantPricesCalculatorInterface',
    ];

    public function getName(): string
    {
        return 'Service Decorator';
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
            if (str_contains($content, 'decorates:')) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return;
        }

        $decoratorCount = 0;

        /* Etape 1 : analyse des fichiers YAML pour les decorateurs de services Sylius */
        $decoratorCount += $this->analyzeYamlDecorators($report, $projectPath);

        /* Etape 2 : resume global avec estimation */
        if ($decoratorCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d decorateur(s) de services Sylius detecte(s) pouvant etre impactes',
                    $decoratorCount,
                ),
                detail: 'Les decorateurs de services Sylius peuvent etre impactes par les renommages '
                    . 'd\'interfaces dans Sylius 2.0. Les interfaces RepositoryInterface et '
                    . 'ProductVariantPriceCalculatorInterface ont ete renommees.',
                suggestion: 'Verifier chaque decorateur et mettre a jour les references '
                    . 'aux interfaces renommees.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $decoratorCount * self::MINUTES_PER_DECORATOR,
            ));
        }
    }

    /**
     * Analyse les fichiers YAML dans config/ pour detecter les decorateurs de services Sylius.
     * Retourne le nombre de decorateurs impactes.
     */
    private function analyzeYamlDecorators(MigrationReport $report, string $projectPath): int
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

            try {
                $config = Yaml::parseFile($filePath);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($config)) {
                continue;
            }

            /* Parcours des definitions de services */
            $services = $config['services'] ?? [];
            if (!is_array($services)) {
                continue;
            }

            foreach ($services as $serviceId => $serviceConfig) {
                if (!is_array($serviceConfig) || !isset($serviceConfig['decorates'])) {
                    continue;
                }

                $decoratedService = (string) $serviceConfig['decorates'];

                /* Verification si le service decore est un service Sylius */
                if (!str_contains($decoratedService, 'sylius') && !str_contains($decoratedService, 'Sylius')) {
                    continue;
                }

                $count++;
                $isRenamedInterface = false;
                $renamedInfo = '';

                /* Verification si le decorateur reference une interface renommee */
                foreach (self::RENAMED_INTERFACES as $oldName => $newName) {
                    if (str_contains($decoratedService, $oldName)) {
                        $isRenamedInterface = true;
                        $renamedInfo = sprintf(' (interface renommee : %s -> %s)', $oldName, $newName);
                        break;
                    }
                }

                $severity = $isRenamedInterface ? Severity::WARNING : Severity::WARNING;

                $report->addIssue(new MigrationIssue(
                    severity: $severity,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Decorateur "%s" decore le service Sylius "%s"%s',
                        $serviceId,
                        $decoratedService,
                        $renamedInfo,
                    ),
                    detail: sprintf(
                        'Le service "%s" defini dans %s decore le service Sylius "%s". '
                        . 'Ce decorateur peut etre impacte par les changements de Sylius 2.0.%s',
                        $serviceId,
                        $file->getRelativePathname(),
                        $decoratedService,
                        $isRenamedInterface
                            ? ' L\'interface decoree a ete renommee.'
                            : '',
                    ),
                    suggestion: $isRenamedInterface
                        ? sprintf(
                            'Mettre a jour la reference du service decore pour utiliser la nouvelle interface. %s',
                            $renamedInfo,
                        )
                        : 'Verifier que le service decore existe toujours dans Sylius 2.0 '
                            . 'et que sa signature n\'a pas change.',
                    file: $filePath,
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }
}
