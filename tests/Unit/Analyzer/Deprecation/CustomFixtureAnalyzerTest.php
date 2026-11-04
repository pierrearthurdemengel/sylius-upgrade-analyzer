<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\CustomFixtureAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des fixtures personnalisees.
 * Verifie la detection des classes dans src/DataFixtures/ et de la configuration sylius_fixtures.
 */
final class CustomFixtureAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('fixture_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir((string) $item->getRealPath());
            } else {
                unlink((string) $item->getRealPath());
            }
        }

        rmdir($path);
    }

    private function createReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12',
            targetVersion: '2.0',
            projectPath: $this->tempDir,
        );
    }

    /**
     * Verifie que supports retourne false sans src/DataFixtures/ ni configuration sylius_fixtures.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutFixtures(): void
    {
        $analyzer = new CustomFixtureAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand src/DataFixtures/ existe.
     */
    #[Test]
    public function testSupportsReturnsTrueWithDataFixturesDir(): void
    {
        mkdir($this->tempDir . '/src/DataFixtures', 0755, true);

        $analyzer = new CustomFixtureAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection des classes de fixtures dans src/DataFixtures/.
     */
    #[Test]
    public function testDetectsDataFixtureClasses(): void
    {
        $fixturesDir = $this->tempDir . '/src/DataFixtures';
        mkdir($fixturesDir, 0755, true);
        file_put_contents($fixturesDir . '/ProductFixture.php', "<?php\nnamespace App\\DataFixtures;\nclass ProductFixture {}\n");

        $analyzer = new CustomFixtureAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $fixtureIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'ProductFixture'),
        );
        self::assertNotEmpty($fixtureIssues);
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $fixturesDir = $this->tempDir . '/src/DataFixtures';
        mkdir($fixturesDir, 0755, true);
        file_put_contents($fixturesDir . '/ProductFixture.php', "<?php\nclass ProductFixture {}\n");

        $analyzer = new CustomFixtureAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new CustomFixtureAnalyzer();

        self::assertSame('Custom Fixture', $analyzer->getName());
    }

    /**
     * Verifie que supports retourne true avec un fichier sylius_fixtures.yaml seul.
     */
    #[Test]
    public function testSupportsReturnsTrueWithSyliusFixturesYaml(): void
    {
        $this->writeFile('config/packages/sylius_fixtures.yaml', "sylius_fixtures: ~\n");

        self::assertTrue((new CustomFixtureAnalyzer())->supports($this->createReport()));
    }

    /**
     * Verifie que supports retourne true avec l'extension .yml.
     */
    #[Test]
    public function testSupportsReturnsTrueWithSyliusFixturesYml(): void
    {
        $this->writeFile('config/packages/sylius_fixtures.yml', "sylius_fixtures: ~\n");

        self::assertTrue((new CustomFixtureAnalyzer())->supports($this->createReport()));
    }

    /**
     * Verifie qu'aucune issue n'est produite pour un projet sans fixtures.
     */
    #[Test]
    public function testReturnsNoIssuesWithoutFixtures(): void
    {
        $report = $this->createReport();

        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que chaque suite declaree dans sylius_fixtures produit une issue rattachee au fichier.
     */
    #[Test]
    public function testDetectsEachFixtureSuiteInConfiguration(): void
    {
        $configFile = $this->writeFile('config/packages/sylius_fixtures.yaml', <<<'YAML'
sylius_fixtures:
    suites:
        default:
            listeners:
                orm_purger: ~
                logger: ~
            fixtures:
                locale: ~
                currency: ~
        b2b_catalog:
            fixtures:
                product:
                    options:
                        random: 20
YAML);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        $defaultSuite = $this->issuesWithMessage($report, 'Suite de fixtures "default"');
        $b2bSuite = $this->issuesWithMessage($report, 'Suite de fixtures "b2b_catalog"');

        self::assertCount(1, $defaultSuite);
        self::assertCount(1, $b2bSuite);
        self::assertSame(Category::DEPRECATION, $defaultSuite[0]->getCategory());
        self::assertSame(realpath($configFile), realpath((string) $defaultSuite[0]->getFile()));
        self::assertStringContainsString('sylius_fixtures.yaml', $defaultSuite[0]->getDetail());
        self::assertStringContainsString('b2b_catalog', $b2bSuite[0]->getSuggestion());
        self::assertSame('https://docs.sylius.com/migration-2.0', $b2bSuite[0]->getDocUrl());
    }

    /**
     * Verifie que l'issue de synthese estime 120 minutes par suite detectee.
     */
    #[Test]
    public function testSummaryEstimatesTwoHoursPerFixtureSuite(): void
    {
        $this->writeFile('config/packages/sylius_fixtures.yml', <<<'YAML'
sylius_fixtures:
    suites:
        default: ~
        demo: ~
YAML);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        $summary = $this->issuesWithMessage($report, '2 fixture(s) personnalisee(s) detectee(s)');

        self::assertCount(1, $summary);
        self::assertSame(240, $summary[0]->getEstimatedMinutes());
        self::assertNull($summary[0]->getFile());
        self::assertCount(3, $report->getIssues());
    }

    /**
     * Verifie qu'un fichier sylius_fixtures.yaml invalide est ignore sans faire echouer l'analyse.
     */
    #[Test]
    public function testIgnoresInvalidFixtureConfigurationYaml(): void
    {
        $this->writeFile('config/packages/sylius_fixtures.yaml', "sylius_fixtures:\n  suites: [unclosed\n    default: {\n");

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'une configuration sans cle sylius_fixtures ne produit aucune issue.
     */
    #[Test]
    public function testIgnoresConfigurationWithoutSyliusFixturesKey(): void
    {
        $this->writeFile('config/packages/sylius_fixtures.yaml', "framework:\n    secret: '%env(APP_SECRET)%'\n");

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'une cle sylius_fixtures vide (~) ne produit aucune issue.
     */
    #[Test]
    public function testIgnoresNullSyliusFixturesConfiguration(): void
    {
        $this->writeFile('config/packages/sylius_fixtures.yaml', "sylius_fixtures: ~\n");

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'une cle suites scalaire est ignoree.
     */
    #[Test]
    public function testIgnoresScalarSuitesConfiguration(): void
    {
        $this->writeFile('config/packages/sylius_fixtures.yaml', "sylius_fixtures:\n    suites: default\n");

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que l'issue d'une classe de src/DataFixtures/ pointe vers le fichier concerne.
     */
    #[Test]
    public function testDataFixtureClassIssueReferencesFile(): void
    {
        $fixtureFile = $this->writeFile('src/DataFixtures/CustomerGroupFixture.php', <<<'PHP'
<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;

final class CustomerGroupFixture extends Fixture
{
}
PHP);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Classe de fixture detectee : CustomerGroupFixture.php');

        self::assertCount(1, $issues);
        self::assertSame(realpath($fixtureFile), realpath((string) $issues[0]->getFile()));
        self::assertStringContainsString('src/DataFixtures/', $issues[0]->getDetail());
    }

    /**
     * Verifie la detection d'une classe implementant FixtureInterface de Sylius hors de src/DataFixtures/.
     */
    #[Test]
    public function testDetectsClassImplementingSyliusFixtureInterface(): void
    {
        $file = $this->writeFile('src/Fixture/LoyaltyProgramFixture.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Fixture;

use Sylius\Bundle\FixturesBundle\Fixture\FixtureInterface;

final class LoyaltyProgramFixture implements FixtureInterface
{
    public function load(array $options): void
    {
    }

    public function getName(): string
    {
        return 'loyalty_program';
    }
}
PHP);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Classe de fixture Sylius "LoyaltyProgramFixture" detectee');

        self::assertCount(1, $issues);
        self::assertSame(9, $issues[0]->getLine());
        self::assertSame(realpath($file), realpath((string) $issues[0]->getFile()));
        self::assertStringContainsString('implemente', $issues[0]->getDetail());
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
    }

    /**
     * Verifie la detection d'une classe etendant AbstractFixture de Sylius.
     */
    #[Test]
    public function testDetectsClassExtendingSyliusAbstractFixture(): void
    {
        $this->writeFile('src/Fixture/GiftCardFixture.php', <<<'PHP'
<?php

namespace App\Fixture;

use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class GiftCardFixture extends AbstractFixture
{
    public function load(array $options): void
    {
    }

    public function getName(): string
    {
        return 'gift_card';
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
    }
}
PHP);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Classe de fixture Sylius "GiftCardFixture" detectee');

        self::assertCount(1, $issues);
        self::assertSame(8, $issues[0]->getLine());
        self::assertStringContainsString('etend', $issues[0]->getDetail());
        self::assertStringContainsString('GiftCardFixture', $issues[0]->getSuggestion());
    }

    /**
     * Verifie qu'une classe etendant AbstractFixture de Doctrine (sans import Sylius) est ignoree.
     */
    #[Test]
    public function testIgnoresDoctrineAbstractFixtureWithoutSyliusImport(): void
    {
        $this->writeFile('src/Fixture/LegacyFixture.php', <<<'PHP'
<?php

namespace App\Fixture;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Persistence\ObjectManager;

class LegacyFixture extends AbstractFixture
{
    public function load(ObjectManager $manager): void
    {
    }
}
PHP);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un fichier PHP invalide mentionnant FixtureInterface est ignore.
     */
    #[Test]
    public function testIgnoresUnparsablePhpFileMentioningFixtureInterface(): void
    {
        $this->writeFile('src/Fixture/BrokenFixture.php', <<<'PHP'
<?php

use Sylius\Bundle\FixturesBundle\Fixture\FixtureInterface;

class BrokenFixture implements FixtureInterface {
    public function load(array $options): void {
PHP);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'une fixture Sylius placee dans src/DataFixtures/ n'est pas comptee deux fois.
     */
    #[Test]
    public function testDoesNotCountDataFixturesClassTwice(): void
    {
        $this->writeFile('src/DataFixtures/ShippingZoneFixture.php', <<<'PHP'
<?php

namespace App\DataFixtures;

use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;

class ShippingZoneFixture extends AbstractFixture
{
}
PHP);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Classe de fixture detectee'));
        self::assertSame([], $this->issuesWithMessage($report, 'Classe de fixture Sylius'));
        self::assertCount(1, $this->issuesWithMessage($report, '1 fixture(s) personnalisee(s) detectee(s)'));
    }

    /**
     * Verifie que la synthese cumule classes, suites et implementations Sylius.
     */
    #[Test]
    public function testSummaryAggregatesClassesSuitesAndInterfaces(): void
    {
        $this->writeFile('src/DataFixtures/AppFixtures.php', "<?php\nnamespace App\\DataFixtures;\nclass AppFixtures {}\n");
        $this->writeFile('config/packages/sylius_fixtures.yaml', "sylius_fixtures:\n    suites:\n        default: ~\n");
        $this->writeFile('src/Fixture/VendorFixture.php', <<<'PHP'
<?php

namespace App\Fixture;

use Sylius\Bundle\FixturesBundle\Fixture\FixtureInterface;

final class VendorFixture implements FixtureInterface
{
}
PHP);

        $report = $this->createReport();
        (new CustomFixtureAnalyzer())->analyze($report);

        $summary = $this->issuesWithMessage($report, '3 fixture(s) personnalisee(s) detectee(s)');

        self::assertCount(1, $summary);
        self::assertSame(360, $summary[0]->getEstimatedMinutes());
        self::assertCount(4, $report->getIssues());
    }

    /**
     * Cree un fichier (et ses repertoires parents) dans le projet temporaire.
     * Retourne le chemin absolu du fichier cree.
     */
    private function writeFile(string $relativePath, string $content): string
    {
        $path = $this->tempDir . '/' . $relativePath;
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Retourne les issues dont le message contient le fragment donne.
     *
     * @return list<MigrationIssue>
     */
    private function issuesWithMessage(MigrationReport $report, string $fragment): array
    {
        return array_values(array_filter(
            $report->getIssues(),
            static fn (MigrationIssue $issue): bool => str_contains($issue->getMessage(), $fragment),
        ));
    }
}
