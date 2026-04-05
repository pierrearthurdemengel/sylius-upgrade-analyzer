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
 * Analyseur des renommages de bus de messages Sylius.
 * Sylius 2.0 renomme sylius_default.bus en sylius.command_bus et sylius_event.bus en sylius.event_bus.
 * Cet analyseur detecte les references aux anciens noms de bus dans le code source et la configuration.
 */
final class MessageBusRenameAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par reference incorrecte de bus */
    private const MINUTES_PER_REFERENCE = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Anciens noms de bus recherches dans les fichiers */
    private const BUS_STRINGS = [
        'sylius_default.bus',
        'sylius_event.bus',
    ];

    /** Anciens noms de variables PHP references dans le code source */
    private const BUS_VARIABLES = [
        '$syliusDefaultBus',
        '$syliusEventBus',
    ];

    public function getName(): string
    {
        return 'Message Bus Rename';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification dans les fichiers src/ et config/ */
        $dirs = [];
        if (is_dir($projectPath . '/src')) {
            $dirs[] = $projectPath . '/src';
        }
        if (is_dir($projectPath . '/config')) {
            $dirs[] = $projectPath . '/config';
        }

        if ($dirs === []) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($dirs)->name('*.php')->name('*.yaml')->name('*.yml')->name('*.xml');

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());
            foreach (self::BUS_STRINGS as $busString) {
                if (str_contains($content, $busString)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $referenceCount = 0;

        /* Etape 1 : analyse des fichiers de configuration */
        $referenceCount += $this->analyzeConfigFiles($report, $projectPath);

        /* Etape 2 : analyse des fichiers PHP pour les noms de bus et variables */
        $referenceCount += $this->analyzePhpFiles($report, $projectPath);

        /* Etape 3 : resume global */
        if ($referenceCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d reference(s) aux anciens noms de bus de messages detectee(s)',
                    $referenceCount,
                ),
                detail: 'Les bus de messages Sylius ont ete renommes dans Sylius 2.0. '
                    . 'sylius_default.bus devient sylius.command_bus et sylius_event.bus devient sylius.event_bus.',
                suggestion: 'Renommer toutes les references : sylius_default.bus -> sylius.command_bus, '
                    . 'sylius_event.bus -> sylius.event_bus, $syliusDefaultBus -> $syliusCommandBus, '
                    . '$syliusEventBus -> $syliusEventBus.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $referenceCount * self::MINUTES_PER_REFERENCE,
            ));
        }
    }

    /**
     * Analyse les fichiers de configuration YAML et XML dans config/.
     * Retourne le nombre de references trouvees.
     */
    private function analyzeConfigFiles(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return 0;
        }

        $count = 0;
        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml')->name('*.xml');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);

            foreach (self::BUS_STRINGS as $busString) {
                if (str_contains($content, $busString)) {
                    $count++;
                    $replacement = $busString === 'sylius_default.bus'
                        ? 'sylius.command_bus'
                        : 'sylius.event_bus';

                    $report->addIssue(new MigrationIssue(
                        severity: Severity::WARNING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf('Reference a "%s" detectee dans %s', $busString, $file->getRelativePathname()),
                        detail: sprintf(
                            'Le bus "%s" a ete renomme en "%s" dans Sylius 2.0.',
                            $busString,
                            $replacement,
                        ),
                        suggestion: sprintf('Remplacer "%s" par "%s".', $busString, $replacement),
                        file: $filePath,
                        docUrl: self::DOC_URL,
                    ));
                }
            }
        }

        return $count;
    }

    /**
     * Analyse les fichiers PHP dans src/ pour les references aux anciens bus.
     * Retourne le nombre de references trouvees.
     */
    private function analyzePhpFiles(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $totalReferences = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            /* Recherche directe des chaines de bus dans le contenu brut */
            foreach (self::BUS_STRINGS as $busString) {
                if (str_contains($code, $busString)) {
                    $totalReferences++;
                    $replacement = $busString === 'sylius_default.bus'
                        ? 'sylius.command_bus'
                        : 'sylius.event_bus';

                    $report->addIssue(new MigrationIssue(
                        severity: Severity::WARNING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf('Reference a "%s" dans %s', $busString, $file->getRelativePathname()),
                        detail: sprintf(
                            'Le fichier PHP %s contient une reference au bus "%s" qui a ete renomme.',
                            $file->getRelativePathname(),
                            $busString,
                        ),
                        suggestion: sprintf('Remplacer "%s" par "%s".', $busString, $replacement),
                        file: $filePath,
                        docUrl: self::DOC_URL,
                    ));
                }
            }

            /* Analyse AST pour les noms de variables */
            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $busVariables = self::BUS_VARIABLES;
            $visitor = new class ($busVariables) extends NodeVisitorAbstract {
                /** @var list<array{variable: string, line: int}> */
                public array $usages = [];

                /** @param list<string> $targetVariables */
                public function __construct(private readonly array $targetVariables)
                {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection des variables et parametres correspondant aux anciens noms */
                    if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                        $varName = '$' . $node->name;
                        if (in_array($varName, $this->targetVariables, true)) {
                            $this->usages[] = [
                                'variable' => $varName,
                                'line' => $node->getStartLine(),
                            ];
                        }
                    }

                    /* Detection dans les parametres de methodes/constructeur */
                    if ($node instanceof Node\Param && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                        $varName = '$' . $node->var->name;
                        if (in_array($varName, $this->targetVariables, true)) {
                            $this->usages[] = [
                                'variable' => $varName,
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

            foreach ($visitor->usages as $usage) {
                $totalReferences++;
                $replacement = $usage['variable'] === '$syliusDefaultBus'
                    ? '$syliusCommandBus'
                    : '$syliusEventBus';

                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Variable "%s" detectee ligne %d', $usage['variable'], $usage['line']),
                    detail: sprintf(
                        'La variable %s dans %s doit etre renommee en %s.',
                        $usage['variable'],
                        $file->getRelativePathname(),
                        $replacement,
                    ),
                    suggestion: sprintf('Renommer %s en %s.', $usage['variable'], $replacement),
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $totalReferences;
    }
}
