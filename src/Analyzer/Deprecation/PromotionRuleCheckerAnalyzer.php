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
 * Analyseur des regles de promotion et actions personnalisees.
 * Sylius 2.0 modifie les interfaces PromotionRuleCheckerInterface et PromotionActionCommandInterface.
 * Les implementations personnalisees doivent etre adaptees.
 */
final class PromotionRuleCheckerAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par regle/action personnalisee */
    private const MINUTES_PER_RULE_ACTION = 180;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Interfaces de promotion recherchees */
    private const TARGET_INTERFACES = [
        'PromotionRuleCheckerInterface',
        'PromotionActionCommandInterface',
    ];

    /** Tags de services lies aux promotions */
    private const PROMOTION_TAGS = [
        'sylius.promotion_rule_checker',
        'sylius.promotion_action',
    ];

    public function getName(): string
    {
        return 'Promotion Rule Checker';
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
            foreach (self::TARGET_INTERFACES as $interface) {
                if (str_contains($content, $interface)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $ruleActionCount = 0;

        /* Etape 1 : detection des classes implementant les interfaces de promotion */
        $ruleActionCount += $this->analyzePromotionClasses($report, $projectPath);

        /* Etape 2 : detection des tags de services lies aux promotions */
        $ruleActionCount += $this->analyzePromotionServiceTags($report, $projectPath);

        /* Etape 3 : resume global */
        if ($ruleActionCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d regle(s)/action(s) de promotion personnalisee(s) detectee(s)',
                    $ruleActionCount,
                ),
                detail: 'Sylius 2.0 modifie les interfaces PromotionRuleCheckerInterface et '
                    . 'PromotionActionCommandInterface. Les implementations personnalisees '
                    . 'doivent etre adaptees aux nouvelles signatures.',
                suggestion: 'Mettre a jour les implementations de PromotionRuleCheckerInterface '
                    . 'et PromotionActionCommandInterface selon les nouvelles signatures de Sylius 2.0.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $ruleActionCount * self::MINUTES_PER_RULE_ACTION,
            ));
        }
    }

    /**
     * Detecte les classes implementant les interfaces de promotion dans src/.
     * Retourne le nombre de classes detectees.
     */
    private function analyzePromotionClasses(MigrationReport $report, string $projectPath): int
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
            $hasTarget = false;
            foreach (self::TARGET_INTERFACES as $interface) {
                if (str_contains($code, $interface)) {
                    $hasTarget = true;
                    break;
                }
            }
            if (!$hasTarget) {
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

            /* Visiteur pour detecter les implementations des interfaces cibles */
            $targetInterfaces = self::TARGET_INTERFACES;
            $visitor = new class ($targetInterfaces) extends NodeVisitorAbstract {
                /** @var list<array{class: string, interface: string, line: int}> */
                public array $implementations = [];

                /** @param list<string> $targetInterfaces */
                public function __construct(private readonly array $targetInterfaces)
                {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_ || $node->implements === []) {
                        return null;
                    }

                    $className = $node->name !== null ? $node->name->toString() : '(anonyme)';

                    foreach ($node->implements as $interface) {
                        $interfaceName = $interface->toString();
                        foreach ($this->targetInterfaces as $target) {
                            if ($interfaceName === $target || str_ends_with($interfaceName, '\\' . $target)) {
                                $this->implementations[] = [
                                    'class' => $className,
                                    'interface' => $target,
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

            foreach ($visitor->implementations as $implementation) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Classe "%s" implementant %s',
                        $implementation['class'],
                        $implementation['interface'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s implemente %s dont la signature '
                        . 'a change dans Sylius 2.0.',
                        $implementation['class'],
                        $file->getRelativePathname(),
                        $implementation['interface'],
                    ),
                    suggestion: sprintf(
                        'Adapter l\'implementation de %s dans %s '
                        . 'selon la nouvelle signature de Sylius 2.0.',
                        $implementation['interface'],
                        $implementation['class'],
                    ),
                    file: $filePath,
                    line: $implementation['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Detecte les tags de services lies aux promotions dans la configuration.
     * Retourne le nombre de tags detectes.
     */
    private function analyzePromotionServiceTags(MigrationReport $report, string $projectPath): int
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

                    if (!in_array($tagName, self::PROMOTION_TAGS, true)) {
                        continue;
                    }

                    $count++;
                    $report->addIssue(new MigrationIssue(
                        severity: Severity::WARNING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf(
                            'Tag "%s" detecte pour le service "%s"',
                            $tagName,
                            $serviceId,
                        ),
                        detail: sprintf(
                            'Le service "%s" dans %s est tague avec "%s". '
                            . 'Les interfaces associees ont change dans Sylius 2.0.',
                            $serviceId,
                            $file->getRelativePathname(),
                            $tagName,
                        ),
                        suggestion: sprintf(
                            'Verifier que le service "%s" est compatible avec les nouvelles '
                            . 'interfaces de promotion de Sylius 2.0.',
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
