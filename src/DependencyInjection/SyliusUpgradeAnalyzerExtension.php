<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\DependencyInjection;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Extension Symfony pour le bundle SyliusUpgradeAnalyzer.
 * Charge la configuration des services et enregistre l'autoconfiguration
 * des analyseurs avec le tag 'sylius_upgrade.analyzer'.
 */
final class SyliusUpgradeAnalyzerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /* Traitement de la configuration du bundle */
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        /* Chargement du fichier de services YAML */
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(\dirname(__DIR__, 2) . '/config'),
        );
        $loader->load('services.yaml');

        /* Enregistrement de l'autoconfiguration : chaque implementation de AnalyzerInterface recoit le tag */
        $container->registerForAutoconfiguration(AnalyzerInterface::class)
            ->addTag('sylius_upgrade.analyzer');

        /* Desactivation des analyseurs selon la configuration */
        $this->disableAnalyzers($container, $config);
    }

    /**
     * Desactive les analyseurs dont la categorie est desactivee dans la configuration.
     *
     * @param array<string, mixed> $config Configuration traitee du bundle
     */
    private function disableAnalyzers(ContainerBuilder $container, array $config): void
    {
        /* Correspondance entre cles de configuration et prefixes de namespace d'analyseur */
        $categoryMapping = [
            'twig' => 'PierreArthur\\SyliusUpgradeAnalyzer\\Analyzer\\Twig\\',
            'deprecation' => 'PierreArthur\\SyliusUpgradeAnalyzer\\Analyzer\\Deprecation\\',
            'plugin' => 'PierreArthur\\SyliusUpgradeAnalyzer\\Analyzer\\Plugin\\',
            'grid' => 'PierreArthur\\SyliusUpgradeAnalyzer\\Analyzer\\Grid\\',
            'resource' => 'PierreArthur\\SyliusUpgradeAnalyzer\\Analyzer\\Resource\\',
            'frontend' => 'PierreArthur\\SyliusUpgradeAnalyzer\\Analyzer\\Frontend\\',
            'api' => 'PierreArthur\\SyliusUpgradeAnalyzer\\Analyzer\\ApiPlatform\\',
        ];

        foreach ($categoryMapping as $key => $namespace) {
            if (($config[$key] ?? true) === false) {
                /* Suppression de tous les services dont le FQCN commence par le namespace de la categorie */
                foreach ($container->getDefinitions() as $id => $definition) {
                    $class = $definition->getClass() ?? $id;
                    if (str_starts_with($class, $namespace)) {
                        $container->removeDefinition($id);
                    }
                }
            }
        }
    }
}
