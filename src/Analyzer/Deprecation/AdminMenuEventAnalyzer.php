<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

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

/**
 * Analyseur des evenements de menu d'administration Sylius.
 *
 * Sylius 2.0 supprime cinq menu builders de l'administration (UPGRADE-2.0.md, services
 * supprimes) : leurs evenements ne sont plus jamais declenches et les listeners qui les
 * ecoutent cessent d'agir sans erreur. Ces menus passent par les Twig Hooks. Le menu
 * principal (sylius.menu.admin.main) et le menu du compte client sont conserves a
 * l'identique : leurs listeners continuent de fonctionner et ne sont pas signales.
 */
final class AdminMenuEventAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par menu base sur les evenements */
    private const MINUTES_PER_EVENT_MENU = 120;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Prefixe des evenements de menu d'administration */
    private const MENU_EVENT_PREFIX = 'sylius.menu.admin';

    /**
     * Evenements des menu builders supprimes en Sylius 2.0 (EVENT_NAME de
     * CustomerShowMenuBuilder, OrderShowMenuBuilder, ProductFormMenuBuilder,
     * ProductVariantFormMenuBuilder et PromotionUpdateMenuBuilder en 1.14).
     */
    private const REMOVED_MENU_EVENTS = [
        'sylius.menu.admin.customer.show',
        'sylius.menu.admin.order.show',
        'sylius.menu.admin.product.form',
        'sylius.menu.admin.product_variant.form',
        'sylius.menu.admin.promotion.update',
    ];

    public function getName(): string
    {
        return 'Admin Menu Event';
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
            if (str_contains($content, 'sylius.menu.admin')) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $eventCount = 0;

        /* Etape 1 : detection des attributs #[AsEventListener] sur les evenements de menu */
        $eventCount += $this->analyzeEventListenerAttributes($report, $projectPath);

        /* Etape 2 : detection des getSubscribedEvents() avec les cles sylius.menu.* */
        $eventCount += $this->analyzeEventSubscribers($report, $projectPath);

        /* Etape 3 : resume global */
        if ($eventCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d gestionnaire(s) d\'evenements de menu admin supprime(s) detecte(s)',
                    $eventCount,
                ),
                detail: 'Sylius 2.0 supprime les menus admin des pages client, commande, produit, '
                    . 'variante et promotion : leurs evenements ne sont plus declenches et ces '
                    . 'gestionnaires n\'agissent plus.',
                suggestion: 'Reporter les entrees de menu ajoutees par ces gestionnaires dans les '
                    . 'Twig Hooks des pages concernees.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $eventCount * self::MINUTES_PER_EVENT_MENU,
            ));
        }
    }

    /**
     * Detecte les attributs #[AsEventListener] ciblant les evenements de menu admin.
     * Retourne le nombre d'attributs detectes.
     */
    private function analyzeEventListenerAttributes(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            /* Verification rapide avant de parser l'AST */
            if (!str_contains($code, self::MENU_EVENT_PREFIX)) {
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

            /* Visiteur pour detecter les attributs AsEventListener et les chaines de menu */
            $menuEventPrefix = self::MENU_EVENT_PREFIX;
            $visitor = new class ($menuEventPrefix) extends NodeVisitorAbstract {
                /** @var list<array{event: string, line: int, type: string}> */
                public array $usages = [];

                public function __construct(private readonly string $menuEventPrefix)
                {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection des attributs PHP 8 #[AsEventListener] */
                    if ($node instanceof Node\Stmt\Class_) {
                        foreach ($node->attrGroups as $attrGroup) {
                            foreach ($attrGroup->attrs as $attr) {
                                $attrName = $attr->name->toString();
                                if (!str_contains($attrName, 'AsEventListener')) {
                                    continue;
                                }

                                foreach ($attr->args as $arg) {
                                    if ($arg->value instanceof Node\Scalar\String_
                                        && str_starts_with($arg->value->value, $this->menuEventPrefix)
                                    ) {
                                        $this->usages[] = [
                                            'event' => $arg->value->value,
                                            'line' => $attr->getStartLine(),
                                            'type' => 'attribute',
                                        ];
                                    }
                                }
                            }
                        }
                    }

                    /* Detection aussi sur les methodes */
                    if ($node instanceof Node\Stmt\ClassMethod) {
                        foreach ($node->attrGroups as $attrGroup) {
                            foreach ($attrGroup->attrs as $attr) {
                                $attrName = $attr->name->toString();
                                if (!str_contains($attrName, 'AsEventListener')) {
                                    continue;
                                }

                                foreach ($attr->args as $arg) {
                                    if ($arg->value instanceof Node\Scalar\String_
                                        && str_starts_with($arg->value->value, $this->menuEventPrefix)
                                    ) {
                                        $this->usages[] = [
                                            'event' => $arg->value->value,
                                            'line' => $attr->getStartLine(),
                                            'type' => 'attribute',
                                        ];
                                    }
                                }
                            }
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->usages as $usage) {
                if (!\in_array($usage['event'], self::REMOVED_MENU_EVENTS, true)) {
                    continue;
                }
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Attribut #[AsEventListener] sur l\'evenement "%s" detecte',
                        $usage['event'],
                    ),
                    detail: sprintf(
                        'Un attribut #[AsEventListener] ciblant l\'evenement "%s" a ete trouve '
                        . 'dans %s ligne %d. Ce menu est supprime dans Sylius 2.0 : l\'evenement '
                        . 'n\'est plus declenche.',
                        $usage['event'],
                        $file->getRelativePathname(),
                        $usage['line'],
                    ),
                    suggestion: sprintf(
                        'Reporter les entrees ajoutees sur "%s" dans les Twig Hooks de la page.',
                        $usage['event'],
                    ),
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Detecte les methodes getSubscribedEvents() contenant des cles sylius.menu.*.
     * Retourne le nombre de souscriptions detectees.
     */
    private function analyzeEventSubscribers(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            /* Verification rapide */
            if (!str_contains($code, 'getSubscribedEvents') || !str_contains($code, 'sylius.menu')) {
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

            /* Visiteur pour detecter getSubscribedEvents avec des cles sylius.menu.* */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{event: string, line: int}> */
                public array $subscriptions = [];

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\ClassMethod
                        || $node->name->toString() !== 'getSubscribedEvents'
                    ) {
                        return null;
                    }

                    /* Parcours des noeuds enfants pour trouver les chaines sylius.menu.* */
                    $this->findMenuStrings($node);

                    return null;
                }

                private function findMenuStrings(Node $node): void
                {
                    if ($node instanceof Node\Scalar\String_
                        && str_starts_with($node->value, 'sylius.menu.')
                    ) {
                        $this->subscriptions[] = [
                            'event' => $node->value,
                            'line' => $node->getStartLine(),
                        ];
                    }

                    foreach ($node->getSubNodeNames() as $subNodeName) {
                        $subNode = $node->{$subNodeName};
                        if ($subNode instanceof Node) {
                            $this->findMenuStrings($subNode);
                        } elseif (is_array($subNode)) {
                            foreach ($subNode as $item) {
                                if ($item instanceof Node) {
                                    $this->findMenuStrings($item);
                                }
                            }
                        }
                    }
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->subscriptions as $subscription) {
                if (!\in_array($subscription['event'], self::REMOVED_MENU_EVENTS, true)) {
                    continue;
                }
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Souscription a l\'evenement "%s" dans getSubscribedEvents()',
                        $subscription['event'],
                    ),
                    detail: sprintf(
                        'La methode getSubscribedEvents() dans %s souscrit a l\'evenement "%s" (ligne %d). '
                        . 'Ce menu est supprime dans Sylius 2.0 : l\'evenement n\'est plus declenche.',
                        $file->getRelativePathname(),
                        $subscription['event'],
                        $subscription['line'],
                    ),
                    suggestion: sprintf(
                        'Reporter les entrees ajoutees sur "%s" dans les Twig Hooks de la page.',
                        $subscription['event'],
                    ),
                    file: $filePath,
                    line: $subscription['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }
}
