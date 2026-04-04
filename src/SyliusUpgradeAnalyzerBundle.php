<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer;

use PierreArthur\SyliusUpgradeAnalyzer\DependencyInjection\Compiler\AnalyzerCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Bundle Symfony pour l'outil d'analyse de migration Sylius.
 * Enregistre les services, les analyseurs et les reporters via l'injection de dependances.
 */
final class SyliusUpgradeAnalyzerBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        /* Enregistrement du compiler pass pour collecter les analyseurs taggues */
        $container->addCompilerPass(new AnalyzerCompilerPass());
    }
}
