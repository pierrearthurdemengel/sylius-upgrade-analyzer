<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Grid;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
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
 * Analyseur des personnalisations de grilles Sylius.
 * Detecte les definitions de grilles YAML, les classes de grilles PHP,
 * les colonnes personnalisees, les filtres et les actions qui devront
 * etre migres vers le nouveau systeme de grilles de Sylius 2.x.
 */
final class GridCustomizationAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes pour une grille simple (YAML uniquement) */
    private const MINUTES_SIMPLE = 60;

    /** Estimation en minutes pour une grille standard (filtres personnalises) */
    private const MINUTES_STANDARD = 120;

    /** Estimation en minutes pour une grille complexe (actions personnalisees) */
    private const MINUTES_COMPLEX = 240;

    /** URL de la documentation sur les grilles Sylius 2.x */
    private const DOC_URL = 'https://docs.sylius.com/en/latest/components_and_bundles/bundles/SyliusGridBundle/index.html';

    /*
     * Les noms ci-dessous sont pleinement qualifies et compares apres resolution des
     * use : ils correspondent aux contrats publics de SyliusGridBundle 1.x
     * (src/Bundle/Grid et src/Component). Les actions n'ont pas de contrat PHP :
     * une action personnalisee est un template Twig declare sous sylius_grid.templates.action.
     */

    /** Interfaces de grilles recherchees dans le code source */
    private const GRID_INTERFACES = [
        'Sylius\\Bundle\\GridBundle\\Grid\\ResourceAwareGridInterface',
    ];

    /** Classes abstraites de grilles */
    private const GRID_ABSTRACT_CLASSES = [
        'Sylius\\Bundle\\GridBundle\\Grid\\AbstractGrid',
    ];

    /** Interfaces des types de champ (colonnes) personnalises */
    private const FIELD_TYPE_INTERFACES = [
        'Sylius\\Component\\Grid\\FieldTypes\\FieldTypeInterface',
    ];

    /**
     * Interfaces de filtres personnalises. ConfigurableFilterInterface etend
     * FilterInterface et c'est elle que la documentation recommande : une classe
     * qui n'implemente qu'elle doit aussi etre detectee.
     */
    private const FILTER_INTERFACES = [
        'Sylius\\Component\\Grid\\Filtering\\FilterInterface',
        'Sylius\\Component\\Grid\\Filtering\\ConfigurableFilterInterface',
    ];

    public function getName(): string
    {
        return 'Grid Customization';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification des fichiers YAML de configuration de grilles */
        if ($this->hasGridYamlConfig($projectPath)) {
            return true;
        }

        /* Verification des classes PHP liees aux grilles */
        if ($this->hasGridPhpClasses($projectPath)) {
            return true;
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        /* Etape 1 : analyse des definitions de grilles YAML */
        $this->analyzeYamlGridDefinitions($report, $projectPath);

        /* Etape 2 : analyse des classes de grilles PHP */
        $this->analyzePhpGridClasses($report, $projectPath);

        /* Etape 3 : analyse des types de champ (colonnes) personnalises */
        $this->analyzeCustomFieldTypes($report, $projectPath);

        /* Etape 4 : analyse des filtres personnalises */
        $this->analyzeCustomFilters($report, $projectPath);

        /* Etape 5 : analyse des actions personnalisees (templates declares en YAML) */
        $this->analyzeCustomActions($report, $projectPath);
    }

    /**
     * Verifie si le projet contient des fichiers YAML avec la cle sylius_grid.
     */
    private function hasGridYamlConfig(string $projectPath): bool
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
                $config = Yaml::parseFile($filePath, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                continue;
            }

            if (is_array($config) && isset($config['sylius_grid'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifie si le projet contient des classes PHP liees aux grilles.
     */
    private function hasGridPhpClasses(string $projectPath): bool
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

            /*
             * Recherche rapide par texte avant l'analyse AST complete. FilterInterface
             * couvre aussi ConfigurableFilterInterface, dont il est un suffixe.
             */
            if (str_contains($content, 'ResourceAwareGridInterface')
                || str_contains($content, 'AbstractGrid')
                || str_contains($content, 'FieldTypeInterface')
                || str_contains($content, 'FilterInterface')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyse les fichiers YAML pour detecter les definitions de grilles sylius_grid.
     * Compte les grilles definies et cree un probleme par fichier contenant des grilles.
     */
    private function analyzeYamlGridDefinitions(MigrationReport $report, string $projectPath): void
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
                $config = Yaml::parseFile($filePath, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($config) || !isset($config['sylius_grid'])) {
                continue;
            }

            $gridConfig = $config['sylius_grid'];
            if (!is_array($gridConfig)) {
                continue;
            }

            /* Comptage des grilles definies */
            $grids = $gridConfig['grids'] ?? [];
            if (!is_array($grids)) {
                continue;
            }

            $gridCount = count($grids);
            if ($gridCount === 0) {
                continue;
            }

            $gridNames = array_keys($grids);

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::GRID,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d definition(s) de grille(s) YAML detectee(s) dans %s',
                    $gridCount,
                    $file->getRelativePathname(),
                ),
                detail: sprintf(
                    'Les grilles suivantes sont definies en YAML et devront etre migrees '
                    . 'vers le nouveau format de configuration de Sylius 2.x : %s',
                    implode(', ', array_map(fn ($name) => is_string($name) ? $name : (string) $name, $gridNames)),
                ),
                suggestion: 'Migrer les definitions de grilles YAML vers des classes PHP '
                    . 'implementant AbstractGrid ou ResourceAwareGridInterface. '
                    . 'Consulter la documentation de migration des grilles Sylius 2.x.',
                file: $filePath,
                docUrl: self::DOC_URL,
                estimatedMinutes: $gridCount * self::MINUTES_SIMPLE,
            ));
        }
    }

    /**
     * Analyse les fichiers PHP pour detecter les classes de grilles.
     * Recherche les implementations de ResourceAwareGridInterface et les extensions d'AbstractGrid.
     */
    private function analyzePhpGridClasses(MigrationReport $report, string $projectPath): void
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

            /* Filtre rapide avant analyse AST */
            if (!str_contains($code, 'ResourceAwareGridInterface')
                && !str_contains($code, 'AbstractGrid')
            ) {
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

            $gridInterfaces = self::GRID_INTERFACES;
            $gridAbstractClasses = self::GRID_ABSTRACT_CLASSES;

            $visitor = new class ($gridInterfaces, $gridAbstractClasses) extends NodeVisitorAbstract {
                /** @var list<array{name: string, line: int, type: string}> */
                public array $gridClasses = [];

                /**
                 * @param list<string> $interfaces
                 * @param list<string> $abstractClasses
                 */
                public function __construct(
                    private readonly array $interfaces,
                    private readonly array $abstractClasses,
                ) {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_) {
                        return null;
                    }

                    $className = $node->name?->toString() ?? 'anonyme';

                    /*
                     * Verification des interfaces implementees. Les noms sont resolus :
                     * la comparaison exacte couvre les alias d'import et ecarte les
                     * homonymes d'autres espaces de noms.
                     */
                    foreach ($node->implements as $implement) {
                        if (\in_array($implement->toString(), $this->interfaces, true)) {
                            $this->gridClasses[] = [
                                'name' => $className,
                                'line' => $node->getStartLine(),
                                'type' => 'interface',
                            ];

                            return null;
                        }
                    }

                    /* Verification de la classe parente */
                    if ($node->extends !== null
                        && \in_array($node->extends->toString(), $this->abstractClasses, true)
                    ) {
                        $this->gridClasses[] = [
                            'name' => $className,
                            'line' => $node->getStartLine(),
                            'type' => 'extends',
                        ];
                    }

                    return null;
                }
            };

            $this->traverseWithResolvedNames($ast, $visitor);

            foreach ($visitor->gridClasses as $gridClass) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::GRID,
                    analyzer: $this->getName(),
                    message: sprintf('Classe de grille personnalisee detectee : %s', $gridClass['name']),
                    detail: sprintf(
                        'La classe %s dans %s (ligne %d) %s. '
                        . 'Cette classe devra etre adaptee pour la nouvelle API de grilles Sylius 2.x.',
                        $gridClass['name'],
                        $file->getRelativePathname(),
                        $gridClass['line'],
                        $gridClass['type'] === 'interface'
                            ? 'implemente ResourceAwareGridInterface'
                            : 'etend AbstractGrid',
                    ),
                    suggestion: 'Verifier la compatibilite de cette classe de grille avec Sylius 2.x. '
                        . 'Adapter les methodes buildGrid() et getResourceClass() si necessaire.',
                    file: $filePath,
                    line: $gridClass['line'],
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_STANDARD,
                ));
            }
        }
    }

    /**
     * Analyse les fichiers PHP pour detecter les types de champ (colonnes) personnalises.
     */
    private function analyzeCustomFieldTypes(MigrationReport $report, string $projectPath): void
    {
        $this->analyzeCustomGridComponents(
            $report,
            $projectPath,
            self::FIELD_TYPE_INTERFACES,
            'FieldTypeInterface',
            'champ',
            self::MINUTES_STANDARD,
        );
    }

    /**
     * Analyse les fichiers PHP pour detecter les filtres de grille personnalises.
     */
    private function analyzeCustomFilters(MigrationReport $report, string $projectPath): void
    {
        /* Le libelle FilterInterface sert aussi de filtre textuel : il couvre ConfigurableFilterInterface */
        $this->analyzeCustomGridComponents(
            $report,
            $projectPath,
            self::FILTER_INTERFACES,
            'FilterInterface',
            'filtre',
            self::MINUTES_STANDARD,
        );
    }

    /**
     * Analyse la configuration YAML pour detecter les actions de grille personnalisees.
     * SyliusGridBundle 1.x ne definit aucune interface PHP pour les actions : chaque type
     * d'action personnalise est un template Twig declare sous sylius_grid.templates.action,
     * que la refonte des templates de Sylius 2.x oblige a reecrire.
     */
    private function analyzeCustomActions(MigrationReport $report, string $projectPath): void
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
                $config = Yaml::parseFile($filePath, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                continue;
            }

            $actionTemplates = $config['sylius_grid']['templates']['action'] ?? null;
            if (!is_array($actionTemplates)) {
                continue;
            }

            foreach ($actionTemplates as $actionType => $template) {
                /* Une liste YAML produit des cles entieres, qui ne sont pas des types d'action */
                if (!is_string($actionType) || !is_string($template)) {
                    continue;
                }

                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::GRID,
                    analyzer: $this->getName(),
                    message: sprintf('Type d\'action personnalise detecte : %s', $actionType),
                    detail: sprintf(
                        'Le type d\'action %s est declare dans %s avec le template %s. '
                        . 'Ce template d\'action personnalise devra etre adapte pour Sylius 2.x.',
                        $actionType,
                        $file->getRelativePathname(),
                        $template,
                    ),
                    suggestion: sprintf(
                        'Reecrire le template de l\'action %s pour le nouveau systeme de templates '
                        . 'des grilles de Sylius 2.x et verifier sa declaration sous sylius_grid.templates.action.',
                        $actionType,
                    ),
                    file: $filePath,
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_COMPLEX,
                ));
            }
        }
    }

    /**
     * Methode generique pour analyser les composants de grille personnalises.
     * Detecte les classes qui implementent une interface specifique liee aux grilles.
     *
     * @param list<string> $interfaces       Liste des noms d'interfaces a rechercher
     * @param string       $interfaceLabel   Nom court de l'interface pour les messages
     * @param string       $componentLabel   Label du composant pour les messages (colonne, filtre, action)
     * @param int          $estimatedMinutes Estimation du temps de migration par composant
     */
    private function analyzeCustomGridComponents(
        MigrationReport $report,
        string $projectPath,
        array $interfaces,
        string $interfaceLabel,
        string $componentLabel,
        int $estimatedMinutes,
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

            /* Filtre rapide avant analyse AST */
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

                    /* Comparaison exacte sur les noms resolus (alias d'import compris) */
                    foreach ($node->implements as $implement) {
                        if (\in_array($implement->toString(), $this->targetInterfaces, true)) {
                            $this->implementations[] = [
                                'name' => $className,
                                'line' => $node->getStartLine(),
                            ];

                            return null;
                        }
                    }

                    return null;
                }
            };

            $this->traverseWithResolvedNames($ast, $visitor);

            foreach ($visitor->implementations as $implementation) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::GRID,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Type de %s personnalise detecte : %s',
                        $componentLabel,
                        $implementation['name'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s (ligne %d) implemente %s. '
                        . 'Ce type de %s personnalise devra etre adapte pour Sylius 2.x.',
                        $implementation['name'],
                        $file->getRelativePathname(),
                        $implementation['line'],
                        $interfaceLabel,
                        $componentLabel,
                    ),
                    suggestion: sprintf(
                        'Adapter l\'implementation de %s dans %s pour la nouvelle API '
                        . 'de grilles de Sylius 2.x. Verifier les changements de signatures des methodes.',
                        $interfaceLabel,
                        $implementation['name'],
                    ),
                    file: $filePath,
                    line: $implementation['line'],
                    docUrl: self::DOC_URL,
                    estimatedMinutes: $estimatedMinutes,
                ));
            }
        }
    }

    /**
     * Parcourt l'AST apres resolution des noms : les clauses extends et implements
     * contiennent alors des noms pleinement qualifies, quels que soient les use et
     * alias du fichier (use ...\AbstractGrid as BaseGrid).
     *
     * @param array<Node\Stmt> $ast
     */
    private function traverseWithResolvedNames(array $ast, NodeVisitorAbstract $visitor): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
