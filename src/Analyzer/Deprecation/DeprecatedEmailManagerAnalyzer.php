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
 * Analyseur des email managers deprecies de Sylius.
 * Les interfaces OrderEmailManagerInterface et ContactEmailManagerInterface du ShopBundle
 * sont depreciees dans Sylius 2.0 et doivent etre remplacees par le nouveau systeme de notifications.
 */
final class DeprecatedEmailManagerAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par email manager deprecie */
    private const MINUTES_PER_MANAGER = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Interfaces d'email managers depreciees */
    private const DEPRECATED_INTERFACES = [
        'OrderEmailManagerInterface' => 'Sylius\\Bundle\\ShopBundle\\EmailManager\\OrderEmailManagerInterface',
        'ContactEmailManagerInterface' => 'Sylius\\Bundle\\ShopBundle\\EmailManager\\ContactEmailManagerInterface',
    ];

    public function getName(): string
    {
        return 'Deprecated Email Manager';
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
            foreach (self::DEPRECATED_INTERFACES as $shortName => $fqcn) {
                if (str_contains($content, $shortName)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $managerCount = 0;

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

            /* Visiteur pour detecter les references aux email managers deprecies */
            $deprecatedInterfaces = self::DEPRECATED_INTERFACES;
            $visitor = new class ($deprecatedInterfaces) extends NodeVisitorAbstract {
                /** @var list<array{interface: string, fqcn: string, line: int, type: string}> */
                public array $usages = [];

                /** @param array<string, string> $deprecatedInterfaces */
                public function __construct(private readonly array $deprecatedInterfaces)
                {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection dans les imports (use statements) */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $fullName = $use->name->toString();
                            foreach ($this->deprecatedInterfaces as $shortName => $fqcn) {
                                if ($fullName === $fqcn || str_ends_with($fullName, '\\' . $shortName)) {
                                    $this->usages[] = [
                                        'interface' => $shortName,
                                        'fqcn' => $fqcn,
                                        'line' => $node->getStartLine(),
                                        'type' => 'import',
                                    ];
                                }
                            }
                        }
                    }

                    /* Detection dans les type hints et implementations */
                    if ($node instanceof Node\Name) {
                        $name = $node->toString();
                        foreach ($this->deprecatedInterfaces as $shortName => $fqcn) {
                            if ($name === $shortName || $name === $fqcn) {
                                $this->usages[] = [
                                    'interface' => $shortName,
                                    'fqcn' => $fqcn,
                                    'line' => $node->getStartLine(),
                                    'type' => 'usage',
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

            /* Deduplication par fichier : on compte une seule fois par interface par fichier */
            $seenInterfaces = [];
            foreach ($visitor->usages as $usage) {
                $key = $usage['interface'];
                if (isset($seenInterfaces[$key])) {
                    continue;
                }
                $seenInterfaces[$key] = true;

                $managerCount++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Utilisation de %s depreciee dans %s', $usage['interface'], $file->getRelativePathname()),
                    detail: sprintf(
                        'L\'interface %s est depreciee dans Sylius 2.0. '
                        . 'Elle doit etre remplacee par le nouveau systeme de notifications email.',
                        $usage['fqcn'],
                    ),
                    suggestion: sprintf(
                        'Remplacer %s par le systeme de notifications Sylius 2.0 '
                        . 'utilisant Symfony Mailer et les events.',
                        $usage['interface'],
                    ),
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_PER_MANAGER,
                ));
            }
        }

        /* Resume global */
        if ($managerCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d utilisation(s) d\'email managers deprecies detectee(s)',
                    $managerCount,
                ),
                detail: 'Les EmailManagers du ShopBundle (OrderEmailManagerInterface, ContactEmailManagerInterface) '
                    . 'sont deprecies et remplaces par le systeme de notifications dans Sylius 2.0.',
                suggestion: 'Migrer vers le nouveau systeme de notifications email base sur Symfony Mailer.',
                docUrl: self::DOC_URL,
            ));
        }
    }
}
