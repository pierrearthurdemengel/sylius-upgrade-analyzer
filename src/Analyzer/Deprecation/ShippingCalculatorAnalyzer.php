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
use Symfony\Component\Yaml\Yaml;

/**
 * Analyseur des calculateurs d'expedition personnalises.
 * Sylius 2.0 modifie l'interface CalculatorInterface du composant Shipping.
 * Les implementations personnalisees doivent etre adaptees.
 */
final class ShippingCalculatorAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par calculateur personnalise */
    private const MINUTES_PER_CALCULATOR = 240;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Namespace Sylius pour l'interface CalculatorInterface */
    private const CALCULATOR_INTERFACE_FQCN = 'Sylius\\Component\\Shipping\\Calculator\\CalculatorInterface';

    public function getName(): string
    {
        return 'Shipping Calculator';
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
            if (str_contains($content, 'CalculatorInterface')
                && (str_contains($content, 'Sylius') || str_contains($content, 'Shipping'))
            ) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $calculatorCount = 0;

        /* Etape 1 : detection des classes implementant CalculatorInterface */
        $calculatorCount += $this->analyzeCalculatorClasses($report, $projectPath);

        /* Etape 2 : detection des tags sylius.shipping_calculator dans la configuration */
        $calculatorCount += $this->analyzeShippingCalculatorTags($report, $projectPath);

        /* Etape 3 : resume global */
        if ($calculatorCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d calculateur(s) d\'expedition personnalise(s) detecte(s)',
                    $calculatorCount,
                ),
                detail: 'Sylius 2.0 modifie l\'interface CalculatorInterface du composant Shipping. '
                    . 'Les calculateurs personnalises doivent etre adaptes aux nouvelles signatures.',
                suggestion: 'Mettre a jour les implementations de CalculatorInterface selon '
                    . 'la nouvelle signature de Sylius 2.0.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $calculatorCount * self::MINUTES_PER_CALCULATOR,
            ));
        }
    }

    /**
     * Detecte les classes implementant CalculatorInterface de Sylius Shipping dans src/.
     * Retourne le nombre de classes detectees.
     */
    private function analyzeCalculatorClasses(MigrationReport $report, string $projectPath): int
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

            /* Verification rapide avant le parsing AST */
            if (!str_contains($code, 'CalculatorInterface')) {
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

            /* Visiteur pour detecter les classes implementant CalculatorInterface de Sylius Shipping */
            $calculatorFqcn = self::CALCULATOR_INTERFACE_FQCN;
            $visitor = new class ($calculatorFqcn) extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int}> */
                public array $calculators = [];

                /** Indique si le fichier importe CalculatorInterface de Sylius Shipping */
                private bool $importsSyliusCalculator = false;

                public function __construct(private readonly string $calculatorFqcn)
                {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection de l'import de l'interface Sylius */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $fullName = $use->name->toString();
                            if ($fullName === $this->calculatorFqcn
                                || (str_contains($fullName, 'Sylius')
                                    && str_contains($fullName, 'Shipping')
                                    && str_ends_with($fullName, 'CalculatorInterface'))
                            ) {
                                $this->importsSyliusCalculator = true;
                            }
                        }
                    }

                    /* Detection des implementations */
                    if ($node instanceof Node\Stmt\Class_
                        && $node->implements !== []
                        && $this->importsSyliusCalculator
                    ) {
                        foreach ($node->implements as $interface) {
                            $name = $interface->toString();
                            if (str_contains($name, 'CalculatorInterface')) {
                                $className = $node->name !== null ? $node->name->toString() : '(anonyme)';
                                $this->calculators[] = [
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

            foreach ($visitor->calculators as $calculator) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Calculateur d\'expedition "%s" implementant CalculatorInterface',
                        $calculator['class'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s implemente CalculatorInterface de Sylius Shipping. '
                        . 'La signature de cette interface change dans Sylius 2.0.',
                        $calculator['class'],
                        $file->getRelativePathname(),
                    ),
                    suggestion: sprintf(
                        'Adapter l\'implementation de CalculatorInterface dans %s '
                        . 'selon la nouvelle signature de Sylius 2.0.',
                        $calculator['class'],
                    ),
                    file: $filePath,
                    line: $calculator['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Detecte les tags sylius.shipping_calculator dans la configuration YAML.
     * Retourne le nombre de tags detectes.
     */
    private function analyzeShippingCalculatorTags(MigrationReport $report, string $projectPath): int
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
                    $tagName = is_array($tag) ? ($tag['name'] ?? '') : (string) $tag;

                    if ($tagName !== 'sylius.shipping_calculator') {
                        continue;
                    }

                    $count++;
                    $calculatorType = is_array($tag) ? ($tag['calculator'] ?? 'inconnu') : 'inconnu';

                    $report->addIssue(new MigrationIssue(
                        severity: Severity::WARNING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf(
                            'Tag sylius.shipping_calculator detecte pour le service "%s" (type: %s)',
                            $serviceId,
                            $calculatorType,
                        ),
                        detail: sprintf(
                            'Le service "%s" dans %s est tague comme calculateur d\'expedition Sylius. '
                            . 'L\'interface CalculatorInterface a change dans Sylius 2.0.',
                            $serviceId,
                            $file->getRelativePathname(),
                        ),
                        suggestion: sprintf(
                            'Verifier que le calculateur "%s" est compatible avec la nouvelle '
                            . 'interface CalculatorInterface de Sylius 2.0.',
                            $serviceId,
                        ),
                        file: $filePath,
                        docUrl: self::DOC_URL,
                    ));
                }
            }
        }

        return $count;
    }
}
