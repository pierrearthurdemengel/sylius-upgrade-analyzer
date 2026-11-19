<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\TemplateDirectoryLocator;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur des routes Sylius supprimees.
 * Sylius 2.0 supprime de nombreuses routes AJAX et partielles du panneau d'administration et de la boutique.
 * Cet analyseur detecte les references a ces routes dans les fichiers PHP et les templates Twig.
 */
final class RemovedRouteAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par reference de route supprimee */
    private const MINUTES_PER_ROUTE = 30;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Liste des routes supprimees dans Sylius 2.0 */
    private const REMOVED_ROUTES = [
        'sylius_admin_dashboard_statistics',
        'sylius_admin_ajax_all_product_variants_by_codes',
        'sylius_admin_ajax_all_product_variants_by_phrase',
        'sylius_admin_ajax_find_product_options',
        'sylius_admin_ajax_generate_product_slug',
        'sylius_admin_ajax_generate_taxon_slug',
        'sylius_admin_ajax_product_by_code',
        'sylius_admin_ajax_product_by_name_phrase',
        'sylius_admin_ajax_product_index',
        'sylius_admin_ajax_products_by_phrase',
        'sylius_admin_ajax_product_variants_by_codes',
        'sylius_admin_ajax_product_variants_by_phrase',
        'sylius_admin_ajax_taxon_by_code',
        'sylius_admin_ajax_taxon_by_name_phrase',
        'sylius_admin_ajax_taxon_leafs',
        'sylius_admin_ajax_taxon_root_nodes',
        'sylius_admin_get_attribute_types',
        'sylius_admin_get_payment_gateways',
        'sylius_admin_get_product_attributes',
        'sylius_admin_partial_address_log_entry_index',
        'sylius_admin_partial_catalog_promotion_show',
        'sylius_admin_partial_channel_index',
        'sylius_admin_partial_customer_latest',
        'sylius_admin_partial_customer_show',
        'sylius_admin_partial_order_latest',
        'sylius_admin_partial_order_latest_in_channel',
        'sylius_admin_partial_product_show',
        'sylius_admin_partial_promotion_show',
        'sylius_admin_partial_taxon_show',
        'sylius_admin_partial_taxon_tree',
        'sylius_admin_render_attribute_forms',
        'sylius_shop_ajax_cart_add_item',
        'sylius_shop_ajax_cart_item_remove',
        'sylius_shop_ajax_user_check_action',
        'sylius_shop_partial_cart_summary',
        'sylius_shop_partial_cart_add_item',
        'sylius_shop_partial_channel_menu_taxon_index',
        'sylius_shop_partial_product_association_show',
        'sylius_shop_partial_product_index_latest',
        'sylius_shop_partial_product_review_latest',
        'sylius_shop_partial_product_show_by_slug',
        'sylius_shop_partial_taxon_index_by_code',
        'sylius_shop_partial_taxon_show_by_slug',
    ];

    public function getName(): string
    {
        return 'Removed Route';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        $dirs = [];
        if (is_dir($projectPath . '/src')) {
            $dirs[] = $projectPath . '/src';
        }
        /* templates/ et themes : les routes partielles du shop sont souvent appelees depuis un theme */
        foreach (TemplateDirectoryLocator::locate($projectPath) as $templateDir) {
            $dirs[] = $templateDir;
        }

        if ($dirs === []) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($dirs)->exclude(TemplateDirectoryLocator::EXCLUDED_DIRECTORIES)->name('*.php')->name('*.html.twig');

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());
            foreach (self::REMOVED_ROUTES as $route) {
                if (str_contains($content, $route)) {
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

        /* Etape 1 : analyse des fichiers PHP dans src/ */
        $referenceCount += $this->analyzePhpFiles($report, $projectPath);

        /* Etape 2 : analyse des templates Twig dans templates/ */
        $referenceCount += $this->analyzeTwigFiles($report, $projectPath);

        /* Etape 3 : ajout d'un probleme de synthese */
        if ($referenceCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d reference(s) a des routes supprimees detectee(s)',
                    $referenceCount,
                ),
                detail: 'Sylius 2.0 supprime les routes AJAX et partielles de l\'administration et de la boutique. '
                    . 'Ces routes doivent etre remplacees par les nouvelles API ou les composants Twig Hooks.',
                suggestion: 'Consulter la documentation de migration Sylius 2.0 pour trouver '
                    . 'les alternatives a chaque route supprimee.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $referenceCount * self::MINUTES_PER_ROUTE,
            ));
        }
    }

    /**
     * Analyse les fichiers PHP dans src/ pour les references aux routes supprimees.
     * Retourne le nombre de references trouvees.
     */
    private function analyzePhpFiles(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $count = 0;
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            $lines = explode("\n", $content);

            foreach (self::REMOVED_ROUTES as $route) {
                if (!str_contains($content, $route)) {
                    continue;
                }

                /* Identification de la ligne contenant la route */
                foreach ($lines as $index => $line) {
                    if (str_contains($line, $route)) {
                        $count++;
                        $lineNumber = $index + 1;

                        $report->addIssue(new MigrationIssue(
                            severity: Severity::BREAKING,
                            category: Category::DEPRECATION,
                            analyzer: $this->getName(),
                            message: sprintf(
                                'Route supprimee "%s" referencee dans %s',
                                $route,
                                $file->getRelativePathname(),
                            ),
                            detail: sprintf(
                                'La route "%s" a ete supprimee dans Sylius 2.0. '
                                . 'Reference trouvee dans %s ligne %d.',
                                $route,
                                $file->getRelativePathname(),
                                $lineNumber,
                            ),
                            suggestion: sprintf(
                                'Remplacer la reference a la route "%s" par l\'alternative Sylius 2.0 '
                                . '(nouvelle API ou composant Twig Hooks).',
                                $route,
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
     * Analyse les templates Twig de templates/ et des themes pour les appels path() et url()
     * vers des routes supprimees.
     * Retourne le nombre de references trouvees.
     */
    private function analyzeTwigFiles(MigrationReport $report, string $projectPath): int
    {
        $finder = TemplateDirectoryLocator::createTwigFinder($projectPath, ['*.html.twig']);
        if ($finder === null) {
            return 0;
        }

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            $lines = explode("\n", $content);
            $label = TemplateDirectoryLocator::templateLabel($projectPath, $filePath);

            foreach (self::REMOVED_ROUTES as $route) {
                if (!str_contains($content, $route)) {
                    continue;
                }

                /* Detection des appels path('route') et url('route') dans les templates */
                foreach ($lines as $index => $line) {
                    $pathPattern = sprintf('/(?:path|url)\s*\(\s*[\'"]%s[\'"]\s*/', preg_quote($route, '/'));
                    if (preg_match($pathPattern, $line) !== 1) {
                        continue;
                    }

                    $count++;
                    $lineNumber = $index + 1;

                    $report->addIssue(new MigrationIssue(
                        severity: Severity::BREAKING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf(
                            'Route supprimee "%s" utilisee dans le template %s',
                            $route,
                            $label,
                        ),
                        detail: sprintf(
                            'Le template %s utilise la route "%s" (ligne %d) '
                            . 'qui a ete supprimee dans Sylius 2.0.',
                            $label,
                            $route,
                            $lineNumber,
                        ),
                        suggestion: sprintf(
                            'Remplacer l\'appel path(\'%s\') ou url(\'%s\') par l\'alternative Sylius 2.0.',
                            $route,
                            $route,
                        ),
                        file: $filePath,
                        line: $lineNumber,
                        codeSnippet: trim($line),
                        docUrl: self::DOC_URL,
                    ));
                }
            }
        }

        return $count;
    }
}
