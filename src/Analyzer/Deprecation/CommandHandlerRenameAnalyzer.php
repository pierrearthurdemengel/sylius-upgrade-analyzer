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
 * Analyseur des renommages de commandes et handlers.
 * Sylius 2.0 renomme le repertoire Message/ en Command/ et les classes *MessageHandler en *CommandHandler.
 * Cet analyseur detecte les anciennes conventions de nommage a adapter.
 */
final class CommandHandlerRenameAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par fichier necessitant un renommage */
    private const MINUTES_PER_FILE = 30;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    public function getName(): string
    {
        return 'Command Handler Rename';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification de l'existence du repertoire src/Message/ */
        if (is_dir($projectPath . '/src/Message')) {
            return true;
        }

        /* Verification de la presence de classes *MessageHandler dans src/ */
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*MessageHandler.php');

        return $finder->hasResults();
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $fileCount = 0;

        /* Etape 1 : detection du repertoire src/Message/ */
        $fileCount += $this->analyzeMessageDirectory($report, $projectPath);

        /* Etape 2 : detection des classes *MessageHandler */
        $fileCount += $this->analyzeMessageHandlerClasses($report, $projectPath);

        /* Etape 3 : detection des injections de MessageDispatcherInterface */
        $fileCount += $this->analyzeMessageDispatcherUsages($report, $projectPath);

        /* Etape 4 : resume global */
        if ($fileCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d fichier(s) necessitant un renommage Command/Handler detecte(s)',
                    $fileCount,
                ),
                detail: 'Sylius 2.0 renomme le namespace Message en Command et les classes '
                    . '*MessageHandler en *CommandHandler. Les injections de MessageDispatcherInterface '
                    . 'doivent etre remplacees par CommandDispatcherInterface.',
                suggestion: 'Renommer le repertoire src/Message/ en src/Command/, renommer les classes '
                    . '*MessageHandler en *CommandHandler et remplacer MessageDispatcherInterface '
                    . 'par CommandDispatcherInterface.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $fileCount * self::MINUTES_PER_FILE,
            ));
        }
    }

    /**
     * Detecte le repertoire src/Message/ qui doit etre renomme en src/Command/.
     * Retourne le nombre de fichiers PHP dans ce repertoire.
     */
    private function analyzeMessageDirectory(MigrationReport $report, string $projectPath): int
    {
        $messageDir = $projectPath . '/src/Message';
        if (!is_dir($messageDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($messageDir)->name('*.php');

        $count = 0;
        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $count++;
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf('Fichier dans src/Message/ a deplacer : %s', $file->getRelativePathname()),
                detail: sprintf(
                    'Le fichier %s se trouve dans le repertoire src/Message/ qui doit etre renomme en src/Command/ dans Sylius 2.0.',
                    $file->getRelativePathname(),
                ),
                suggestion: 'Deplacer ce fichier dans src/Command/ et mettre a jour le namespace.',
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Detecte les classes *MessageHandler a renommer en *CommandHandler.
     * Retourne le nombre de classes detectees.
     */
    private function analyzeMessageHandlerClasses(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*MessageHandler.php');

        $count = 0;
        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $count++;
            $baseName = $file->getFilenameWithoutExtension();
            $newName = str_replace('MessageHandler', 'CommandHandler', $baseName);

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf('Classe %s a renommer en %s', $baseName, $newName),
                detail: sprintf(
                    'La classe %s dans %s utilise l\'ancienne convention *MessageHandler. '
                    . 'Dans Sylius 2.0, les handlers sont nommes *CommandHandler.',
                    $baseName,
                    $file->getRelativePathname(),
                ),
                suggestion: sprintf('Renommer %s en %s et mettre a jour les references.', $baseName, $newName),
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Detecte les injections de MessageDispatcherInterface dans les fichiers PHP.
     * Retourne le nombre d'usages trouves.
     */
    private function analyzeMessageDispatcherUsages(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $totalUsages = 0;

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

            /* Visiteur pour detecter les usages de MessageDispatcherInterface */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{name: string, line: int}> */
                public array $usages = [];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Name) {
                        $name = $node->toString();
                        if (str_contains($name, 'MessageDispatcherInterface')) {
                            $this->usages[] = [
                                'name' => $name,
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
                $totalUsages++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Injection de MessageDispatcherInterface detectee ligne %d', $usage['line']),
                    detail: sprintf(
                        'Le fichier %s utilise MessageDispatcherInterface qui doit etre remplacee '
                        . 'par CommandDispatcherInterface dans Sylius 2.0.',
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Remplacer MessageDispatcherInterface par CommandDispatcherInterface.',
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $totalUsages;
    }
}
