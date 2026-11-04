<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\MultiStoreChannelAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur multi-store/multi-canal.
 * Verifie la detection des configurations multi-canaux et des usages de findOneByHostname.
 */
final class MultiStoreChannelAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('multistore_', true);
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
     * Verifie que supports retourne false sans config/.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutConfigDir(): void
    {
        $analyzer = new MultiStoreChannelAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand sylius_channel est reference.
     */
    #[Test]
    public function testSupportsReturnsTrueWithSyliusChannel(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/sylius_channel.yaml', <<<'YAML'
sylius_channel:
    channels:
        default: ~
        fr: ~
YAML);

        $analyzer = new MultiStoreChannelAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection de findOneByHostname() dans le code PHP.
     */
    #[Test]
    public function testDetectsFindOneByHostnameUsage(): void
    {
        /* Creation du fichier de config pour supports() */
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/sylius_channel.yaml', "sylius_channel: ~\n");

        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/ChannelResolver.php', <<<'PHP'
<?php

namespace App;

class ChannelResolver
{
    public function resolve($repository, string $hostname)
    {
        return $repository->findOneByHostname($hostname);
    }
}
PHP);

        $analyzer = new MultiStoreChannelAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $hostnameIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'findOneByHostname'),
        );
        self::assertNotEmpty($hostnameIssues);
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/sylius_channel.yaml', "sylius_channel: ~\n");

        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/ChannelResolver.php', <<<'PHP'
<?php

namespace App;

class ChannelResolver
{
    public function resolve($repository, string $hostname)
    {
        return $repository->findOneByHostname($hostname);
    }
}
PHP);

        $analyzer = new MultiStoreChannelAnalyzer();
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
        $analyzer = new MultiStoreChannelAnalyzer();

        self::assertSame('Multi-Store Channel', $analyzer->getName());
    }

    /**
     * Verifie que supports retourne true quand un fichier .yml declare une cle channels.
     */
    #[Test]
    public function testSupportsReturnsTrueWithChannelsKeyInYml(): void
    {
        $this->writeFile('config/packages/app.yml', "parameters:\n    channels:\n        - FASHION_WEB\n");

        self::assertTrue((new MultiStoreChannelAnalyzer())->supports($this->createReport()));
    }

    /**
     * Verifie que supports retourne false quand la configuration ne mentionne aucun canal.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutChannelConfiguration(): void
    {
        $this->writeFile('config/packages/framework.yaml', "framework:\n    secret: '%env(APP_SECRET)%'\n");

        self::assertFalse((new MultiStoreChannelAnalyzer())->supports($this->createReport()));
    }

    /**
     * Verifie qu'aucune issue n'est produite pour un projet sans configuration ni code multi-canal.
     */
    #[Test]
    public function testReturnsNoIssuesForSingleStoreProject(): void
    {
        $report = $this->createReport();

        (new MultiStoreChannelAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection d'une configuration declarant plusieurs canaux, avec leur nombre.
     */
    #[Test]
    public function testDetectsMultiChannelConfiguration(): void
    {
        $configFile = $this->writeFile('config/packages/sylius_channel.yaml', <<<'YAML'
sylius_channel:
    channels:
        FASHION_WEB:
            hostname: fashion.example.com
        FASHION_B2B:
            hostname: pro.fashion.example.com
        FASHION_DE:
            hostname: fashion.example.de
YAML);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Configuration multi-canal detectee avec 3 canaux');

        self::assertCount(1, $issues);
        self::assertStringContainsString('sylius_channel.yaml', $issues[0]->getMessage());
        self::assertSame(realpath($configFile), realpath((string) $issues[0]->getFile()));
        self::assertSame(Category::DEPRECATION, $issues[0]->getCategory());
        self::assertStringContainsString('3 canaux', $issues[0]->getDetail());
    }

    /**
     * Verifie que la synthese estime 240 minutes par canal configure.
     */
    #[Test]
    public function testSummaryEstimatesFourHoursPerChannel(): void
    {
        $this->writeFile('config/packages/sylius_channel.yml', <<<'YAML'
sylius_channel:
    channels:
        FASHION_WEB: ~
        FASHION_B2B: ~
YAML);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        $summary = $this->issuesWithMessage($report, '2 probleme(s) lie(s) au multi-store/multi-canal');

        self::assertCount(1, $summary);
        self::assertSame(480, $summary[0]->getEstimatedMinutes());
        self::assertNull($summary[0]->getFile());
        self::assertCount(2, $report->getIssues());
    }

    /**
     * Verifie qu'une configuration a un seul canal n'est pas consideree comme multi-canal.
     */
    #[Test]
    public function testIgnoresSingleChannelConfiguration(): void
    {
        $this->writeFile('config/packages/sylius_channel.yaml', "sylius_channel:\n    channels:\n        FASHION_WEB:\n            hostname: fashion.example.com\n");

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un fichier YAML invalide est ignore sans interrompre l'analyse.
     */
    #[Test]
    public function testIgnoresInvalidYamlConfiguration(): void
    {
        $this->writeFile('config/packages/sylius_channel.yaml', "sylius_channel:\n    channels: [FASHION_WEB\n  - {\n");

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un fichier YAML scalaire ou sans cle sylius_channel est ignore.
     */
    #[Test]
    public function testIgnoresYamlWithoutSyliusChannelKey(): void
    {
        $this->writeFile('config/packages/scalar.yaml', "just a string\n");
        $this->writeFile('config/packages/sylius_shop.yaml', "sylius_shop:\n    product_grid:\n        include_all_descendants: true\n");

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que chaque appel a findOneByHostname produit une issue avec sa ligne.
     */
    #[Test]
    public function testReportsEachFindOneByHostnameCallWithLine(): void
    {
        $file = $this->writeFile('src/Context/HostnameChannelResolver.php', <<<'PHP'
<?php

namespace App\Context;

use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;

final class HostnameChannelResolver
{
    public function __construct(private ChannelRepositoryInterface $channelRepository)
    {
    }

    public function resolve(string $hostname): ?object
    {
        $channel = $this->channelRepository->findOneByHostname($hostname);
        if ($channel === null) {
            $channel = $this->channelRepository->findOneByHostname('www.' . $hostname);
        }

        return $channel;
    }
}
PHP);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Utilisation de findOneByHostname()');

        self::assertCount(2, $issues);
        self::assertSame(15, $issues[0]->getLine());
        self::assertSame(17, $issues[1]->getLine());
        self::assertStringContainsString('ligne 15', $issues[0]->getMessage());
        self::assertSame(realpath($file), realpath((string) $issues[1]->getFile()));
        self::assertCount(1, $this->issuesWithMessage($report, '2 probleme(s) lie(s) au multi-store/multi-canal'));
    }

    /**
     * Verifie qu'une simple mention de findOneByHostname en commentaire ne produit aucune issue.
     */
    #[Test]
    public function testIgnoresFindOneByHostnameMentionedInComment(): void
    {
        $this->writeFile('src/Context/ChannelNotes.php', <<<'PHP'
<?php

namespace App\Context;

// Ancienne implementation : $repository->findOneByHostname($host)
final class ChannelNotes
{
}
PHP);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un fichier PHP invalide est ignore par les deux analyses de code.
     */
    #[Test]
    public function testIgnoresUnparsablePhpFile(): void
    {
        $this->writeFile('src/Context/Broken.php', <<<'PHP'
<?php

class Broken implements LocaleContextInterface {
    public function resolve($repo) { return $repo->findOneByHostname('x');
PHP);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection d'un contexte de locale personnalise.
     */
    #[Test]
    public function testDetectsCustomLocaleContext(): void
    {
        $file = $this->writeFile('src/Context/HeaderLocaleContext.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Context;

use Sylius\Component\Locale\Context\LocaleContextInterface;

final class HeaderLocaleContext implements LocaleContextInterface
{
    public function getLocaleCode(): string
    {
        return 'fr_FR';
    }
}
PHP);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Contexte personnalise "HeaderLocaleContext" implementant LocaleContextInterface');

        self::assertCount(1, $issues);
        self::assertSame(9, $issues[0]->getLine());
        self::assertSame(realpath($file), realpath((string) $issues[0]->getFile()));
        self::assertStringContainsString('HeaderLocaleContext', $issues[0]->getSuggestion());
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
    }

    /**
     * Verifie la detection d'un contexte de canal personnalise reference par son nom complet.
     */
    #[Test]
    public function testDetectsCustomChannelContext(): void
    {
        $this->writeFile('src/Context/SubdomainChannelContext.php', <<<'PHP'
<?php

namespace App\Context;

final class SubdomainChannelContext implements \Sylius\Component\Channel\Context\ChannelContextInterface
{
}
PHP);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Contexte personnalise "SubdomainChannelContext"');

        self::assertCount(1, $issues);
        self::assertSame(5, $issues[0]->getLine());
        self::assertStringContainsString(
            'Sylius\\Component\\Channel\\Context\\ChannelContextInterface',
            $issues[0]->getMessage(),
        );
    }

    /**
     * Verifie qu'une classe injectant LocaleContextInterface sans l'implementer est ignoree.
     */
    #[Test]
    public function testIgnoresClassOnlyInjectingLocaleContext(): void
    {
        $this->writeFile('src/Twig/LocaleExtension.php', <<<'PHP'
<?php

namespace App\Twig;

use Sylius\Component\Locale\Context\LocaleContextInterface;
use Twig\Extension\ExtensionInterface;

final class LocaleExtension implements ExtensionInterface
{
    public function __construct(private LocaleContextInterface $localeContext)
    {
    }
}
PHP);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que la synthese cumule canaux, appels findOneByHostname et contextes personnalises.
     */
    #[Test]
    public function testSummaryAggregatesChannelsHostnameCallsAndContexts(): void
    {
        $this->writeFile('config/packages/sylius_channel.yaml', "sylius_channel:\n    channels:\n        FASHION_WEB: ~\n        FASHION_B2B: ~\n");
        $this->writeFile('src/Context/RequestChannelContext.php', <<<'PHP'
<?php

namespace App\Context;

use Sylius\Component\Channel\Context\ChannelContextInterface;

final class RequestChannelContext implements ChannelContextInterface
{
    public function getChannel($repository, string $host)
    {
        return $repository->findOneByHostname($host);
    }
}
PHP);

        $report = $this->createReport();
        (new MultiStoreChannelAnalyzer())->analyze($report);

        $summary = $this->issuesWithMessage($report, '4 probleme(s) lie(s) au multi-store/multi-canal');

        self::assertCount(1, $summary);
        self::assertSame(960, $summary[0]->getEstimatedMinutes());
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
