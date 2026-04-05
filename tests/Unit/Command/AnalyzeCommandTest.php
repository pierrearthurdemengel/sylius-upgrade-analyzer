<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig\TwigTemplateOverrideAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Command\AnalyzeCommand;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ConsoleReporter;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ReporterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests unitaires pour la commande d'analyse de migration.
 * Utilise CommandTester pour simuler l'exécution en ligne de commande.
 */
final class AnalyzeCommandTest extends TestCase
{
    /** Chemin vers le répertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../Fixtures';

    /**
     * Retourne le chemin absolu réel d'un projet de fixture.
     */
    private function getFixturePath(string $projectName): string
    {
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le répertoire de fixture "%s" est introuvable.', $projectName));

        return $path;
    }

    /**
     * Crée une instance de la commande avec les analyseurs et reporters fournis.
     *
     * @param list<AnalyzerInterface> $analyzers
     * @param list<ReporterInterface> $reporters
     */
    private function createCommand(array $analyzers = [], array $reporters = []): AnalyzeCommand
    {
        /* Ajout du reporter console par défaut si aucun reporter n'est fourni */
        if (count($reporters) === 0) {
            $reporters = [new ConsoleReporter()];
        }

        return new AnalyzeCommand($analyzers, $reporters);
    }

    /**
     * Vérifie que l'exécution sur le projet trivial retourne 0 (pas de BREAKING).
     * Le projet trivial n'a aucune surcharge de template.
     */
    #[Test]
    public function testExecuteOnTrivialProjectReturnsZero(): void
    {
        $command = $this->createCommand(
            analyzers: [new TwigTemplateOverrideAnalyzer()],
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'project-path' => $this->getFixturePath('project-trivial'),
        ]);

        /* Pas de problèmes BREAKING => code de sortie 0 (succès) */
        self::assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * Vérifie que l'exécution sur le projet complexe retourne 1
     * lorsqu'un analyseur produit des problèmes BREAKING.
     */
    #[Test]
    public function testExecuteOnComplexProjectReturnsOne(): void
    {
        /* Création d'un analyseur factice qui génère un problème BREAKING */
        $breakingAnalyzer = new class () implements AnalyzerInterface {
            public function analyze(MigrationReport $report): void
            {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::PLUGIN,
                    analyzer: 'test',
                    message: 'Plugin critique incompatible',
                    detail: 'Détail du problème critique.',
                    suggestion: 'Mettre à jour le plugin.',
                    estimatedMinutes: 120,
                ));
            }

            public function getName(): string
            {
                return 'Breaking Analyzer';
            }

            public function supports(MigrationReport $report): bool
            {
                return true;
            }
        };

        $command = $this->createCommand(analyzers: [$breakingAnalyzer]);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'project-path' => $this->getFixturePath('project-complex'),
        ]);

        /* Des problèmes BREAKING existent => code de sortie 1 (échec) */
        self::assertSame(Command::FAILURE, $exitCode);
    }

    /**
     * Vérifie que l'option --format=json échoue quand aucun reporter JSON n'est disponible.
     * Utilise le projet modéré pour que l'analyseur Twig soit applicable.
     */
    #[Test]
    public function testExecuteWithFormatOptionJson(): void
    {
        /* La commande ne dispose que du reporter console, pas de JSON */
        $command = $this->createCommand(
            analyzers: [new TwigTemplateOverrideAnalyzer()],
            reporters: [new ConsoleReporter()],
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'project-path' => $this->getFixturePath('project-moderate'),
            '--format' => 'json',
        ]);

        /* Sans reporter JSON, la commande doit indiquer un échec */
        self::assertSame(Command::FAILURE, $exitCode);

        /* Le message d'erreur doit mentionner le format non supporté */
        $output = $tester->getDisplay();
        self::assertStringContainsString('json', $output);
    }

    /**
     * Vérifie que l'option --only filtre les analyseurs par nom.
     */
    #[Test]
    public function testExecuteWithOnlyOption(): void
    {
        $command = $this->createCommand(
            analyzers: [new TwigTemplateOverrideAnalyzer()],
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'project-path' => $this->getFixturePath('project-moderate'),
            '--only' => ['Analyseur inexistant'],
        ]);

        /* Aucun analyseur correspondant au nom => aucun analyseur exécuté */
        $output = $tester->getDisplay();
        self::assertStringContainsString('Aucun analyseur applicable', $output);
        self::assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * Vérifie que la version Sylius détectée est affichée dans la sortie.
     */
    #[Test]
    public function testExecuteShowsDetectedVersion(): void
    {
        $command = $this->createCommand(
            analyzers: [new TwigTemplateOverrideAnalyzer()],
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'project-path' => $this->getFixturePath('project-moderate'),
        ]);

        $output = $tester->getDisplay();

        /* La version détectée du projet modéré est v1.14.0 */
        self::assertStringContainsString('v1.14.0', $output);
    }

    /**
     * Vérifie que l'exécution avec un chemin invalide lève une exception.
     */
    #[Test]
    public function testExecuteWithInvalidPathFails(): void
    {
        $command = $this->createCommand(
            analyzers: [new TwigTemplateOverrideAnalyzer()],
        );

        $tester = new CommandTester($command);

        /* Un chemin inexistant doit provoquer une exception ProjectNotFoundException */
        $this->expectException(\PierreArthur\SyliusUpgradeAnalyzer\Exception\ProjectNotFoundException::class);

        $tester->execute([
            'project-path' => '/chemin/totalement/inexistant/projet-fantome',
        ]);
    }

    /**
     * Vérifie que la commande affiche le chemin du projet analysé.
     * Utilise le projet modéré pour que l'analyseur Twig soit applicable.
     */
    #[Test]
    public function testExecuteShowsProjectPath(): void
    {
        $command = $this->createCommand(
            analyzers: [new TwigTemplateOverrideAnalyzer()],
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'project-path' => $this->getFixturePath('project-moderate'),
        ]);

        $output = $tester->getDisplay();

        /* Le chemin du projet doit apparaître dans la sortie */
        self::assertStringContainsString('project-moderate', $output);
    }
}
