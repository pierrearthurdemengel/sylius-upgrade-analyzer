<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Resource;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Analyseur du systeme de ressources Sylius (SyliusResourceBundle).
 * Detecte les definitions de ressources personnalisees en YAML,
 * les implementations de ResourceInterface, les factories et repositories
 * personnalises, et les surcharges de services Sylius.
 */
final class ResourceBundleAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par ressource personnalisee avec factory/repository */
    private const MINUTES_PER_RESOURCE = 120;

    /** Estimation en minutes par surcharge de service */
    private const MINUTES_PER_SERVICE_OVERRIDE = 60;

    /** URL de la documentation sur les ressources Sylius 2.x */
    private const DOC_URL = 'https://docs.sylius.com/en/latest/components_and_bundles/bundles/SyliusResourceBundle/index.html';

    /** Interfaces de ressources recherchees dans le code source */
    private const RESOURCE_INTERFACES = [
        'ResourceInterface',
        'Sylius\\Component\\Resource\\Model\\ResourceInterface',
    ];

    /** Interfaces de factory recherchees */
    private const FACTORY_INTERFACES = [
        'FactoryInterface',
        'Sylius\\Component\\Resource\\Factory\\FactoryInterface',
    ];

    /** Classes de repository Sylius */
    private const REPOSITORY_CLASSES = [
        'EntityRepository',
        'Sylius\\Bundle\\ResourceBundle\\Doctrine\\ORM\\EntityRepository',
    ];

    /** Prefixes de services Sylius courants dans les surcharges */
    private const SYLIUS_SERVICE_PREFIXES = [
        'sylius.repository.',
        'sylius.factory.',
        'sylius.manager.',
        'sylius.controller.',
        'sylius.form.',
    ];

    public function getName(): string
    {
        return 'Resource Bundle';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification des fichiers YAML de configuration de ressources */
        if ($this->hasResourceYamlConfig($projectPath)) {
            return true;
        }

        /* Verification des classes PHP liees aux ressources */
        if ($this->hasResourcePhpClasses($projectPath)) {
            return true;
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        /* Etape 1 : analyse des definitions de ressources YAML */
        $this->analyzeYamlResourceDefinitions($report, $projectPath);

        /* Etape 2 : analyse des implementations de ResourceInterface */
        $this->analyzeResourceImplementations($report, $projectPath);

        /* Etape 3 : analyse des implementations de FactoryInterface */
        $this->analyzeFactoryImplementations($report, $projectPath);

        /* Etape 4 : analyse des extensions de EntityRepository */
        $this->analyzeRepositoryExtensions($report, $projectPath);

        /* Etape 5 : analyse des surcharges de services Sylius */
        $this->analyzeServiceOverrides($report, $projectPath);
    }

    /**
     * Verifie si le projet contient des fichiers YAML avec la cle sylius_resource.
     */
    private function hasResourceYamlConfig(string $projectPath): bool
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

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

            if (is_array($config) && isset($config['sylius_resource'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifie si le projet contient des classes PHP implementant ResourceInterface.
     */
    private function hasResourcePhpClasses(string $projectPath): bool
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);

            if (str_contains($content, 'ResourceInterface')
                || str_contains($content, 'FactoryInterface')
                || str_contains($content, 'EntityRepository')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyse les fichiers YAML pour detecter les definitions de ressources sylius_resource.
     */
    private function analyzeYamlResourceDefinitions(MigrationReport $report, string $projectPath): void
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

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

            if (!is_array($config) || !isset($config['sylius_resource'])) {
                continue;
            }

            $resourceConfig = $config['sylius_resource'];
            if (!is_array($resourceConfig)) {
                continue;
            }

            /* Extraction des definitions de ressources */
            $resources = $resourceConfig['resources'] ?? [];
            if (!is_array($resources) || count($resources) === 0) {
                continue;
            }

            $resourceCount = count($resources);
            $resourceNames = array_keys($resources);

            /* Determination de la complexite : verification des factories et repositories */
            $hasCustomFactories = false;
            $hasCustomRepositories = false;
            foreach ($resources as $resourceDef) {
                if (!is_array($resourceDef)) {
                    continue;
                }
                $classes = $resourceDef['classes'] ?? [];
                if (!is_array($classes)) {
                    continue;
                }
                if (isset($classes['factory'])) {
                    $hasCustomFactories = true;
                }
                if (isset($classes['repository'])) {
                    $hasCustomRepositories = true;
                }
            }

            $complexityDetail = '';
            if ($hasCustomFactories && $hasCustomRepositories) {
                $complexityDetail = ' Certaines definissent des factories et repositories personnalises.';
            } elseif ($hasCustomFactories) {
                $complexityDetail = ' Certaines definissent des factories personnalises.';
            } elseif ($hasCustomRepositories) {
                $complexityDetail = ' Certaines definissent des repositories personnalises.';
            }

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::RESOURCE,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d definition(s) de ressource(s) YAML detectee(s) dans %s',
                    $resourceCount,
                    $file->getRelativePathname(),
                ),
                detail: sprintf(
                    'Les ressources suivantes sont definies en YAML : %s.%s '
                    . 'Ces definitions devront etre adaptees pour le systeme de ressources de Sylius 2.x.',
                    implode(', ', array_map(fn ($name) => is_string($name) ? $name : (string) $name, $resourceNames)),
                    $complexityDetail,
                ),
                suggestion: 'Migrer les definitions de ressources YAML vers le nouveau format '
                    . 'de configuration de Sylius 2.x. Verifier que les classes model, factory '
                    . 'et repository restent compatibles avec la nouvelle API.',
                file: $filePath,
                docUrl: self::DOC_URL,
                estimatedMinutes: $resourceCount * self::MINUTES_PER_RESOURCE,
            ));
        }
    }

    /**
     * Analyse les fichiers PHP pour detecter les implementations de ResourceInterface.
     */
    private function analyzeResourceImplementations(MigrationReport $report, string $projectPath): void
    {
        $this->analyzePhpImplementations(
            $report,
            $projectPath,
            self::RESOURCE_INTERFACES,
            'ResourceInterface',
            'ressource',
            'Verifier que le modele de ressource est compatible avec la nouvelle API '
            . 'de SyliusResourceBundle 2.x. Adapter les getters/setters si necessaire.',
        );
    }

    /**
     * Analyse les fichiers PHP pour detecter les implementations de FactoryInterface.
     */
    private function analyzeFactoryImplementations(MigrationReport $report, string $projectPath): void
    {
        $this->analyzePhpImplementations(
            $report,
            $projectPath,
            self::FACTORY_INTERFACES,
            'FactoryInterface',
            'factory',
            'Adapter la factory personnalisee pour la nouvelle API de SyliusResourceBundle 2.x. '
            . 'Verifier la signature de la methode createNew() et les eventuelles injections.',
        );
    }

    /**
     * Analyse les fichiers PHP pour detecter les extensions de EntityRepository.
     */
    private function analyzeRepositoryExtensions(MigrationReport $report, string $projectPath): void
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            /* Filtre rapide */
            if (!str_contains($code, 'EntityRepository')) {
                continue;
            }

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $repositoryClasses = self::REPOSITORY_CLASSES;

            $visitor = new class ($repositoryClasses) extends NodeVisitorAbstract {
                /** @var list<array{name: string, line: int}> */
                public array $repositories = [];

                /** @param list<string> $targetClasses */
                public function __construct(private readonly array $targetClasses)
                {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_) {
                        return null;
                    }

                    if ($node->extends === null) {
                        return null;
                    }

                    $className = $node->name?->toString() ?? 'anonyme';
                    $extendsName = $node->extends->toString();

                    foreach ($this->targetClasses as $targetClass) {
                        if ($extendsName === $targetClass
                            || str_ends_with($extendsName, '\\' . $targetClass)
                        ) {
                            $this->repositories[] = [
                                'name' => $className,
                                'line' => $node->getStartLine(),
                            ];

                            return null;
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->repositories as $repository) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::RESOURCE,
                    analyzer: $this->getName(),
                    message: sprintf('Repository personnalise detecte : %s', $repository['name']),
                    detail: sprintf(
                        'La classe %s dans %s (ligne %d) etend EntityRepository de Sylius. '
                        . 'Ce repository personnalise devra etre adapte pour la nouvelle couche '
                        . 'de persistance de Sylius 2.x.',
                        $repository['name'],
                        $file->getRelativePathname(),
                        $repository['line'],
                    ),
                    suggestion: 'Adapter le repository personnalise pour la nouvelle API de '
                        . 'SyliusResourceBundle 2.x. Verifier les methodes de requete '
                        . 'et la compatibilite avec les changements Doctrine.',
                    file: $filePath,
                    line: $repository['line'],
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_PER_RESOURCE,
                ));
            }
        }
    }

    /**
     * Analyse les fichiers YAML de services pour detecter les surcharges de services Sylius.
     * Recherche les decorateurs et alias de services sylius.repository.*, sylius.factory.*, etc.
     */
    private function analyzeServiceOverrides(MigrationReport $report, string $projectPath): void
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

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

            $services = $config['services'] ?? [];
            if (!is_array($services)) {
                continue;
            }

            foreach ($services as $serviceId => $serviceDef) {
                if (!is_string($serviceId)) {
                    continue;
                }

                /* Detection des surcharges directes de services Sylius */
                $isSyliusService = false;
                foreach (self::SYLIUS_SERVICE_PREFIXES as $prefix) {
                    if (str_starts_with($serviceId, $prefix)) {
                        $isSyliusService = true;
                        break;
                    }
                }

                if (!$isSyliusService) {
                    /* Detection des decorateurs de services Sylius */
                    if (is_array($serviceDef)) {
                        $decorates = $serviceDef['decorates'] ?? null;
                        if (is_string($decorates)) {
                            foreach (self::SYLIUS_SERVICE_PREFIXES as $prefix) {
                                if (str_starts_with($decorates, $prefix)) {
                                    $isSyliusService = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                if (!$isSyliusService) {
                    continue;
                }

                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::RESOURCE,
                    analyzer: $this->getName(),
                    message: sprintf('Surcharge de service Sylius detectee : %s', $serviceId),
                    detail: sprintf(
                        'Le service %s dans %s surcharge ou decore un service Sylius natif. '
                        . 'Cette surcharge devra etre verifiee et potentiellement adaptee '
                        . 'pour la nouvelle architecture de services de Sylius 2.x.',
                        $serviceId,
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Verifier que cette surcharge de service est toujours necessaire '
                        . 'dans Sylius 2.x. Les identifiants de services et les interfaces '
                        . 'peuvent avoir change. Adapter la configuration en consequence.',
                    file: $filePath,
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_PER_SERVICE_OVERRIDE,
                ));
            }
        }
    }

    /**
     * Methode generique pour analyser les implementations d'interfaces PHP.
     *
     * @param list<string> $interfaces     Liste des noms d'interfaces a rechercher
     * @param string       $interfaceLabel Nom court de l'interface pour les messages
     * @param string       $componentLabel Label du composant pour les messages
     * @param string       $suggestion     Suggestion de correction
     */
    private function analyzePhpImplementations(
        MigrationReport $report,
        string $projectPath,
        array $interfaces,
        string $interfaceLabel,
        string $componentLabel,
        string $suggestion,
    ): void {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            /* Filtre rapide */
            if (!str_contains($code, $interfaceLabel)) {
                continue;
            }

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $targetInterfaces = $interfaces;

            $visitor = new class ($targetInterfaces) extends NodeVisitorAbstract {
                /** @var list<array{name: string, line: int}> */
                public array $implementations = [];

                /** @param list<string> $targetInterfaces */
                public function __construct(private readonly array $targetInterfaces)
                {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_) {
                        return null;
                    }

                    $className = $node->name?->toString() ?? 'anonyme';

                    foreach ($node->implements as $implement) {
                        $implementName = $implement->toString();
                        foreach ($this->targetInterfaces as $targetInterface) {
                            if ($implementName === $targetInterface
                                || str_ends_with($implementName, '\\' . $targetInterface)
                            ) {
                                $this->implementations[] = [
                                    'name' => $className,
                                    'line' => $node->getStartLine(),
                                ];

                                return null;
                            }
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->implementations as $implementation) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::RESOURCE,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Implementation de %s personnalisee detectee : %s',
                        $componentLabel,
                        $implementation['name'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s (ligne %d) implemente %s. '
                        . 'Cette implementation devra etre adaptee pour Sylius 2.x.',
                        $implementation['name'],
                        $file->getRelativePathname(),
                        $implementation['line'],
                        $interfaceLabel,
                    ),
                    suggestion: $suggestion,
                    file: $filePath,
                    line: $implementation['line'],
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_PER_RESOURCE,
                ));
            }
        }
    }
}
