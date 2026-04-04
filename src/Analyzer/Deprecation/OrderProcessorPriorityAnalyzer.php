<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Analyseur des processeurs de commande et de leurs priorites.
 * Sylius 2.0 modifie les priorites des processeurs de commande internes.
 * Les implementations personnalisees utilisant les priorites 40-60 peuvent entrer en conflit.
 */
final class OrderProcessorPriorityAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par processeur impacte */
    private const MINUTES_PER_PROCESSOR = 120;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Plage de priorites pouvant entrer en conflit avec les processeurs internes Sylius */
    private const MIN_CONFLICTING_PRIORITY = 40;
    private const MAX_CONFLICTING_PRIORITY = 60;

    public function getName(): string
    {
        return 'Order Processor Priority';
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
            if (str_contains($content, 'OrderProcessorInterface')) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $processorCount = 0;

        /* Etape 1 : detection des classes implementant OrderProcessorInterface */
        $processorCount += $this->analyzeProcessorClasses($report, $projectPath);

        /* Etape 2 : analyse des priorites dans la configuration */
        $processorCount += $this->analyzeProcessorPriorities($report, $projectPath);

        /* Etape 3 : resume global */
        if ($processorCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d processeur(s) de commande personnalise(s) detecte(s)',
                    $processorCount,
                ),
                detail: 'Sylius 2.0 modifie les priorites des processeurs de commande internes. '
                    . 'Les implementations personnalisees avec des priorites entre 40 et 60 '
                    . 'peuvent entrer en conflit avec les processeurs internes.',
                suggestion: 'Verifier les priorites des processeurs de commande personnalises '
                    . 'et les ajuster pour eviter les conflits avec Sylius 2.0.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $processorCount * self::MINUTES_PER_PROCESSOR,
            ));
        }
    }

    /**
     * Detecte les classes implementant OrderProcessorInterface dans src/.
     * Retourne le nombre de classes detectees.
     */
    private function analyzeProcessorClasses(MigrationReport $report, string $projectPath): int
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

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            /* Visiteur pour detecter les classes implementant OrderProcessorInterface */
            $visitor = new class extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int}> */
                public array $processors = [];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Stmt\Class_ && $node->implements !== []) {
                        foreach ($node->implements as $interface) {
                            $name = $interface->toString();
                            if (str_contains($name, 'OrderProcessorInterface')) {
                                $className = $node->name !== null ? $node->name->toString() : '(anonyme)';
                                $this->processors[] = [
                                    'class' => $className,
                                    'line' => $node->getStartLine(),
                                ];
                            }
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->processors as $processor) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Processeur de commande "%s" implementant OrderProcessorInterface', $processor['class']),
                    detail: sprintf(
                        'La classe %s dans %s implemente OrderProcessorInterface. '
                        . 'Verifier que sa priorite ne conflit pas avec les processeurs internes de Sylius 2.0.',
                        $processor['class'],
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Verifier la priorite de ce processeur dans la configuration des services '
                        . 'et l\'ajuster si elle se situe entre 40 et 60.',
                    file: $filePath,
                    line: $processor['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Analyse la configuration pour les tags sylius.order_processor avec priorites conflictuelles.
     * Retourne le nombre de configurations problematiques.
     */
    private function analyzeProcessorPriorities(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

        $count = 0;

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

            foreach ($services as $serviceId => $serviceConfig) {
                if (!is_array($serviceConfig) || !isset($serviceConfig['tags'])) {
                    continue;
                }

                $tags = $serviceConfig['tags'];
                if (!is_array($tags)) {
                    continue;
                }

                foreach ($tags as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }

                    $tagName = $tag['name'] ?? '';
                    if ($tagName !== 'sylius.order_processor') {
                        continue;
                    }

                    $priority = (int) ($tag['priority'] ?? 0);

                    if ($priority >= self::MIN_CONFLICTING_PRIORITY && $priority <= self::MAX_CONFLICTING_PRIORITY) {
                        $count++;
                        $report->addIssue(new MigrationIssue(
                            severity: Severity::WARNING,
                            category: Category::DEPRECATION,
                            analyzer: $this->getName(),
                            message: sprintf(
                                'Tag sylius.order_processor avec priorite %d pour le service "%s"',
                                $priority,
                                $serviceId,
                            ),
                            detail: sprintf(
                                'Le service "%s" dans %s a un tag sylius.order_processor avec une priorite de %d. '
                                . 'Cette valeur est dans la plage 40-60 qui peut entrer en conflit '
                                . 'avec les processeurs internes de Sylius 2.0.',
                                $serviceId,
                                $file->getRelativePathname(),
                                $priority,
                            ),
                            suggestion: sprintf(
                                'Ajuster la priorite du processeur "%s" pour eviter la plage 40-60 '
                                . 'reservee aux processeurs internes de Sylius 2.0.',
                                $serviceId,
                            ),
                            file: $filePath,
                            docUrl: self::DOC_URL,
                        ));
                    }
                }
            }
        }

        return $count;
    }
}
