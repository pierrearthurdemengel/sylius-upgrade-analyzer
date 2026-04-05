<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Api;

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
 * Analyseur des signatures de QueryExtension API Platform.
 * Dans Sylius 2.0 / API Platform 4, la signature des methodes de
 * QueryCollectionExtensionInterface et QueryItemExtensionInterface change :
 * le parametre `string $operationName = null` est remplace par
 * `\ApiPlatform\Metadata\Operation $operation = null`.
 * Cet analyseur detecte les classes utilisant l'ancienne signature.
 */
final class ApiQueryExtensionSignatureAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par classe d'extension a migrer */
    private const MINUTES_PER_EXTENSION = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Interfaces d'extension de requetes API Platform */
    private const EXTENSION_INTERFACES = [
        'QueryCollectionExtensionInterface',
        'QueryItemExtensionInterface',
    ];

    public function getName(): string
    {
        return 'API Query Extension Signature';
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
            foreach (self::EXTENSION_INTERFACES as $interface) {
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
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $extensionCount = 0;

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

            /* Visiteur pour detecter les interfaces et le parametre $operationName */
            $extensionInterfaces = self::EXTENSION_INTERFACES;
            $visitor = new class ($extensionInterfaces) extends NodeVisitorAbstract {
                /** @var list<array{interface: string, line: int}> */
                public array $extensionUsages = [];

                /** @var list<array{method: string, line: int}> */
                public array $oldSignatures = [];

                /** @param list<string> $extensionInterfaces */
                public function __construct(private readonly array $extensionInterfaces)
                {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection des imports d'interfaces d'extension */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $fullName = $use->name->toString();
                            foreach ($this->extensionInterfaces as $interface) {
                                if (str_ends_with($fullName, $interface)) {
                                    $this->extensionUsages[] = [
                                        'interface' => $interface,
                                        'line' => $node->getStartLine(),
                                    ];
                                }
                            }
                        }
                    }

                    /* Detection du parametre $operationName dans les methodes */
                    if ($node instanceof Node\Stmt\ClassMethod) {
                        foreach ($node->params as $param) {
                            if ($param->var instanceof Node\Expr\Variable
                                && $param->var->name === 'operationName'
                            ) {
                                $this->oldSignatures[] = [
                                    'method' => $node->name->name,
                                    'line' => $param->getStartLine(),
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

            /* On ne signale un probleme que si la classe implemente une interface d'extension ET utilise $operationName */
            if ($visitor->extensionUsages === []) {
                continue;
            }

            foreach ($visitor->oldSignatures as $signature) {
                $extensionCount++;
                $interfaceNames = array_map(
                    static fn (array $usage): string => $usage['interface'],
                    $visitor->extensionUsages,
                );

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::API,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Ancienne signature $operationName detectee dans %s::%s()',
                        $file->getFilenameWithoutExtension(),
                        $signature['method'],
                    ),
                    detail: sprintf(
                        'Le fichier %s implemente %s et utilise le parametre $operationName '
                        . 'dans la methode %s() (ligne %d). Dans Sylius 2.0 / API Platform 4, '
                        . 'ce parametre est remplace par \ApiPlatform\Metadata\Operation $operation.',
                        $file->getRelativePathname(),
                        implode(', ', $interfaceNames),
                        $signature['method'],
                        $signature['line'],
                    ),
                    suggestion: 'Remplacer le parametre `string $operationName = null` par '
                        . '`\ApiPlatform\Metadata\Operation $operation = null` dans la methode '
                        . $signature['method'] . '().',
                    file: $filePath,
                    line: $signature['line'],
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_PER_EXTENSION,
                ));
            }
        }

        /* Resume global */
        if ($extensionCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::API,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d extension(s) de requete API Platform avec ancienne signature detectee(s)',
                    $extensionCount,
                ),
                detail: 'Les extensions de requete API Platform (QueryCollectionExtensionInterface, '
                    . 'QueryItemExtensionInterface) utilisent l\'ancienne signature avec $operationName. '
                    . 'Dans Sylius 2.0 / API Platform 4, le parametre doit etre de type '
                    . '\ApiPlatform\Metadata\Operation.',
                suggestion: 'Mettre a jour la signature de chaque extension de requete pour utiliser '
                    . '\ApiPlatform\Metadata\Operation au lieu de string $operationName.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $extensionCount * self::MINUTES_PER_EXTENSION,
            ));
        }
    }
}
