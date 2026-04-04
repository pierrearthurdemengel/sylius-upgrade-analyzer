<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration du bundle SyliusUpgradeAnalyzer.
 * Permet d'activer ou desactiver chaque categorie d'analyseur individuellement.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sylius_upgrade_analyzer');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('twig')
                    ->defaultTrue()
                    ->info('Activer l\'analyseur de templates Twig et hooks')
                ->end()
                ->booleanNode('deprecation')
                    ->defaultTrue()
                    ->info('Activer les analyseurs de depreciations (SwiftMailer, Payum, winzou, encoders)')
                ->end()
                ->booleanNode('plugin')
                    ->defaultTrue()
                    ->info('Activer l\'analyseur de compatibilite des plugins')
                ->end()
                ->booleanNode('grid')
                    ->defaultTrue()
                    ->info('Activer l\'analyseur de personnalisations de grilles')
                ->end()
                ->booleanNode('resource')
                    ->defaultTrue()
                    ->info('Activer l\'analyseur du systeme de ressources')
                ->end()
                ->booleanNode('frontend')
                    ->defaultTrue()
                    ->info('Activer les analyseurs front-end (Semantic UI, jQuery, Webpack Encore)')
                ->end()
                ->booleanNode('api')
                    ->defaultTrue()
                    ->info('Activer l\'analyseur de migration API Platform')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
