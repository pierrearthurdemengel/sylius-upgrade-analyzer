<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
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
    /**
     * Estimation en minutes par suite de fixtures ou par classe de fixture Sylius.
     * Les fixtures Doctrine pures ne sont pas comptees : DoctrineFixturesBundle
     * n'est pas touche par la migration Sylius.
     */
    private const MINUTES_PER_FIXTURE_SUITE = 120;

    /**
     * Espaces de noms des fixtures Sylius (FixtureInterface, AbstractFixture,
     * AbstractResourceFixture et fixtures du coeur). Une classe qui en implemente
     * ou en etend une depend du systeme sylius_fixtures.
     */
    private const SYLIUS_FIXTURE_NAMESPACES = [
        'Sylius\\Bundle\\FixturesBundle\\Fixture\\',
        'Sylius\\Bundle\\CoreBundle\\Fixture\\',
    ];

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

        /* Une fixture Sylius peut vivre ailleurs que dans src/DataFixtures/ (src/Fixture/...)
           sans configuration dediee : sa seule presence justifie l'analyse. */
        return $this->srcReferencesSyliusFixtures($projectPath . '/src');
    }

    /**
     * Indique si un fichier PHP de src/ fait reference a un espace de noms de fixtures Sylius.
     * Simple recherche textuelle : l'analyse proprement dite verifie ensuite l'heritage reel.
     */
    private function srcReferencesSyliusFixtures(string $srcDir): bool
    {
        if (!is_dir($srcDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());
            foreach (self::SYLIUS_FIXTURE_NAMESPACES as $namespace) {
                if (str_contains($content, $namespace)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $fixtureCount = 0;

        /* Etape 1 : analyse de la configuration sylius_fixtures */
        $fixtureCount += $this->analyzeFixtureConfiguration($report, $projectPath);

        /* Etape 2 : detection des fixtures Sylius dans tout src/, src/DataFixtures/ compris.
           Un simple fichier de src/DataFixtures/ n'est plus compte : c'est le plus souvent
           une fixture Doctrine, sans lien avec la migration. */
        $fixtureCount += $this->analyzeFixtureInterfaces($report, $projectPath);

        /* Etape 3 : resume global */
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

        /* Symfony charge a la fois le .yaml et le .yml et fusionne leurs suites :
           une suite presente dans les deux fichiers n'existe qu'une fois. */
        $seenSuites = [];

        foreach ($configFiles as $configFile) {
            if (!file_exists($configFile)) {
                continue;
            }

            try {
                $config = Yaml::parseFile($configFile, Yaml::PARSE_CUSTOM_TAGS);
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
                if (isset($seenSuites[$suiteName])) {
                    continue;
                }

                $seenSuites[$suiteName] = true;
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
     * Detecte les classes de src/ (src/DataFixtures/ compris) qui implementent ou etendent
     * une fixture Sylius.
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

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            /* Verification rapide : toute reference a une fixture Sylius passe par un espace de noms en "Fixture" */
            if (!str_contains($code, 'Fixture')) {
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

            /* Visiteur pour detecter les classes implementant ou etendant une fixture Sylius.
               Le NameResolver passe avant lui : les noms compares sont pleinement qualifies,
               qu'ils soient importes, aliases ou ecrits en \Sylius\... sans use. */
            $visitor = new class (self::SYLIUS_FIXTURE_NAMESPACES) extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int, type: string}> */
                public array $fixtures = [];

                /**
                 * @param list<string> $syliusFixtureNamespaces Espaces de noms des fixtures Sylius
                 */
                public function __construct(private readonly array $syliusFixtureNamespaces)
                {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_) {
                        return null;
                    }

                    $className = $node->name !== null ? $node->name->toString() : '(anonyme)';

                    /* Detection des implementations d'une interface de fixture Sylius */
                    foreach ($node->implements as $interface) {
                        if ($this->isSyliusFixture($interface->toString())) {
                            $this->fixtures[] = [
                                'class' => $className,
                                'line' => $node->getStartLine(),
                                'type' => 'implements',
                            ];
                        }
                    }

                    /* Detection de l'heritage d'une classe de fixture Sylius */
                    if ($node->extends !== null && $this->isSyliusFixture($node->extends->toString())) {
                        $this->fixtures[] = [
                            'class' => $className,
                            'line' => $node->getStartLine(),
                            'type' => 'extends',
                        ];
                    }

                    return null;
                }

                /** Indique si le nom pleinement qualifie appartient a un espace de noms de fixtures Sylius. */
                private function isSyliusFixture(string $fullName): bool
                {
                    foreach ($this->syliusFixtureNamespaces as $namespace) {
                        if (str_starts_with($fullName, $namespace)) {
                            return true;
                        }
                    }

                    return false;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
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
