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
 * Analyseur des identifiants de services Sylius renommes ou supprimes.
 * Sylius 2.0 renomme et supprime de nombreux identifiants de services.
 * Cet analyseur detecte les references aux anciens identifiants dans les fichiers YAML de config/.
 */
final class RenamedServiceIdAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par service renomme ou supprime */
    private const MINUTES_PER_SERVICE = 30;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /**
     * Correspondance entre anciens et nouveaux identifiants de services.
     * La valeur 'removed' ou 'removed (...)' indique un service supprime sans equivalent direct.
     *
     * @var array<string, string>
     */
    private const SERVICE_RENAMES = [
        'sylius.twig.extension.sort_by' => 'sylius_twig_extra.twig.extension.sort_by',
        'sylius.twig.extension.template_event' => 'removed (use Twig Hooks)',
        'sylius.province_naming_provider' => 'sylius.provider.province_naming',
        'sylius.zone_matcher' => 'sylius.matcher.zone',
        'sylius.address_comparator' => 'sylius.comparator.address',
        'sylius.controller.admin.dashboard' => 'sylius_admin.controller.dashboard',
        'sylius.controller.shop.contact' => 'sylius_shop.controller.contact',
        'sylius.controller.shop.currency_switch' => 'sylius_shop.controller.currency_switch',
        'sylius.controller.shop.locale_switch' => 'sylius_shop.controller.locale_switch',
        'sylius.controller.shop.register_thank_you' => 'sylius_shop.controller.register_thank_you',
        'sylius.security.password_hasher' => 'removed',
        'sylius.security.user_login' => 'removed',
        'sylius.controller.admin.notification' => 'removed',
        'sylius.dashboard.statistics_provider' => 'removed',
        'sylius.grid_filter.entities' => 'removed (use entity filter)',
        'sylius.payum_action.paypal_express_checkout.convert_payment' => 'removed',
        'sylius.controller.payum' => 'removed',
        'sylius.form.type.gateway_configuration.stripe' => 'removed',
        'sylius.twig.extension.taxes' => 'removed',
        'sylius.email_manager.shipment' => 'removed (moved to CoreBundle)',
        'sylius.email_manager.contact' => 'removed (moved to CoreBundle)',
        'sylius.email_manager.order' => 'removed',
        'sylius.http_message_factory' => 'removed',
        'sylius.form.type.product_option_choice' => 'removed',
        'sylius.form_registry.payum_gateway_config' => 'sylius.form_registry.payment_gateway_config',
        'sylius.admin.menu_builder.customer.show' => 'removed',
        'sylius.admin.menu_builder.order.show' => 'removed',
    ];

    /**
     * Espaces de noms utilises comme identifiants de services dans les fichiers YAML.
     * Ces references doivent etre remplacees par des identifiants prefixes dans Sylius 2.0.
     *
     * @var array<string, string>
     */
    private const SERVICE_NAMESPACE_RENAMES = [
        'Sylius\\Bundle\\ApiBundle\\' => 'sylius_api.*',
    ];

    public function getName(): string
    {
        return 'Renamed Service ID';
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

            foreach (array_keys(self::SERVICE_RENAMES) as $oldServiceId) {
                if (str_contains($content, $oldServiceId)) {
                    return true;
                }
            }

            foreach (array_keys(self::SERVICE_NAMESPACE_RENAMES) as $oldNamespace) {
                if (str_contains($content, $oldNamespace)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $referenceCount = 0;

        /* Etape 1 : analyse des identifiants exacts dans les fichiers YAML de config/ */
        $referenceCount += $this->analyzeYamlFiles($report, $projectPath);

        /* Etape 2 : analyse des prefixes de services dans les fichiers YAML de config/ */
        $referenceCount += $this->scanForPrefixPatterns($report, $projectPath);

        /* Etape 3 : ajout d'un probleme de synthese si des references sont detectees */
        if ($referenceCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d reference(s) a des identifiants de services renommes ou supprimes detectee(s)',
                    $referenceCount,
                ),
                detail: 'Les identifiants de services Sylius ont ete renommes ou supprimes dans Sylius 2.0. '
                    . 'Chaque reference doit etre mise a jour ou remplacee par une alternative.',
                suggestion: 'Mettre a jour chaque identifiant de service selon le tableau de correspondance '
                    . 'de la documentation de migration Sylius 2.0.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $referenceCount * self::MINUTES_PER_SERVICE,
            ));
        }
    }

    /**
     * Analyse les fichiers YAML dans config/ pour les anciens identifiants de services.
     * Retourne le nombre de references trouvees.
     */
    private function analyzeYamlFiles(MigrationReport $report, string $projectPath): int
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
            $lines = explode("\n", $content);

            foreach (self::SERVICE_RENAMES as $oldServiceId => $newServiceId) {
                /* Recherche de chaque ancien identifiant dans le contenu du fichier */
                if (!str_contains($content, $oldServiceId)) {
                    continue;
                }

                /* Identification de la ligne exacte pour un meilleur diagnostic */
                foreach ($lines as $index => $line) {
                    if (str_contains($line, $oldServiceId)) {
                        $count++;
                        $lineNumber = $index + 1;
                        $isRemoved = str_starts_with($newServiceId, 'removed');

                        $report->addIssue(new MigrationIssue(
                            severity: Severity::BREAKING,
                            category: Category::DEPRECATION,
                            analyzer: $this->getName(),
                            message: sprintf(
                                'Identifiant de service obsolete "%s" detecte dans %s',
                                $oldServiceId,
                                $file->getRelativePathname(),
                            ),
                            detail: sprintf(
                                'Le service "%s" est %s dans Sylius 2.0. '
                                . 'Reference trouvee dans %s ligne %d.',
                                $oldServiceId,
                                $isRemoved ? 'supprime' : sprintf('renomme en "%s"', $newServiceId),
                                $file->getRelativePathname(),
                                $lineNumber,
                            ),
                            suggestion: $isRemoved
                                ? sprintf(
                                    'Le service "%s" a ete supprime (%s). '
                                    . 'Trouver une alternative ou supprimer la reference.',
                                    $oldServiceId,
                                    $newServiceId,
                                )
                                : sprintf(
                                    'Remplacer "%s" par "%s".',
                                    $oldServiceId,
                                    $newServiceId,
                                ),
                            file: $filePath,
                            line: $lineNumber,
                            codeSnippet: trim($line),
                            docUrl: self::DOC_URL,
                        ));
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Analyse les fichiers YAML dans config/ pour les prefixes de services deprecies
     * et les espaces de noms utilises comme identifiants de services.
     * Retourne le nombre de references trouvees.
     */
    private function scanForPrefixPatterns(MigrationReport $report, string $projectPath): int
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
            $relativePath = $file->getRelativePathname();

            /* Verification des espaces de noms utilises comme identifiants de services */
            foreach (self::SERVICE_NAMESPACE_RENAMES as $oldNamespace => $newPattern) {
                if (str_contains($content, $oldNamespace)) {
                    $count++;
                    $report->addIssue(new MigrationIssue(
                        severity: Severity::BREAKING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf(
                            'Espace de noms utilise comme identifiant de service "%s" detecte dans %s',
                            $oldNamespace,
                            $relativePath,
                        ),
                        detail: sprintf(
                            'L\'espace de noms "%s" utilise comme identifiant de service YAML '
                            . 'doit etre remplace par un identifiant prefixe "%s" dans Sylius 2.0.',
                            $oldNamespace,
                            $newPattern,
                        ),
                        suggestion: sprintf(
                            'Remplacer les references a "%s" par des identifiants prefixes "%s".',
                            $oldNamespace,
                            $newPattern,
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
