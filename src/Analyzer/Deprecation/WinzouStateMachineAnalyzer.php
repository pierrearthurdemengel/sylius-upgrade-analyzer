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
 * Analyseur de l'utilisation de winzou/state-machine-bundle.
 * Sylius 2.0 remplace winzou par le composant Workflow de Symfony.
 * Cet analyseur detecte les configurations et usages de winzou pour estimer l'effort de migration.
 */
final class WinzouStateMachineAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par definition de machine a etats */
    private const MINUTES_PER_STATE_MACHINE = 240;

    public function getName(): string
    {
        return 'Winzou State Machine';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification de la dependance dans composer.json */
        $composerJsonPath = $projectPath . '/composer.json';
        if (file_exists($composerJsonPath)) {
            $composerData = json_decode((string) file_get_contents($composerJsonPath), true);
            if (isset($composerData['require']['winzou/state-machine-bundle'])) {
                return true;
            }
        }

        /* Verification de la presence d'un fichier de configuration winzou */
        $configDir = $projectPath . '/config/packages';
        if (is_dir($configDir)) {
            $finder = new Finder();
            $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

            foreach ($finder as $file) {
                $content = (string) file_get_contents($file->getRealPath());
                if (str_contains($content, 'winzou_state_machine')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        /* Etape 1 : verification dans composer.json */
        $this->analyzeComposerJson($report, $projectPath);

        /* Etape 2 : analyse des fichiers de configuration YAML */
        $stateMachineCount = $this->analyzeYamlConfigurations($report, $projectPath);

        /* Etape 3 : analyse des fichiers PHP pour les usages directs */
        $this->analyzePhpUsages($report, $projectPath);

        /* Etape 4 : ajout d'un probleme global avec estimation */
        if ($stateMachineCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d machine(s) a etats winzou detectee(s) necessitant une migration vers Symfony Workflow',
                    $stateMachineCount,
                ),
                detail: sprintf(
                    'Chaque machine a etats doit etre convertie en definition Symfony Workflow. '
                    . 'Les callbacks winzou doivent etre remplaces par des listeners/subscribers Symfony.',
                ),
                suggestion: 'Migrer chaque definition winzou_state_machine vers framework.workflows '
                    . 'et convertir les callbacks en event subscribers Symfony.',
                estimatedMinutes: $stateMachineCount * self::MINUTES_PER_STATE_MACHINE,
            ));
        }
    }

    /**
     * Verifie la presence de winzou/state-machine-bundle dans composer.json.
     */
    private function analyzeComposerJson(MigrationReport $report, string $projectPath): void
    {
        $composerJsonPath = $projectPath . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);

        if (isset($composerData['require']['winzou/state-machine-bundle'])) {
            $version = $composerData['require']['winzou/state-machine-bundle'];
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: 'Dependance winzou/state-machine-bundle detectee dans composer.json',
                detail: sprintf(
                    'La version %s de winzou/state-machine-bundle est installee. '
                    . 'Ce bundle est remplace par le composant Workflow de Symfony dans Sylius 2.0.',
                    $version,
                ),
                suggestion: 'Remplacer winzou/state-machine-bundle par symfony/workflow '
                    . 'et adapter toutes les configurations et usages.',
                file: $composerJsonPath,
            ));
        }
    }

    /**
     * Analyse les fichiers YAML pour les definitions de machines a etats winzou.
     * Retourne le nombre de machines a etats trouvees.
     */
    private function analyzeYamlConfigurations(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config/packages';
        if (!is_dir($configDir)) {
            return 0;
        }

        $stateMachineCount = 0;
        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

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

            if (!is_array($config) || !isset($config['winzou_state_machine'])) {
                continue;
            }

            $stateMachines = $config['winzou_state_machine'];
            if (!is_array($stateMachines)) {
                continue;
            }

            /* Chaque cle de premier niveau sous winzou_state_machine est une definition de SM */
            foreach (array_keys($stateMachines) as $smName) {
                $stateMachineCount++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Machine a etats winzou "%s" a migrer vers Symfony Workflow', $smName),
                    detail: sprintf(
                        'La machine a etats "%s" definie dans %s doit etre convertie '
                        . 'en definition Symfony Workflow avec ses transitions et callbacks.',
                        $smName,
                        $file->getRelativePathname(),
                    ),
                    suggestion: sprintf(
                        'Creer une definition framework.workflows.%s equivalente '
                        . 'et migrer les callbacks en event subscribers.',
                        $smName,
                    ),
                    file: $filePath,
                ));
            }
        }

        return $stateMachineCount;
    }

    /**
     * Analyse les fichiers PHP pour detecter les usages de classes winzou.
     */
    private function analyzePhpUsages(MigrationReport $report, string $projectPath): void
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

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

            /* Recherche des utilisations des classes winzou via un visiteur de noeuds */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int}> */
                public array $usages = [];

                public function enterNode(Node $node): null
                {
                    /* Detection des imports (use statements) */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $fullName = $use->name->toString();
                            if (str_starts_with($fullName, 'SM\\')) {
                                $this->usages[] = [
                                    'class' => $fullName,
                                    'line' => $node->getStartLine(),
                                ];
                            }
                        }
                    }

                    /* Detection des references de noms complets (FQCN) */
                    if ($node instanceof Node\Name\FullyQualified) {
                        $fullName = $node->toString();
                        if (str_starts_with($fullName, 'SM\\')) {
                            $this->usages[] = [
                                'class' => $fullName,
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

            /* Creation d'un probleme pour chaque usage detecte */
            foreach ($visitor->usages as $usage) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Utilisation de la classe winzou %s detectee', $usage['class']),
                    detail: sprintf(
                        'La classe %s est utilisee dans %s. '
                        . 'Elle doit etre remplacee par les equivalents Symfony Workflow.',
                        $usage['class'],
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Remplacer les references SM\\Factory\\Factory par '
                        . 'Symfony\\Component\\Workflow\\Registry et adapter le code.',
                    file: $filePath,
                    line: $usage['line'],
                ));
            }
        }
    }
}
