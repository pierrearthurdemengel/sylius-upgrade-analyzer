<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

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
 * Detecte les processeurs de commande et contextes de panier qui ne seront plus
 * enregistres apres la montee en Sylius 2.0.
 *
 * En Sylius 1.x (sylius_order.autoconfigure_with_attributes a false, la valeur par defaut),
 * toute classe implementant OrderProcessorInterface ou CartContextInterface recoit
 * automatiquement son tag. Sylius 2.0 supprime cette autoconfiguration par interface : seuls
 * les attributs #[AsOrderProcessor] et #[AsCartContext] l'assurent (UPGRADE-2.0.md,
 * section Autoconfiguration). Une classe sans attribut ni tag explicite cesse alors d'etre
 * appelee, sans aucune erreur : les totaux de commande ou le panier deviennent faux.
 */
final class OrderProcessorAutoconfigurationAnalyzer implements AnalyzerInterface
{
    /** Ajout d'un attribut et verification du comportement de la commande */
    private const MINUTES_PER_SERVICE = 30;

    private const DOC_URL = 'https://github.com/Sylius/Sylius/blob/2.0/UPGRADE-2.0.md#autoconfiguration';

    /**
     * Interface => [attribut a ajouter, tag du service]
     *
     * @var array<string, array{attribute: string, tag: string}>
     */
    private const AUTOCONFIGURED = [
        'Sylius\\Component\\Order\\Processor\\OrderProcessorInterface' => [
            'attribute' => 'Sylius\\Bundle\\OrderBundle\\Attribute\\AsOrderProcessor',
            'tag' => 'sylius.order_processor',
        ],
        'Sylius\\Component\\Order\\Context\\CartContextInterface' => [
            'attribute' => 'Sylius\\Bundle\\OrderBundle\\Attribute\\AsCartContext',
            'tag' => 'sylius.context.cart',
        ],
    ];

    public function getName(): string
    {
        return 'Order Processor Autoconfiguration';
    }

    public function supports(MigrationReport $report): bool
    {
        $srcDir = $report->getProjectPath() . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());
            if (str_contains($content, 'OrderProcessorInterface') || str_contains($content, 'CartContextInterface')) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $tagged = $this->collectExplicitlyTaggedServices($projectPath);

        /* Un _instanceof sur l'interface reproduit l'autoconfiguration : rien a signaler pour elle */
        $coveredInterfaces = $tagged['instanceof'];

        foreach ($this->findImplementations($projectPath) as $implementation) {
            $interface = $implementation['interface'];
            if (\in_array($interface, $coveredInterfaces, true)
                || $implementation['hasAttribute']
                || \in_array($implementation['class'], $tagged['classes'], true)
            ) {
                continue;
            }

            $shortAttribute = substr(strrchr(self::AUTOCONFIGURED[$interface]['attribute'], '\\') ?: '', 1);
            $shortInterface = substr(strrchr($interface, '\\') ?: '', 1);

            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%s ne sera plus enregistre : %s sans #[%s] ni tag %s',
                    $implementation['class'],
                    $shortInterface,
                    $shortAttribute,
                    self::AUTOCONFIGURED[$interface]['tag'],
                ),
                detail: sprintf(
                    'Sylius 1.x tague automatiquement les classes implementant %s. Sylius 2.0 supprime '
                    . 'cette autoconfiguration par interface : sans attribut ni tag explicite, %s ne sera '
                    . 'plus appele, sans erreur.',
                    $shortInterface,
                    $implementation['class'],
                ),
                suggestion: sprintf(
                    'Ajouter #[%s] sur la classe (priorite 0 par defaut, comme le tag pose par l\'interface '
                    . 'en Sylius 1.x), ou declarer le tag %s dans la configuration des services.',
                    $shortAttribute,
                    self::AUTOCONFIGURED[$interface]['tag'],
                ),
                file: $implementation['file'],
                line: $implementation['line'],
                docUrl: self::DOC_URL,
                estimatedMinutes: self::MINUTES_PER_SERVICE,
            ));
        }
    }

    /**
     * Recense les classes concretes de src/ qui implementent une interface autoconfiguree.
     *
     * @return list<array{class: string, interface: string, hasAttribute: bool, file: string, line: int}>
     */
    private function findImplementations(string $projectPath): array
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return [];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $found = [];
        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            try {
                $ast = $parser->parse((string) file_get_contents($filePath));
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new class (self::AUTOCONFIGURED) extends NodeVisitorAbstract {
                /** @var list<array{class: string, interface: string, hasAttribute: bool, line: int}> */
                public array $classes = [];

                /**
                 * @param array<string, array{attribute: string, tag: string}> $autoconfigured
                 */
                public function __construct(private readonly array $autoconfigured)
                {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_ || $node->isAbstract() || $node->namespacedName === null) {
                        return null;
                    }

                    $attributes = [];
                    foreach ($node->attrGroups as $group) {
                        foreach ($group->attrs as $attribute) {
                            $attributes[] = ltrim($attribute->name->toString(), '\\');
                        }
                    }

                    foreach ($node->implements as $interface) {
                        $name = ltrim($interface->toString(), '\\');
                        if (!isset($this->autoconfigured[$name])) {
                            continue;
                        }

                        $this->classes[] = [
                            'class' => $node->namespacedName->toString(),
                            'interface' => $name,
                            'hasAttribute' => \in_array($this->autoconfigured[$name]['attribute'], $attributes, true),
                            'line' => $node->getStartLine(),
                        ];
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->classes as $class) {
                $found[] = $class + ['file' => $filePath];
            }
        }

        return $found;
    }

    /**
     * Lit les services tagues explicitement dans config/ (YAML et XML), ainsi que les
     * interfaces couvertes par un _instanceof.
     *
     * @return array{classes: list<string>, instanceof: list<string>}
     */
    private function collectExplicitlyTaggedServices(string $projectPath): array
    {
        $result = ['classes' => [], 'instanceof' => []];
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return $result;
        }

        $tags = array_column(self::AUTOCONFIGURED, 'tag');
        $finder = new Finder();
        $finder->files()->in($configDir)->name(['*.yaml', '*.yml', '*.xml']);

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());

            if ($file->getExtension() === 'xml') {
                $this->collectFromXml($content, $tags, $result);
                continue;
            }

            try {
                $config = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                continue;
            }

            $services = \is_array($config) ? ($config['services'] ?? null) : null;
            if (!\is_array($services)) {
                continue;
            }

            foreach ($services as $id => $definition) {
                if ($id === '_instanceof' && \is_array($definition)) {
                    foreach ($definition as $interface => $instanceofDefinition) {
                        if (\is_array($instanceofDefinition) && $this->hasTag($instanceofDefinition, $tags)) {
                            $result['instanceof'][] = ltrim((string) $interface, '\\');
                        }
                    }
                    continue;
                }

                if (!\is_array($definition) || !$this->hasTag($definition, $tags)) {
                    continue;
                }

                $class = \is_string($definition['class'] ?? null) ? $definition['class'] : (string) $id;
                $result['classes'][] = ltrim($class, '\\');
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $definition
     * @param list<string> $tags
     */
    private function hasTag(array $definition, array $tags): bool
    {
        foreach ((array) ($definition['tags'] ?? []) as $tag) {
            $name = \is_array($tag) ? ($tag['name'] ?? null) : $tag;
            if (\is_string($name) && \in_array($name, $tags, true)) {
                return true;
            }
            /* Forme courte : - sylius.order_processor: { priority: 10 } */
            if (\is_array($tag) && array_intersect(array_keys($tag), $tags) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tags
     * @param array{classes: list<string>, instanceof: list<string>} $result
     */
    private function collectFromXml(string $content, array $tags, array &$result): void
    {
        if (preg_match_all('#<service\b([^>]*)>(.*?)</service>#s', $content, $services, PREG_SET_ORDER) === 0) {
            return;
        }

        foreach ($services as $service) {
            $isTagged = false;
            foreach ($tags as $tag) {
                if (preg_match('#<tag\b[^>]*name="' . preg_quote($tag, '#') . '"#', $service[2]) === 1) {
                    $isTagged = true;
                }
            }
            if (!$isTagged) {
                continue;
            }

            if (preg_match('#\bclass="([^"]+)"#', $service[1], $class) === 1
                || preg_match('#\bid="([^"]+)"#', $service[1], $class) === 1
            ) {
                $result['classes'][] = ltrim($class[1], '\\');
            }
        }
    }
}
