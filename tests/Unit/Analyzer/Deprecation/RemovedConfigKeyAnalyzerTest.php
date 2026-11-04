<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\RemovedConfigKeyAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des cles de configuration supprimees.
 * Verifie la detection des cles obsoletes dans les fichiers YAML de config/.
 */
final class RemovedConfigKeyAnalyzerTest extends TestCase
{
    /** Chemin vers le repertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /**
     * Cree un rapport de migration pointant vers le projet de fixture specifie.
     */
    private function createReportForFixture(string $projectName): MigrationReport
    {
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le repertoire de fixture "%s" est introuvable.', $projectName));

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.0',
            projectPath: $path,
        );
    }

    /**
     * Verifie que supports retourne false pour le projet trivial sans cles supprimees.
     */
    #[Test]
    public function testSupportsReturnsFalseForTrivialProject(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true pour le projet moderate avec des cles supprimees.
     */
    #[Test]
    public function testSupportsReturnsTrueForModerateProject(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte autoconfigure_with_attributes dans le projet moderate.
     */
    #[Test]
    public function testDetectsAutoconfigureWithAttributes(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        $issues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'autoconfigure_with_attributes'),
        );

        self::assertNotEmpty($issues);
    }

    /**
     * Verifie que le projet major detecte plus de problemes que le projet moderate.
     */
    #[Test]
    public function testMajorProjectHasMoreIssuesThanModerate(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();

        $moderateReport = $this->createReportForFixture('project-moderate');
        $analyzer->analyze($moderateReport);
        $moderateCount = count($moderateReport->getIssues());

        $majorReport = $this->createReportForFixture('project-major');
        $analyzer->analyze($majorReport);
        $majorCount = count($majorReport->getIssues());

        self::assertGreaterThan($moderateCount, $majorCount);
    }

    /**
     * Verifie que l'analyseur detecte state_machine et d'autres cles dans le projet complex.
     */
    #[Test]
    public function testDetectsMultipleRemovedKeysInComplex(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        $stateMachineIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'state_machine'),
        );

        self::assertNotEmpty($stateMachineIssues);
    }

    /**
     * Verifie que tous les problemes sont de severite BREAKING et categorie DEPRECATION.
     */
    #[Test]
    public function testCreatesBreakingDeprecationIssues(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::BREAKING, $issue->getSeverity());
            self::assertSame(Category::DEPRECATION, $issue->getCategory());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();

        self::assertSame('Removed Config Key', $analyzer->getName());
    }

    /**
     * Verifie que l'estimation est de 30 minutes par cle de configuration.
     */
    #[Test]
    public function testEstimatesThirtyMinutesPerConfigKey(): void
    {
        $analyzer = new RemovedConfigKeyAnalyzer();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        $globalIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'cle(s) de configuration supprimee(s)'),
        ));

        self::assertNotEmpty($globalIssues, 'Le probleme global de synthese devrait etre present.');
        /* Au moins 1 cle x 30 minutes */
        self::assertGreaterThanOrEqual(30, $globalIssues[0]->getEstimatedMinutes());
    }

    /**
     * Cree un projet temporaire dont config/ contient les fichiers donnes.
     *
     * @param array<string, string> $files
     */
    private function createTempProject(array $files): MigrationReport
    {
        $dir = sys_get_temp_dir() . '/removed-config-' . uniqid('', true);
        foreach ($files as $relativePath => $content) {
            $path = $dir . '/config/' . $relativePath;
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }
        $this->tempDirs[] = $dir;

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.14.0',
            targetVersion: '2.2',
            projectPath: $dir,
        );
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir((string) $item->getRealPath()) : unlink((string) $item->getRealPath());
            }
            rmdir($dir);
        }
    }

    /**
     * Reproduit Sylius-Standard 1.14 : des lignes "class:" et des chemins "templates" ordinaires
     * ne doivent pas etre pris pour les parametres supprimes sylius.*.repository.class
     * ou sylius.mailer.templates.
     */
    #[Test]
    public function testIgnoresUnrelatedClassAndTemplatesKeys(): void
    {
        $report = $this->createTempProject([
            'packages/http_discovery.yaml' => "services:
    http_discovery.psr17_factory:
        class: Http\Discovery\Psr17Factory
",
            'packages/twig.yaml' => "twig:
    paths: ['%kernel.project_dir%/templates']
    form_themes: ['@SyliusUi/Form/theme.html.twig']
",
            'services.yaml' => "services:
    App\:
        resource: '../src/*'
    app.checker:
        class: App\Checker
",
        ]);

        (new RemovedConfigKeyAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /** Verifie la detection d'un parametre supprime defini sous parameters, avec sa ligne. */
    #[Test]
    public function testDetectsRemovedParameterDefinition(): void
    {
        $report = $this->createTempProject([
            'services.yaml' => 'parameters:
    locale: fr_FR
    sylius.mailer.templates: []
',
        ]);

        (new RemovedConfigKeyAnalyzer())->analyze($report);

        $issues = array_values(array_filter($report->getIssues(), static fn ($i): bool => str_contains($i->getMessage(), '"sylius.mailer.templates"')));
        self::assertCount(1, $issues);
        self::assertSame(3, $issues[0]->getLine());
    }

    /** Verifie la detection d'un parametre supprime reference sous la forme %nom%. */
    #[Test]
    public function testDetectsRemovedParameterReference(): void
    {
        $report = $this->createTempProject([
            'packages/doctrine.yaml' => "services:
    app.repository:
        class: '%sylius.mongodb_odm.repository.class%'
",
        ]);

        (new RemovedConfigKeyAnalyzer())->analyze($report);

        self::assertCount(1, array_filter($report->getIssues(), static fn ($i): bool => str_contains($i->getMessage(), '"sylius.mongodb_odm.repository.class"')));
    }

    /** Verifie qu'un noeud de bundle n'est detecte qu'a son chemin exact, y compris sous when@prod. */
    #[Test]
    public function testDetectsBundleNodeOnlyAtItsExactPath(): void
    {
        $report = $this->createTempProject([
            'packages/sylius_api.yaml' => 'when@prod:
    sylius_api:
        serialization_groups:
            skip_adding_read_group: true
',
            'packages/app.yaml' => 'app:
    serialization_groups:
        skip_adding_read_group: true
    state_machine: custom
',
        ]);

        (new RemovedConfigKeyAnalyzer())->analyze($report);

        $messages = array_map(static fn ($i): string => $i->getMessage(), $report->getIssues());
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'skip_adding_read_group') && str_contains($m, 'sylius_api.yaml')));
        self::assertCount(0, array_filter($messages, static fn (string $m): bool => str_contains($m, 'app.yaml')));
    }
}
