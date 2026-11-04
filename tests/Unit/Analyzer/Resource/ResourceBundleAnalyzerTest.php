<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Resource\ResourceBundleAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur du systeme de ressources Sylius.
 * Verifie la detection des definitions YAML, des repositories et factories.
 */
final class ResourceBundleAnalyzerTest extends TestCase
{
    /** Chemin vers le repertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /** Repertoire temporaire servant de projet Sylius pour les cas cibles */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('resource_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Supprime recursivement un repertoire et son contenu.
     */
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

    /**
     * Ecrit un fichier dans le projet temporaire et retourne son chemin reel,
     * tel que l'analyseur le rapportera dans les issues.
     */
    private function writeProjectFile(string $relativePath, string $content): string
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $directory = \dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($fullPath, $content);

        $realPath = realpath($fullPath);
        self::assertNotFalse($realPath);

        return $realPath;
    }

    /**
     * Cree un rapport de migration pointant vers le projet temporaire.
     */
    private function createTempReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12',
            targetVersion: '2.0',
            projectPath: $this->tempDir,
        );
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
            static fn ($issue): bool => str_contains($issue->getMessage(), $fragment),
        ));
    }

    /**
     * Cree un rapport de migration pointant vers le projet de fixture specifie.
     */
    private function createReportForFixture(string $projectName): MigrationReport
    {
        /* Resolution du chemin reel pour eviter les problemes de chemins relatifs */
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le repertoire de fixture "%s" est introuvable.', $projectName));

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: $path,
        );
    }

    /**
     * Verifie que supports() retourne true pour un projet avec sylius_resource.yaml.
     * Le projet complexe contient un fichier config/packages/sylius_resource.yaml.
     */
    #[Test]
    public function testSupportsReturnsTrueWithResourceConfig(): void
    {
        $analyzer = new ResourceBundleAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        /* Le projet complexe contient sylius_resource.yaml, supports doit retourner true */
        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que supports() retourne false pour un projet sans configuration de ressources.
     * Le projet trivial ne contient aucun fichier sylius_resource.
     */
    #[Test]
    public function testSupportsReturnsFalseWithNoResourceConfig(): void
    {
        $analyzer = new ResourceBundleAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        /* Le projet trivial n'a ni YAML de ressources ni classes PHP de ressources */
        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte les definitions de ressources depuis le fichier YAML.
     * Le fichier sylius_resource.yaml du projet complexe contient la ressource app.product.
     */
    #[Test]
    public function testDetectsResourceDefinitionsFromYaml(): void
    {
        $analyzer = new ResourceBundleAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problemes mentionnant les definitions YAML de ressources */
        $yamlIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'definition(s) de ressource(s) YAML'),
        );

        self::assertNotEmpty($yamlIssues, 'Les definitions de ressources YAML auraient du etre detectees.');

        /* Verification que la ressource app.product est mentionnee dans le detail */
        $yamlIssue = array_values($yamlIssues)[0];
        self::assertStringContainsString('app.product', $yamlIssue->getDetail());
    }

    /**
     * Verifie que l'analyseur detecte le repository personnalise ProductRepository.
     * Le projet complexe definit un ProductRepository dans sylius_resource.yaml
     * et possede la classe PHP correspondante.
     */
    #[Test]
    public function testDetectsCustomRepositories(): void
    {
        $analyzer = new ResourceBundleAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problemes mentionnant le detail du repository personnalise */
        $yamlIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'definition(s) de ressource(s) YAML'),
        ));

        self::assertNotEmpty($yamlIssues);

        /* Le detail YAML doit mentionner les repositories personnalises */
        self::assertStringContainsString('repositories personnalises', $yamlIssues[0]->getDetail());
    }

    /**
     * Verifie que les problemes de ressources sont de severite WARNING.
     * Toutes les detections doivent etre classees comme avertissements dans la categorie RESOURCE.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $analyzer = new ResourceBundleAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Tous les problemes detectes doivent etre des WARNING dans la categorie RESOURCE */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
            self::assertSame(Category::RESOURCE, $issue->getCategory());
        }
    }

    /**
     * Verifie que getName() retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new ResourceBundleAnalyzer();

        self::assertSame('Resource Bundle', $analyzer->getName());
    }

    /**
     * Verifie l'estimation du temps pour les ressources YAML.
     * Chaque ressource est estimee a 120 minutes (MINUTES_PER_RESOURCE).
     * Le projet complexe contient 1 ressource, donc 120 minutes.
     */
    #[Test]
    public function testEstimatesCorrectMinutesPerResource(): void
    {
        $analyzer = new ResourceBundleAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche de l'issue YAML pour verifier l'estimation */
        $yamlIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'definition(s) de ressource(s) YAML'),
        ));

        self::assertNotEmpty($yamlIssues);

        /* 1 ressource * 120 minutes = 120 minutes */
        self::assertSame(120, $yamlIssues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que supports() retourne true pour un projet sans YAML de ressources
     * mais dont le code source implemente ResourceInterface.
     */
    #[Test]
    public function testSupportsReturnsTrueWithResourcePhpClassOnly(): void
    {
        $this->writeProjectFile('src/Entity/Brand.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Entity;

            use Sylius\Component\Resource\Model\ResourceInterface;

            class Brand implements ResourceInterface
            {
                public function getId(): ?int
                {
                    return null;
                }
            }
            PHP);

        self::assertTrue((new ResourceBundleAnalyzer())->supports($this->createTempReport()));
    }

    /**
     * Verifie que supports() retourne false quand la configuration ne contient ni
     * sylius_resource ni YAML valide, et que le code source ne reference aucune
     * classe du systeme de ressources.
     */
    #[Test]
    public function testSupportsReturnsFalseWithUnrelatedConfigAndSources(): void
    {
        $this->writeProjectFile('config/packages/framework.yaml', "framework:\n    secret: '%env(APP_SECRET)%'\n");
        $this->writeProjectFile('config/packages/broken.yaml', "sylius_resource: [unclosed\n");
        $this->writeProjectFile('src/Kernel.php', "<?php\n\nnamespace App;\n\nfinal class Kernel\n{\n}\n");

        self::assertFalse((new ResourceBundleAnalyzer())->supports($this->createTempReport()));
    }

    /**
     * Verifie qu'un projet vide (ni config/ ni src/) ne produit aucune issue.
     */
    #[Test]
    public function testReturnsNoIssuesForProjectWithoutConfigAndSrc(): void
    {
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie l'issue produite pour plusieurs ressources YAML dont une seule
     * declare une factory : comptage, liste des noms, fichier et estimation.
     */
    #[Test]
    public function testDetectsYamlResourcesWithCustomFactoryOnly(): void
    {
        $filePath = $this->writeProjectFile('config/packages/sylius_resource.yaml', <<<'YAML'
            sylius_resource:
                resources:
                    app.brand:
                        classes:
                            model: App\Entity\Brand
                            factory: App\Factory\BrandFactory
                    app.supplier:
                        classes:
                            model: App\Entity\Supplier
            YAML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'definition(s) de ressource(s) YAML');
        self::assertCount(1, $issues);
        self::assertStringStartsWith('2 definition(s)', $issues[0]->getMessage());
        self::assertStringContainsString('app.brand, app.supplier', $issues[0]->getDetail());
        self::assertStringContainsString('Certaines definissent des factories personnalises.', $issues[0]->getDetail());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
        self::assertSame(Category::RESOURCE, $issues[0]->getCategory());
        /* 2 ressources * 120 minutes */
        self::assertSame(240, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que le detail mentionne a la fois factories et repositories
     * lorsque les deux sont personnalises.
     */
    #[Test]
    public function testDetailMentionsFactoriesAndRepositoriesWhenBothAreCustomized(): void
    {
        $this->writeProjectFile('config/packages/sylius_resource.yml', <<<'YAML'
            sylius_resource:
                resources:
                    app.brand:
                        classes:
                            model: App\Entity\Brand
                            factory: App\Factory\BrandFactory
                            repository: App\Repository\BrandRepository
            YAML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'definition(s) de ressource(s) YAML');
        self::assertCount(1, $issues);
        self::assertStringContainsString(
            'Certaines definissent des factories et repositories personnalises.',
            $issues[0]->getDetail(),
        );
    }

    /**
     * Verifie qu'aucune mention de complexite n'est ajoutee quand les ressources
     * ne declarent que leur modele, et que les definitions non structurees
     * (ressource nulle, classes scalaires) sont tolerees.
     */
    #[Test]
    public function testDetailHasNoComplexityMentionForModelOnlyResources(): void
    {
        $this->writeProjectFile('config/packages/sylius_resource.yaml', <<<'YAML'
            sylius_resource:
                resources:
                    app.brand:
                        classes:
                            model: App\Entity\Brand
                    app.supplier: ~
                    app.warehouse:
                        classes: App\Entity\Warehouse
            YAML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'definition(s) de ressource(s) YAML');
        self::assertCount(1, $issues);
        self::assertStringStartsWith('3 definition(s)', $issues[0]->getMessage());
        self::assertStringNotContainsString('Certaines definissent', $issues[0]->getDetail());
        self::assertSame(360, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que les fichiers YAML invalides, sans ressources ou avec une cle
     * sylius_resource non structuree ne produisent aucune issue.
     */
    #[Test]
    public function testIgnoresInvalidOrEmptyResourceYaml(): void
    {
        $this->writeProjectFile('config/packages/broken.yaml', "sylius_resource: [unclosed\n");
        $this->writeProjectFile('config/packages/scalar.yaml', "sylius_resource: enabled\n");
        $this->writeProjectFile('config/packages/empty.yaml', "sylius_resource:\n    resources: []\n");
        $this->writeProjectFile('config/packages/mapping.yaml', "sylius_resource:\n    mapping:\n        paths: ['%kernel.project_dir%/src/Entity']\n");
        $this->writeProjectFile('config/packages/plain.yaml', "just a string\n");
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection d'un modele implementant ResourceInterface :
     * message, ligne de la classe, fichier et estimation.
     */
    #[Test]
    public function testDetectsResourceInterfaceImplementation(): void
    {
        $filePath = $this->writeProjectFile('src/Entity/Brand.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Entity;

            use Sylius\Component\Resource\Model\ResourceInterface;

            class Brand implements ResourceInterface
            {
                private ?int $id = null;

                public function getId(): ?int
                {
                    return $this->id;
                }
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Implementation de ressource personnalisee detectee : Brand');
        self::assertCount(1, $issues);
        self::assertSame(9, $issues[0]->getLine());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertStringContainsString('(ligne 9) implemente ResourceInterface', $issues[0]->getDetail());
        self::assertStringContainsString('SyliusResourceBundle 2.x', $issues[0]->getSuggestion());
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie qu'une implementation referencant ResourceInterface par son nom
     * pleinement qualifie est egalement detectee.
     */
    #[Test]
    public function testDetectsFullyQualifiedResourceInterfaceImplementation(): void
    {
        $this->writeProjectFile('src/Entity/Supplier.php', <<<'PHP'
            <?php

            namespace App\Entity;

            class Supplier implements \Sylius\Component\Resource\Model\ResourceInterface
            {
                public function getId(): ?int
                {
                    return null;
                }
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Implementation de ressource personnalisee detectee : Supplier');
        self::assertCount(1, $issues);
        self::assertSame(5, $issues[0]->getLine());
    }

    /**
     * Verifie la detection d'une factory personnalisee implementant FactoryInterface.
     */
    #[Test]
    public function testDetectsFactoryInterfaceImplementation(): void
    {
        $this->writeProjectFile('src/Factory/BrandFactory.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Factory;

            use App\Entity\Brand;
            use Sylius\Component\Resource\Factory\FactoryInterface;

            final class BrandFactory implements FactoryInterface
            {
                public function createNew(): Brand
                {
                    return new Brand();
                }
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Implementation de factory personnalisee detectee : BrandFactory');
        self::assertCount(1, $issues);
        self::assertSame(10, $issues[0]->getLine());
        self::assertStringContainsString('implemente FactoryInterface', $issues[0]->getDetail());
        self::assertStringContainsString('createNew()', $issues[0]->getSuggestion());
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie la detection d'un repository etendant EntityRepository de Sylius.
     */
    #[Test]
    public function testDetectsEntityRepositoryExtension(): void
    {
        $filePath = $this->writeProjectFile('src/Repository/BrandRepository.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Repository;

            use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

            class BrandRepository extends EntityRepository
            {
                public function findOneByCode(string $code): ?object
                {
                    return $this->findOneBy(['code' => $code]);
                }
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Repository personnalise detecte : BrandRepository');
        self::assertCount(1, $issues);
        self::assertSame(9, $issues[0]->getLine());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertStringContainsString('(ligne 9) etend EntityRepository', $issues[0]->getDetail());
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie qu'un repository etendant EntityRepository par son nom pleinement
     * qualifie est detecte.
     */
    #[Test]
    public function testDetectsFullyQualifiedEntityRepositoryExtension(): void
    {
        $this->writeProjectFile('src/Repository/SupplierRepository.php', <<<'PHP'
            <?php

            namespace App\Repository;

            class SupplierRepository extends \Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Repository personnalise detecte : SupplierRepository'));
    }

    /**
     * Verifie que les classes qui ne font que mentionner les interfaces ou
     * EntityRepository (sans heritage ni implementation), ainsi que les fichiers
     * PHP non parsables, ne produisent aucune issue.
     */
    #[Test]
    public function testIgnoresPhpFilesOnlyMentioningResourceTypes(): void
    {
        $this->writeProjectFile('src/Service/BrandCatalog.php', <<<'PHP'
            <?php

            namespace App\Service;

            use Psr\Log\LoggerInterface;

            /**
             * Utilise le ResourceInterface, la FactoryInterface et l'EntityRepository
             * des marques sans les etendre.
             */
            final class BrandCatalog extends \ArrayObject implements \Countable
            {
            }

            final class StandaloneCatalog
            {
            }
            PHP);
        $this->writeProjectFile('src/Broken/BrokenRepository.php', "<?php\n\nclass BrokenRepository extends EntityRepository implements ResourceInterface, FactoryInterface {\n");
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection d'une surcharge directe d'un service Sylius
     * (redefinition de sylius.repository.*) dans services.yaml.
     */
    #[Test]
    public function testDetectsDirectSyliusServiceOverride(): void
    {
        $filePath = $this->writeProjectFile('config/services.yaml', <<<'YAML'
            services:
                _defaults:
                    autowire: true
                sylius.repository.product:
                    class: App\Repository\ProductRepository
            YAML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Surcharge de service Sylius detectee');
        self::assertCount(1, $issues);
        self::assertSame('Surcharge de service Sylius detectee : sylius.repository.product', $issues[0]->getMessage());
        self::assertStringContainsString('services.yaml', $issues[0]->getDetail());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertSame(60, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie qu'une balise YAML personnalisee (!tagged_iterator, courante dans
     * services.yaml) n'empeche plus l'analyse du reste du fichier.
     */
    #[Test]
    public function testDetectsServiceOverrideInFileWithCustomYamlTags(): void
    {
        $this->writeProjectFile('config/services.yaml', <<<'YAML'
            services:
                App\Promotion\RuleRegistry:
                    arguments: [!tagged_iterator app.promotion_rule]
                sylius.repository.product:
                    class: App\Repository\ProductRepository
            YAML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Surcharge de service Sylius detectee : sylius.repository.product'));
    }

    /**
     * Verifie la detection d'un decorateur de service Sylius (cle decorates).
     */
    #[Test]
    public function testDetectsSyliusServiceDecorator(): void
    {
        $this->writeProjectFile('config/services.yaml', <<<'YAML'
            services:
                App\Factory\CustomerFactory:
                    decorates: sylius.factory.customer
                    arguments: ['@.inner']
            YAML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Surcharge de service Sylius detectee');
        self::assertCount(1, $issues);
        self::assertSame('Surcharge de service Sylius detectee : App\Factory\CustomerFactory', $issues[0]->getMessage());
        self::assertSame(60, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que les services applicatifs, les decorateurs de services non Sylius
     * et les sections services non structurees ne sont pas signales.
     */
    #[Test]
    public function testIgnoresNonSyliusServices(): void
    {
        $this->writeProjectFile('config/services.yaml', <<<'YAML'
            services:
                App\Newsletter\Subscriber: ~
                App\Mailer\LoggingMailer:
                    decorates: mailer.mailer
                App\Shipping\Calculator:
                    decorates: ['sylius.shipping_calculator']
                app.brand_provider: '@App\Provider\BrandProvider'
            YAML);
        $this->writeProjectFile('config/packages/scalar_services.yaml', "services: none\n");
        /* Une liste YAML produit des cles entieres qui ne sont pas des identifiants de service */
        $this->writeProjectFile('config/packages/list_services.yaml', "services:\n    - sylius.repository.product\n");
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un repository etendant l'EntityRepository de Sylius importe sous
     * un alias (use ... as BaseRepository) est detecte : le nom ecrit dans
     * extends ne ressemble plus au nom de la classe parente.
     */
    #[Test]
    public function testDetectsRepositoryExtendingAliasedSyliusEntityRepository(): void
    {
        $this->writeProjectFile('src/Repository/BrandRepository.php', <<<'PHP'
            <?php

            namespace App\Repository;

            use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository as BaseRepository;

            class BrandRepository extends BaseRepository
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Repository personnalise detecte : BrandRepository');
        self::assertCount(1, $issues);
        self::assertStringContainsString('(ligne 7) etend EntityRepository', $issues[0]->getDetail());
    }

    /**
     * Verifie qu'un repository etendant l'EntityRepository de Doctrine n'est pas
     * signale : seul le nom court est commun avec celui de Sylius, la classe ne
     * depend pas de SyliusResourceBundle.
     */
    #[Test]
    public function testIgnoresRepositoryExtendingDoctrineEntityRepository(): void
    {
        $this->writeProjectFile('src/Repository/AuditLogRepository.php', <<<'PHP'
            <?php

            namespace App\Repository;

            use Doctrine\ORM\EntityRepository;

            class AuditLogRepository extends EntityRepository
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un repository etendant un repository concret de Sylius
     * (cas le plus courant : ProductRepository, OrderRepository...) est detecte.
     */
    #[Test]
    public function testDetectsRepositoryExtendingConcreteSyliusRepository(): void
    {
        $this->writeProjectFile('src/Repository/OrderRepository.php', <<<'PHP'
            <?php

            namespace App\Repository;

            class OrderRepository extends \Sylius\Bundle\CoreBundle\Doctrine\ORM\OrderRepository
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Repository personnalise detecte : OrderRepository');
        self::assertCount(1, $issues);
        self::assertStringContainsString('Sylius\Bundle\CoreBundle\Doctrine\ORM\OrderRepository', $issues[0]->getDetail());
    }

    /**
     * Verifie que le ProductRepository du projet complexe, qui etend le
     * ProductRepository de CoreBundle importe sous alias, est bien detecte.
     */
    #[Test]
    public function testDetectsAliasedCoreRepositoryInComplexFixture(): void
    {
        $report = $this->createReportForFixture('project-complex');

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Repository personnalise detecte : ProductRepository'));
    }

    /**
     * Verifie qu'une ressource implementant ResourceInterface importee sous alias
     * est detectee.
     */
    #[Test]
    public function testDetectsResourceInterfaceImplementedThroughAlias(): void
    {
        $this->writeProjectFile('src/Entity/Brand.php', <<<'PHP'
            <?php

            namespace App\Entity;

            use Sylius\Component\Resource\Model\ResourceInterface as SyliusResource;

            class Brand implements SyliusResource
            {
                public function getId(): ?int
                {
                    return null;
                }
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Implementation de ressource personnalisee detectee : Brand'));
    }

    /**
     * Verifie que les interfaces du nouvel espace de noms Sylius\Resource
     * (SyliusResourceBundle 1.11+) sont detectees au meme titre que les anciennes.
     */
    #[Test]
    public function testDetectsInterfacesFromNewSyliusResourceNamespace(): void
    {
        $this->writeProjectFile('src/Factory/BrandFactory.php', <<<'PHP'
            <?php

            namespace App\Factory;

            use Sylius\Resource\Factory\FactoryInterface;
            use Sylius\Resource\Model\ResourceInterface;

            final class BrandFactory implements FactoryInterface
            {
            }

            final class Brand implements ResourceInterface
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Implementation de factory personnalisee detectee : BrandFactory'));
        self::assertCount(1, $this->issuesWithMessage($report, 'Implementation de ressource personnalisee detectee : Brand'));
    }

    /**
     * Verifie qu'une interface applicative homonyme (ResourceInterface,
     * FactoryInterface) n'est pas confondue avec celles de Sylius.
     */
    #[Test]
    public function testIgnoresHomonymousInterfacesFromOtherNamespaces(): void
    {
        $this->writeProjectFile('src/Import/CsvImport.php', <<<'PHP'
            <?php

            namespace App\Import;

            use App\Contract\ResourceInterface;
            use Symfony\Component\Form\FormFactoryInterface as FactoryInterface;

            final class CsvImport implements ResourceInterface, FactoryInterface
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un identifiant de service Sylius redefini comme alias vers un
     * service applicatif est signale (c'est une surcharge), alors qu'un service
     * applicatif pointant vers un service Sylius ne l'est pas (simple reference).
     */
    #[Test]
    public function testDetectsSyliusServiceRedefinedAsAliasButNotAppAliases(): void
    {
        $this->writeProjectFile('config/services.yaml', <<<'YAML'
            services:
                sylius.repository.product: '@App\Repository\ProductRepository'
                sylius.factory.product:
                    alias: App\Factory\ProductFactory
                app.product_repository: '@sylius.repository.product'
                app.product_factory:
                    alias: sylius.factory.product
            YAML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $messages = array_map(static fn (MigrationIssue $issue): string => $issue->getMessage(), $report->getIssues());
        self::assertSame([
            'Surcharge de service Sylius detectee : sylius.repository.product',
            'Surcharge de service Sylius detectee : sylius.factory.product',
        ], $messages);
    }

    /**
     * Verifie la detection des surcharges et decorateurs de services Sylius
     * declares en XML, sans signaler les services applicatifs ni les alias
     * applicatifs pointant vers un service Sylius.
     */
    #[Test]
    public function testDetectsSyliusServiceOverridesInXmlServices(): void
    {
        $filePath = $this->writeProjectFile('config/services.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" ?>
            <container xmlns="http://symfony.com/schema/dic/services"
                       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                       xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
                <services>
                    <service id="sylius.repository.product" class="App\Repository\ProductRepository" />
                    <service id="App\Factory\CustomerFactory" decorates="sylius.factory.customer">
                        <argument type="service" id=".inner" />
                    </service>
                    <service id="App\Newsletter\Subscriber" />
                    <service id="app.product_repository" alias="sylius.repository.product" />
                </services>
            </container>
            XML);
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame('Surcharge de service Sylius detectee : sylius.repository.product', $issues[0]->getMessage());
        self::assertSame('Surcharge de service Sylius detectee : App\Factory\CustomerFactory', $issues[1]->getMessage());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertSame(60, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie qu'un fichier XML invalide ou sans definition de service
     * est ignore sans interrompre l'analyse.
     */
    #[Test]
    public function testIgnoresInvalidOrUnrelatedXmlFiles(): void
    {
        $this->writeProjectFile('config/services.xml', "<container><services><service id=\"sylius.repository.product\"\n");
        $this->writeProjectFile('config/doctrine/Brand.orm.xml', '<doctrine-mapping><entity name="App\Entity\Brand" /></doctrine-mapping>');
        $report = $this->createTempReport();

        (new ResourceBundleAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }
}
