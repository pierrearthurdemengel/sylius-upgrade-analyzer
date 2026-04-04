<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\DependencyInjection\Compiler;

use PierreArthur\SyliusUpgradeAnalyzer\Command\AnalyzeCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass qui collecte tous les services taggues 'sylius_upgrade.analyzer'
 * et les injecte dans la commande AnalyzeCommand en tant que premier argument du constructeur.
 */
final class AnalyzerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /* Verification que la commande est bien enregistree dans le conteneur */
        if (!$container->hasDefinition(AnalyzeCommand::class)) {
            return;
        }

        $commandDefinition = $container->getDefinition(AnalyzeCommand::class);

        /* Collecte de tous les services taggues 'sylius_upgrade.analyzer' */
        $taggedServices = $container->findTaggedServiceIds('sylius_upgrade.analyzer');

        $analyzerReferences = [];
        foreach (array_keys($taggedServices) as $serviceId) {
            $analyzerReferences[] = new Reference($serviceId);
        }

        /* Injection des analyseurs comme premier argument du constructeur */
        $commandDefinition->setArgument(0, $analyzerReferences);
    }
}
