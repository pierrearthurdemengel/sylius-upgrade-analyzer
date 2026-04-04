<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\DependencyInjection\Compiler\AnalyzerCompilerPass;
use PierreArthur\SyliusUpgradeAnalyzer\DependencyInjection\Configuration;
use PierreArthur\SyliusUpgradeAnalyzer\DependencyInjection\SyliusUpgradeAnalyzerExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests d'integration pour le bundle Symfony SyliusUpgradeAnalyzer.
 * Verifie le chargement des services, la configuration par defaut
 * et le compiler pass de collecte des analyseurs.
 */
final class BundleIntegrationTest extends TestCase
{
    /**
     * Verifie que l'extension charge correctement les services.
     * L'autoconfiguration doit taguer les implementations de AnalyzerInterface
     * avec le tag 'sylius_upgrade.analyzer'.
     */
    #[Test]
    public function testExtensionLoadsServices(): void
    {
        $container = new ContainerBuilder();

        /* Enregistrement des parametres requis par le framework */
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);

        $extension = new SyliusUpgradeAnalyzerExtension();
        $extension->load([], $container);

        /* Verification de l'autoconfiguration pour AnalyzerInterface */
        $autoconfigured = $container->getAutoconfiguredInstanceof();
        self::assertArrayHasKey(
            AnalyzerInterface::class,
            $autoconfigured,
            'L\'interface AnalyzerInterface devrait etre autoconfiguree.',
        );

        /* Verification que le tag est bien defini sur l'autoconfiguration */
        $tags = $autoconfigured[AnalyzerInterface::class]->getTags();
        self::assertArrayHasKey(
            'sylius_upgrade.analyzer',
            $tags,
            'L\'autoconfiguration devrait ajouter le tag sylius_upgrade.analyzer.',
        );
    }

    /**
     * Verifie que la configuration du bundle a les bonnes valeurs par defaut.
     * Toutes les categories d'analyseurs doivent etre activees par defaut.
     */
    #[Test]
    public function testConfigurationHasCorrectDefaults(): void
    {
        $configuration = new Configuration();
        $processor = new Processor();

        /* Traitement avec une configuration vide pour obtenir les defauts */
        $config = $processor->processConfiguration($configuration, []);

        /* Toutes les categories doivent etre activees par defaut */
        $expectedCategories = ['twig', 'deprecation', 'plugin', 'grid', 'resource', 'frontend', 'api'];

        foreach ($expectedCategories as $category) {
            self::assertArrayHasKey($category, $config, sprintf('La categorie "%s" devrait exister dans la configuration.', $category));
            self::assertTrue($config[$category], sprintf('La categorie "%s" devrait etre activee par defaut.', $category));
        }
    }

    /**
     * Verifie que le compiler pass collecte les analyseurs taggues
     * et les injecte dans la commande AnalyzeCommand.
     */
    #[Test]
    public function testCompilerPassCollectsAnalyzers(): void
    {
        $container = new ContainerBuilder();

        /* Enregistrement de la commande AnalyzeCommand dans le conteneur */
        $analyzeCommandClass = 'PierreArthur\\SyliusUpgradeAnalyzer\\Command\\AnalyzeCommand';
        $commandDefinition = new Definition($analyzeCommandClass);
        $commandDefinition->setArguments([[], []]);
        $container->setDefinition($analyzeCommandClass, $commandDefinition);

        /* Enregistrement de deux analyseurs fictifs taggues */
        $analyzerDef1 = new Definition('App\\Analyzer\\FakeAnalyzer1');
        $analyzerDef1->addTag('sylius_upgrade.analyzer');
        $container->setDefinition('App\\Analyzer\\FakeAnalyzer1', $analyzerDef1);

        $analyzerDef2 = new Definition('App\\Analyzer\\FakeAnalyzer2');
        $analyzerDef2->addTag('sylius_upgrade.analyzer');
        $container->setDefinition('App\\Analyzer\\FakeAnalyzer2', $analyzerDef2);

        /* Execution du compiler pass */
        $compilerPass = new AnalyzerCompilerPass();
        $compilerPass->process($container);

        /* Verification que les analyseurs ont ete injectes comme premier argument */
        $resultDefinition = $container->getDefinition($analyzeCommandClass);
        $firstArgument = $resultDefinition->getArgument(0);

        self::assertIsArray($firstArgument, 'Le premier argument devrait etre un tableau de references.');
        self::assertCount(2, $firstArgument, 'Deux analyseurs devraient etre injectes.');

        /* Verification que les references pointent vers les bons services */
        foreach ($firstArgument as $reference) {
            self::assertInstanceOf(Reference::class, $reference, 'Chaque element devrait etre une Reference.');
        }
    }
}
