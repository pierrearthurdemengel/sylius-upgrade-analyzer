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
 * Analyseur de la resolution de canal et des contextes de canal/locale.
 *
 * ChannelRepositoryInterface::findOneByHostname(), depreciee depuis Sylius 1.11,
 * est supprimee en 2.0 au profit de findOneEnabledByHostname() : tout appel restant
 * provoque une erreur fatale. Les contextes de canal et de locale personnalises sont
 * signales a titre d'avertissement, leurs dependances ayant change de signature en 2.0.
 */
final class MultiStoreChannelAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par canal avec logique specifique */
    private const MINUTES_PER_CHANNEL = 240;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    public function getName(): string
    {
        return 'Multi-Store Channel';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        return $this->filesContainAny(
            $projectPath . '/src',
            ['*.php'],
            ['findOneByHostname', 'LocaleContextInterface', 'ChannelContextInterface'],
        );
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $channelIssueCount = 0;

        /* Etape 1 : detection des usages de findOneByHostname */
        $channelIssueCount += $this->analyzeFindOneByHostnameUsages($report, $projectPath);

        /* Etape 2 : detection des contextes de locale personnalises */
        $channelIssueCount += $this->analyzeCustomLocaleContexts($report, $projectPath);

        /* Etape 3 : resume global */
        if ($channelIssueCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d probleme(s) lie(s) au multi-store/multi-canal detecte(s)',
                    $channelIssueCount,
                ),
                detail: 'Sylius 2.0 supprime ChannelRepositoryInterface::findOneByHostname() '
                    . 'et modifie les dependances des contextes de canal et de locale.',
                suggestion: 'Remplacer findOneByHostname() par findOneEnabledByHostname() '
                    . 'et verifier les constructeurs des contextes personnalises.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $channelIssueCount * self::MINUTES_PER_CHANNEL,
            ));
        }
    }

    /**
     * Detecte les usages de findOneByHostname() dans les fichiers PHP.
     * Retourne le nombre d'usages trouves.
     */
    private function analyzeFindOneByHostnameUsages(MigrationReport $report, string $projectPath): int
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
            if (!str_contains($code, 'findOneByHostname')) {
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

            /* Visiteur pour detecter les appels a findOneByHostname */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{line: int}> */
                public array $usages = [];

                public function enterNode(Node $node): null
                {
                    /* L'appel nullsafe ($repo?->findOneByHostname()) est un noeud distinct de MethodCall. */
                    if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
                        && $node->name instanceof Node\Identifier
                        && $node->name->toString() === 'findOneByHostname'
                    ) {
                        $this->usages[] = [
                            'line' => $node->getStartLine(),
                        ];
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->usages as $usage) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Utilisation de findOneByHostname() detectee ligne %d dans %s',
                        $usage['line'],
                        $file->getRelativePathname(),
                    ),
                    detail: sprintf(
                        'La methode findOneByHostname() appelee dans %s (ligne %d), depreciee depuis Sylius 1.11, '
                        . 'est supprimee de ChannelRepositoryInterface en 2.0 : tout appel provoque une erreur fatale.',
                        $file->getRelativePathname(),
                        $usage['line'],
                    ),
                    suggestion: 'Remplacer findOneByHostname() par findOneEnabledByHostname(), '
                        . 'qui ne retourne que les canaux actives.',
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Detecte les contextes de locale personnalises dans src/.
     * Retourne le nombre de contextes trouves.
     */
    private function analyzeCustomLocaleContexts(MigrationReport $report, string $projectPath): int
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

            /* Verification rapide pour les contextes de locale */
            if (!str_contains($code, 'LocaleContextInterface')
                && !str_contains($code, 'ChannelContextInterface')
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

            /* Visiteur pour detecter les implementations de contextes de locale/canal */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{class: string, interface: string, line: int}> */
                public array $contexts = [];

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_ || $node->implements === []) {
                        return null;
                    }

                    $className = $node->name !== null ? $node->name->toString() : '(anonyme)';

                    foreach ($node->implements as $interface) {
                        $name = $interface->toString();
                        if (str_contains($name, 'LocaleContextInterface')
                            || str_contains($name, 'ChannelContextInterface')
                        ) {
                            $this->contexts[] = [
                                'class' => $className,
                                'interface' => $name,
                                'line' => $node->getStartLine(),
                            ];
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->contexts as $context) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Contexte personnalise "%s" implementant %s',
                        $context['class'],
                        $context['interface'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s implemente %s. '
                        . 'Les interfaces de contexte de locale/canal changent dans Sylius 2.0.',
                        $context['class'],
                        $file->getRelativePathname(),
                        $context['interface'],
                    ),
                    suggestion: sprintf(
                        'Adapter l\'implementation de %s dans %s '
                        . 'selon les nouvelles interfaces de Sylius 2.0.',
                        $context['interface'],
                        $context['class'],
                    ),
                    file: $filePath,
                    line: $context['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Indique si au moins un fichier du repertoire, filtre par motifs de nom,
     * contient l'un des fragments donnes.
     *
     * @param list<string> $namePatterns Motifs de nom de fichier (ex. *.php)
     * @param list<string> $needles      Fragments recherches
     */
    private function filesContainAny(string $directory, array $namePatterns, array $needles): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($directory)->name($namePatterns);

        foreach ($finder as $file) {
            $content = $file->getContents();
            foreach ($needles as $needle) {
                if (str_contains($content, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
