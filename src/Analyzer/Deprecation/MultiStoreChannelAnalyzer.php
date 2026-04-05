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
 * Analyseur des configurations multi-boutique et multi-canal.
 * Sylius 2.0 modifie la gestion des canaux et du multi-store.
 * Cet analyseur detecte les configurations multi-canaux, les usages de findOneByHostname
 * et les contextes de locale personnalises.
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
        $configDir = $report->getProjectPath() . '/config';
        if (!is_dir($configDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());
            if (str_contains($content, 'sylius_channel') || str_contains($content, 'channels:')) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $channelIssueCount = 0;

        /* Etape 1 : detection des configurations multi-canaux */
        $channelIssueCount += $this->analyzeChannelConfiguration($report, $projectPath);

        /* Etape 2 : detection des usages de findOneByHostname */
        $channelIssueCount += $this->analyzeFindOneByHostnameUsages($report, $projectPath);

        /* Etape 3 : detection des contextes de locale personnalises */
        $channelIssueCount += $this->analyzeCustomLocaleContexts($report, $projectPath);

        /* Etape 4 : resume global */
        if ($channelIssueCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d probleme(s) lie(s) au multi-store/multi-canal detecte(s)',
                    $channelIssueCount,
                ),
                detail: 'Sylius 2.0 modifie la gestion des canaux et du multi-store. '
                    . 'Les configurations multi-canaux, les usages de findOneByHostname '
                    . 'et les contextes de locale personnalises doivent etre adaptes.',
                suggestion: 'Revoir la logique multi-canal pour utiliser les nouvelles API '
                    . 'de gestion des canaux de Sylius 2.0.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $channelIssueCount * self::MINUTES_PER_CHANNEL,
            ));
        }
    }

    /**
     * Analyse la configuration des canaux dans config/.
     * Detecte les configurations multi-canaux.
     * Retourne le nombre de canaux detectes.
     */
    private function analyzeChannelConfiguration(MigrationReport $report, string $projectPath): int
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

            /* Detection de la configuration sylius_channel */
            $channelConfig = $config['sylius_channel'] ?? null;
            if (!is_array($channelConfig)) {
                continue;
            }

            /* Detection des definitions de canaux */
            $channels = $channelConfig['channels'] ?? $channelConfig['resources'] ?? [];
            if (!is_array($channels) || count($channels) <= 1) {
                continue;
            }

            $channelCount = count($channels);
            $count += $channelCount;

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    'Configuration multi-canal detectee avec %d canaux dans %s',
                    $channelCount,
                    $file->getRelativePathname(),
                ),
                detail: sprintf(
                    'Le fichier %s definit %d canaux. Les configurations multi-canaux '
                    . 'sont impactees par les changements de Sylius 2.0.',
                    $file->getRelativePathname(),
                    $channelCount,
                ),
                suggestion: 'Revoir la configuration multi-canal pour s\'assurer de la compatibilite '
                    . 'avec le nouveau systeme de gestion des canaux de Sylius 2.0.',
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
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
                    if ($node instanceof Node\Expr\MethodCall
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
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Utilisation de findOneByHostname() detectee ligne %d dans %s',
                        $usage['line'],
                        $file->getRelativePathname(),
                    ),
                    detail: sprintf(
                        'La methode findOneByHostname() dans %s (ligne %d) est depreciee dans Sylius 2.0. '
                        . 'Le mecanisme de resolution de canal par hostname a change.',
                        $file->getRelativePathname(),
                        $usage['line'],
                    ),
                    suggestion: 'Remplacer findOneByHostname() par la nouvelle methode '
                        . 'de resolution de canal de Sylius 2.0.',
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
}
