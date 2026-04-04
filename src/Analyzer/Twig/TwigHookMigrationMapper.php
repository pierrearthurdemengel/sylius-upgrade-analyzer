<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig;

/**
 * Table de correspondance entre les templates Twig Sylius 1.x et les hooks Twig Sylius 2.x.
 * Permet de déterminer le hook cible et le temps estimé de migration pour chaque template.
 */
final class TwigHookMigrationMapper
{
    /**
     * Correspondances exactes entre chemins de templates et hooks Sylius 2.x.
     * Format : chemin du template => [nom du hook, estimation en minutes]
     *
     * @var array<string, array{hook: string, minutes: int}>
     */
    private const MAPPING = [
        'SyliusShopBundle/layout.html.twig' => [
            'hook' => 'sylius_shop.base',
            'minutes' => 120,
        ],
        'SyliusShopBundle/Product/show.html.twig' => [
            'hook' => 'sylius_shop.product.show.content',
            'minutes' => 60,
        ],
        'SyliusAdminBundle/Order/show.html.twig' => [
            'hook' => 'sylius_admin.order.show.content',
            'minutes' => 60,
        ],
    ];

    /**
     * Correspondances par préfixe de répertoire pour les templates génériques.
     * Format : préfixe du chemin => [préfixe du hook, estimation en minutes]
     *
     * @var array<string, array{hookPrefix: string, minutes: int}>
     */
    private const PREFIX_MAPPING = [
        'SyliusShopBundle/Checkout/' => [
            'hookPrefix' => 'sylius_shop.checkout.',
            'minutes' => 120,
        ],
        'SyliusShopBundle/Cart/' => [
            'hookPrefix' => 'sylius_shop.cart.',
            'minutes' => 60,
        ],
        'SyliusShopBundle/Account/' => [
            'hookPrefix' => 'sylius_shop.account.',
            'minutes' => 60,
        ],
        'SyliusAdminBundle/Product/' => [
            'hookPrefix' => 'sylius_admin.product.',
            'minutes' => 60,
        ],
        'SyliusAdminBundle/Customer/' => [
            'hookPrefix' => 'sylius_admin.customer.',
            'minutes' => 60,
        ],
        'SyliusUiBundle/' => [
            'hookPrefix' => 'sylius_ui.',
            'minutes' => 60,
        ],
    ];

    /**
     * Recherche le hook Sylius 2.x correspondant à un chemin de template.
     * Vérifie d'abord les correspondances exactes, puis les préfixes.
     *
     * @param string $templatePath Chemin relatif du template (ex: SyliusShopBundle/Product/show.html.twig)
     * @return ?array{hook: string, minutes: int} Informations de correspondance ou null si aucune
     */
    public function mapTemplateToHook(string $templatePath): ?array
    {
        /* Normalisation du séparateur de chemin */
        $normalizedPath = str_replace('\\', '/', $templatePath);

        /* Vérification des correspondances exactes */
        if (isset(self::MAPPING[$normalizedPath])) {
            return self::MAPPING[$normalizedPath];
        }

        /* Vérification des correspondances par préfixe */
        foreach (self::PREFIX_MAPPING as $prefix => $config) {
            if (str_starts_with($normalizedPath, $prefix)) {
                /* Extraction du nom du template pour construire le hook */
                $relativePart = substr($normalizedPath, strlen($prefix));
                $hookSuffix = $this->buildHookSuffix($relativePart);

                return [
                    'hook' => $config['hookPrefix'] . $hookSuffix,
                    'minutes' => $config['minutes'],
                ];
            }
        }

        return null;
    }

    /**
     * Estime le temps de migration en minutes en fonction du contenu du template.
     * Trois niveaux de complexité :
     * - Simple (30 min) : uniquement extends et block
     * - Standard (60 min) : includes, macros, logique Twig
     * - Complexe (120 min) : JavaScript inline, Stimulus, appels de services
     *
     * @param string $templatePath Chemin du template
     * @param string $content      Contenu du fichier Twig
     * @return int Estimation en minutes
     */
    public function getComplexityMinutes(string $templatePath, string $content): int
    {
        /* Détection de complexité élevée : JS inline, Stimulus, appels de services */
        if ($this->isComplexTemplate($content)) {
            return 120;
        }

        /* Détection de complexité standard : includes, macros, logique Twig */
        if ($this->isStandardTemplate($content)) {
            return 60;
        }

        /* Template simple : uniquement extends et block */
        return 30;
    }

    /**
     * Détermine si un template est complexe (JS inline, Stimulus, appels de services).
     */
    private function isComplexTemplate(string $content): bool
    {
        $complexPatterns = [
            '/<script[\s>]/i',
            '/data-controller\s*=/i',
            '/stimulus_/i',
            '/\.controller\./i',
            '/importmap/i',
            '/asset\s*\(/i',
            '/webpack_encore/i',
            '/encore_entry/i',
        ];

        foreach ($complexPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détermine si un template est de complexité standard (includes, macros, logique Twig).
     */
    private function isStandardTemplate(string $content): bool
    {
        $standardPatterns = [
            '/\{%\s*include\s/i',
            '/\{%\s*macro\s/i',
            '/\{%\s*import\s/i',
            '/\{%\s*from\s/i',
            '/\{%\s*embed\s/i',
            '/\{%\s*for\s/i',
            '/\{%\s*if\s/i',
            '/\{%\s*set\s/i',
            '/\{%\s*apply\s/i',
            '/\|\s*filter/i',
        ];

        foreach ($standardPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Construit le suffixe du hook à partir du chemin relatif du template.
     * Transforme le nom du fichier en identifiant lisible (snake_case, sans extension).
     */
    private function buildHookSuffix(string $relativePath): string
    {
        /* Suppression de l'extension .html.twig ou .twig */
        $name = preg_replace('/\.html\.twig$|\.twig$/', '', $relativePath);

        /* Remplacement des séparateurs par des points */
        $name = str_replace('/', '.', (string) $name);

        /* Conversion du camelCase en snake_case */
        $name = (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);

        return strtolower($name);
    }
}
