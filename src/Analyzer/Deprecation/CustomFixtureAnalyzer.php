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
 * Analyseur des fixtures personnalisees.
 * Sylius 2.0 modifie le systeme de fixtures et le bundle sylius_fixtures.
 * Les fixtures personnalisees et leur configuration doivent etre adaptees.
 */
final class CustomFixtureAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par suite de fixtures */
    private const MINUTES_PER_FIXTURE_SUITE = 120;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    public function getName(): string
    {
        return 'Custom Fixture';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification du repertoire src/DataFixtures/ */
        if (is_dir($projectPath . '/src/DataFixtures')) {
            return true;
        }

        /* Verification du fichier de configuration sylius_fixtures */
        if (file_exists($projectPath . '/config/packages/sylius_fixtures.yaml')
            || file_exists($projectPath . '/config/packages/sylius_fixtures.yml')
        ) {
            return true;
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $fixtureCount = 0;

        /* Etape 1 : analyse des classes de fixtures dans src/DataFixtures/ */
        $fixtureCount += $this->analyzeFixtureClasses($report, $projectPath);

        /* Etape 2 : analyse de la configuration sylius_fixtures */
        $fixtureCount += $this->analyzeFixtureConfiguration($report, $projectPath);

        /* Etape 3 : detection des classes implementant FixtureInterface dans src/ */
        $fixtureCount += $this->analyzeFixtureInterfaces($report, $projectPath);

        /* Etape 4 : resume global */
        if ($fixtureCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d fixture(s) personnalisee(s) detectee(s)',
                    $fixtureCount,
                ),
                detail: 'Sylius 2.0 modifie le systeme de fixtures. Les fixtures personnalisees '
                    . 'et la configuration sylius_fixtures doivent etre adaptees au nouveau systeme.',
                suggestion: 'Migrer les fixtures vers le nouveau systeme de Sylius 2.0 '
                    . 'ou utiliser le systeme de fixtures Doctrine standard.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $fixtureCount * self::MINUTES_PER_FIXTURE_SUITE,
            ));
        }
    }

    /**
     * Analyse les classes de fixtures dans src/DataFixtures/.
     * Retourne le nombre de classes de fixtures detectees.
     */
    private function analyzeFixtureClasses(MigrationReport $report, string $projectPath): int
    {
        $fixturesDir = $projectPath . '/src/DataFixtures';
        if (!is_dir($fixturesDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($fixturesDir)->name('*.php');

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
                message: sprintf('Classe de fixture detectee : %s', $file->getRelativePathname()),
                detail: sprintf(
                    'Le fichier %s dans src/DataFixtures/ contient une fixture qui peut '
                    . 'necessiter des adaptations pour Sylius 2.0.',
                    $file->getRelativePathname(),
                ),
                suggestion: 'Verifier la compatibilite de cette fixture avec le nouveau '
                    . 'systeme de fixtures de Sylius 2.0.',
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Analyse la configuration sylius_fixtures dans config/packages/.
     * Retourne le nombre de suites de fixtures configurees.
     */
    private function analyzeFixtureConfiguration(MigrationReport $report, string $projectPath): int
    {
        $configFiles = [
            $projectPath . '/config/packages/sylius_fixtures.yaml',
            $projectPath . '/config/packages/sylius_fixtures.yml',
        ];

        $count = 0;

        foreach ($configFiles as $configFile) {
            if (!file_exists($configFile)) {
                continue;
            }

            try {
                $config = Yaml::parseFile($configFile);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($config) || !isset($config['sylius_fixtures'])) {
                continue;
            }

            $fixturesConfig = $config['sylius_fixtures'];
            if (!is_array($fixturesConfig)) {
                continue;
            }

            /* Detection des suites de fixtures */
            $suites = $fixturesConfig['suites'] ?? [];
            if (!is_array($suites)) {
                continue;
            }

            foreach (array_keys($suites) as $suiteName) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Suite de fixtures "%s" configuree dans sylius_fixtures', $suiteName),
                    detail: sprintf(
                        'La suite de fixtures "%s" definie dans %s utilise le systeme sylius_fixtures '
                        . 'qui est modifie dans Sylius 2.0.',
                        $suiteName,
                        basename($configFile),
                    ),
                    suggestion: sprintf(
                        'Adapter la suite de fixtures "%s" au nouveau systeme de fixtures de Sylius 2.0.',
                        $suiteName,
                    ),
                    file: $configFile,
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Detecte les classes implementant FixtureInterface de Sylius dans src/.
     * Retourne le nombre de classes detectees.
     */
    private function analyzeFixtureInterfaces(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        /* Exclure src/DataFixtures/ deja traite */
        if (is_dir($projectPath . '/src/DataFixtures')) {
            $finder->exclude('DataFixtures');
        }

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            /* Verification rapide */
            if (!str_contains($code, 'FixtureInterface') && !str_contains($code, 'AbstractFixture')) {
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

            /* Visiteur pour detecter les classes implementant FixtureInterface ou etendant AbstractFixture */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int, type: string}> */
                public array $fixtures = [];

                /** Indique si le fichier importe une interface/classe Sylius de fixtures */
                private bool $importsSyliusFixture = false;

                public function enterNode(Node $node): null
                {
                    /* Detection de l'import Sylius */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $fullName = $use->name->toString();
                            if (str_contains($fullName, 'Sylius')
                                && (str_contains($fullName, 'FixtureInterface')
                                    || str_contains($fullName, 'AbstractFixture'))
                            ) {
                                $this->importsSyliusFixture = true;
                            }
                        }
                    }

                    if (!$node instanceof Node\Stmt\Class_ || !$this->importsSyliusFixture) {
                        return null;
                    }

                    $className = $node->name !== null ? $node->name->toString() : '(anonyme)';

                    /* Detection des implementations de FixtureInterface */
                    foreach ($node->implements as $interface) {
                        if (str_contains($interface->toString(), 'FixtureInterface')) {
                            $this->fixtures[] = [
                                'class' => $className,
                                'line' => $node->getStartLine(),
                                'type' => 'implements',
                            ];
                        }
                    }

                    /* Detection de l'heritage d'AbstractFixture */
                    if ($node->extends !== null && str_contains($node->extends->toString(), 'AbstractFixture')) {
                        $this->fixtures[] = [
                            'class' => $className,
                            'line' => $node->getStartLine(),
                            'type' => 'extends',
                        ];
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->fixtures as $fixture) {
                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Classe de fixture Sylius "%s" detectee', $fixture['class']),
                    detail: sprintf(
                        'La classe %s dans %s %s une interface/classe de fixture Sylius. '
                        . 'Le systeme de fixtures est modifie dans Sylius 2.0.',
                        $fixture['class'],
                        $file->getRelativePathname(),
                        $fixture['type'] === 'implements' ? 'implemente' : 'etend',
                    ),
                    suggestion: sprintf(
                        'Adapter la fixture "%s" au nouveau systeme de fixtures de Sylius 2.0.',
                        $fixture['class'],
                    ),
                    file: $filePath,
                    line: $fixture['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }
}
