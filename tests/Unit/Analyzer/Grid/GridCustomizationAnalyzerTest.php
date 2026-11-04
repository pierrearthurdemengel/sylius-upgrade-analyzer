<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Grid;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Grid\GridCustomizationAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de personnalisations de grilles Sylius.
 * Verifie la detection des definitions YAML, des classes PHP et l'estimation du temps.
 */
final class GridCustomizationAnalyzerTest extends TestCase
{
    /** Chemin vers le repertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /** Repertoire temporaire servant de projet Sylius pour les cas cibles */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('grid_', true);
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
     * Verifie que supports() retourne true pour un projet avec sylius_grid.yaml.
     * Le projet complexe contient un fichier config/packages/sylius_grid.yaml.
     */
    #[Test]
    public function testSupportsReturnsTrueWithGridConfig(): void
    {
        $analyzer = new GridCustomizationAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        /* Le projet complexe contient sylius_grid.yaml, supports doit retourner true */
        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie que supports() retourne false pour un projet sans configuration de grilles.
     * Le projet trivial ne contient aucun fichier sylius_grid.
     */
    #[Test]
    public function testSupportsReturnsFalseWithNoGridConfig(): void
    {
        $analyzer = new GridCustomizationAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        /* Le projet trivial n'a ni YAML de grilles ni classes PHP de grilles */
        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte les definitions de grilles depuis le fichier YAML.
     * Le fichier sylius_grid.yaml du projet complexe contient une grille app_admin_order.
     */
    #[Test]
    public function testDetectsGridDefinitionsFromYaml(): void
    {
        $analyzer = new GridCustomizationAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche des problemes mentionnant les definitions YAML de grilles */
        $yamlIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'definition(s) de grille(s) YAML'),
        );

        self::assertNotEmpty($yamlIssues, 'Les definitions de grilles YAML auraient du etre detectees.');

        /* Verification que la grille app_admin_order est mentionnee dans le detail */
        $yamlIssue = array_values($yamlIssues)[0];
        self::assertStringContainsString('app_admin_order', $yamlIssue->getDetail());
    }

    /**
     * Verifie que les problemes de grilles sont de severite WARNING.
     * Toutes les detections de grilles doivent etre classees comme avertissements.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $analyzer = new GridCustomizationAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Tous les problemes detectes doivent etre des WARNING */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
            self::assertSame(Category::GRID, $issue->getCategory());
        }
    }

    /**
     * Verifie l'estimation en heures par grille YAML.
     * Chaque grille simple YAML est estimee a 60 minutes (1 heure).
     * Le projet complexe contient 1 grille, donc 60 minutes au total.
     */
    #[Test]
    public function testEstimatesCorrectHoursPerGrid(): void
    {
        $analyzer = new GridCustomizationAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Recherche de l'issue YAML pour verifier l'estimation */
        $yamlIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'definition(s) de grille(s) YAML'),
        ));

        self::assertNotEmpty($yamlIssues);

        /* 1 grille * 60 minutes = 60 minutes */
        self::assertSame(60, $yamlIssues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que getName() retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new GridCustomizationAnalyzer();

        self::assertSame('Grid Customization', $analyzer->getName());
    }

    /**
     * Verifie que l'analyseur attribue bien le nom 'Grid Customization' aux issues.
     * Le champ analyzer de chaque issue doit correspondre au nom de l'analyseur.
     */
    #[Test]
    public function testIssuesReferenceCorrectAnalyzerName(): void
    {
        $analyzer = new GridCustomizationAnalyzer();
        $report = $this->createReportForFixture('project-complex');

        $analyzer->analyze($report);

        /* Chaque issue doit mentionner le bon nom d'analyseur */
        foreach ($report->getIssues() as $issue) {
            self::assertSame('Grid Customization', $issue->getAnalyzer());
        }
    }

    /**
     * Verifie que supports() retourne true pour un projet sans YAML de grilles
     * mais contenant une classe de grille PHP.
     */
    #[Test]
    public function testSupportsReturnsTrueWithGridPhpClassOnly(): void
    {
        $this->writeProjectFile('src/Grid/BrandGrid.php', <<<'PHP'
            <?php

            namespace App\Grid;

            use Sylius\Bundle\GridBundle\Grid\AbstractGrid;

            final class BrandGrid extends AbstractGrid
            {
            }
            PHP);

        self::assertTrue((new GridCustomizationAnalyzer())->supports($this->createTempReport()));
    }

    /**
     * Verifie que supports() retourne false quand la configuration ne contient ni
     * sylius_grid ni YAML valide, et que le code source ne reference aucun type de grille.
     */
    #[Test]
    public function testSupportsReturnsFalseWithUnrelatedConfigAndSources(): void
    {
        $this->writeProjectFile('config/packages/framework.yaml', "framework:\n    secret: '%env(APP_SECRET)%'\n");
        $this->writeProjectFile('config/packages/broken.yml', "sylius_grid: [unclosed\n");
        $this->writeProjectFile('src/Kernel.php', "<?php\n\nnamespace App;\n\nfinal class Kernel\n{\n}\n");

        self::assertFalse((new GridCustomizationAnalyzer())->supports($this->createTempReport()));
    }

    /**
     * Verifie qu'un projet vide (ni config/ ni src/) ne produit aucune issue.
     */
    #[Test]
    public function testReturnsNoIssuesForProjectWithoutConfigAndSrc(): void
    {
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un fichier YAML declarant plusieurs grilles produit une seule issue
     * listant toutes les grilles, estimee a 60 minutes par grille.
     */
    #[Test]
    public function testDetectsMultipleYamlGridsInSingleFile(): void
    {
        $filePath = $this->writeProjectFile('config/packages/sylius_grid.yaml', <<<'YAML'
            sylius_grid:
                grids:
                    app_admin_brand:
                        driver:
                            name: doctrine/orm
                            options:
                                class: App\Entity\Brand
                        fields:
                            name:
                                type: string
                                label: sylius.ui.name
                    app_admin_supplier:
                        driver:
                            name: doctrine/orm
                            options:
                                class: App\Entity\Supplier
            YAML);
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertStringStartsWith('2 definition(s) de grille(s) YAML detectee(s)', $issues[0]->getMessage());
        self::assertStringContainsString('app_admin_brand, app_admin_supplier', $issues[0]->getDetail());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertSame(Category::GRID, $issues[0]->getCategory());
        /* 2 grilles * 60 minutes */
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que les fichiers YAML invalides, sans grilles ou avec une cle
     * sylius_grid non structuree ne produisent aucune issue.
     */
    #[Test]
    public function testIgnoresInvalidOrEmptyGridYaml(): void
    {
        $this->writeProjectFile('config/packages/broken.yaml', "sylius_grid: [unclosed\n");
        $this->writeProjectFile('config/packages/scalar.yaml', "sylius_grid: enabled\n");
        $this->writeProjectFile('config/packages/templates.yaml', "sylius_grid:\n    templates:\n        action:\n            show: '@App/Grid/Action/show.html.twig'\n");
        $this->writeProjectFile('config/packages/scalar_grids.yaml', "sylius_grid:\n    grids: none\n");
        $this->writeProjectFile('config/packages/empty.yml', "sylius_grid:\n    grids: {}\n");
        $this->writeProjectFile('config/packages/plain.yaml', "just a string\n");
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection d'une classe de grille implementant ResourceAwareGridInterface.
     */
    #[Test]
    public function testDetectsGridImplementingResourceAwareGridInterface(): void
    {
        $filePath = $this->writeProjectFile('src/Grid/BrandGrid.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Grid;

            use App\Entity\Brand;
            use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
            use Sylius\Bundle\GridBundle\Grid\AbstractGrid;
            use Sylius\Bundle\GridBundle\Grid\ResourceAwareGridInterface;

            final class BrandGrid extends AbstractGrid implements ResourceAwareGridInterface
            {
                public static function getName(): string
                {
                    return 'app_admin_brand';
                }

                public function buildGrid(GridBuilderInterface $gridBuilder): void
                {
                }

                public function getResourceClass(): string
                {
                    return Brand::class;
                }
            }
            PHP);
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        /* L'interface est prioritaire : une seule issue meme si la classe etend aussi AbstractGrid */
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame('Classe de grille personnalisee detectee : BrandGrid', $issues[0]->getMessage());
        self::assertStringContainsString('(ligne 12) implemente ResourceAwareGridInterface', $issues[0]->getDetail());
        self::assertSame(12, $issues[0]->getLine());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie la detection d'une classe de grille etendant AbstractGrid par son
     * nom pleinement qualifie, sans implementer ResourceAwareGridInterface.
     */
    #[Test]
    public function testDetectsGridExtendingAbstractGrid(): void
    {
        $this->writeProjectFile('src/Grid/SupplierGrid.php', <<<'PHP'
            <?php

            namespace App\Grid;

            final class SupplierGrid extends \Sylius\Bundle\GridBundle\Grid\AbstractGrid implements \JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    return [];
                }
            }
            PHP);
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame('Classe de grille personnalisee detectee : SupplierGrid', $issues[0]->getMessage());
        self::assertStringContainsString('(ligne 5) etend AbstractGrid', $issues[0]->getDetail());
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie la detection d'un type de colonne personnalise, estime a 120 minutes.
     */
    #[Test]
    public function testDetectsCustomColumnType(): void
    {
        $filePath = $this->writeProjectFile('src/Grid/Column/MoneyColumnType.php', <<<'PHP'
            <?php

            namespace App\Grid\Column;

            use Sylius\Component\Grid\Definition\Column\ColumnTypeInterface;

            final class MoneyColumnType implements ColumnTypeInterface
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame('Type de colonne personnalise detecte : MoneyColumnType', $issues[0]->getMessage());
        self::assertStringContainsString('(ligne 7) implemente ColumnTypeInterface', $issues[0]->getDetail());
        self::assertStringContainsString('ColumnTypeInterface dans MoneyColumnType', $issues[0]->getSuggestion());
        self::assertSame(7, $issues[0]->getLine());
        self::assertSame($filePath, $issues[0]->getFile());
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie la detection d'un type de filtre personnalise, estime a 120 minutes.
     */
    #[Test]
    public function testDetectsCustomFilterType(): void
    {
        $this->writeProjectFile('src/Grid/Filter/BrandFilterType.php', <<<'PHP'
            <?php

            namespace App\Grid\Filter;

            final class BrandFilterType implements \Sylius\Component\Grid\Filtering\FilterTypeInterface
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame('Type de filtre personnalise detecte : BrandFilterType', $issues[0]->getMessage());
        self::assertStringContainsString('Ce type de filtre personnalise', $issues[0]->getDetail());
        self::assertSame(120, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie la detection d'un type d'action personnalise, estime comme complexe (240 minutes).
     */
    #[Test]
    public function testDetectsCustomActionTypeAsComplex(): void
    {
        $this->writeProjectFile('src/Grid/Action/ExportActionType.php', <<<'PHP'
            <?php

            namespace App\Grid\Action;

            use Sylius\Bundle\GridBundle\ActionType\ActionTypeInterface;

            final class ExportActionType implements ActionTypeInterface
            {
            }
            PHP);
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame('Type de action personnalise detecte : ExportActionType', $issues[0]->getMessage());
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
        self::assertSame(240, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie que les classes qui ne font que mentionner les types de grilles,
     * sans heritage ni implementation, ainsi que les fichiers PHP non parsables,
     * ne produisent aucune issue.
     */
    #[Test]
    public function testIgnoresPhpFilesOnlyMentioningGridTypes(): void
    {
        $this->writeProjectFile('src/Grid/GridRegistry.php', <<<'PHP'
            <?php

            namespace App\Grid;

            /**
             * Reference AbstractGrid, ResourceAwareGridInterface, ColumnTypeInterface,
             * FilterTypeInterface et ActionTypeInterface sans les utiliser.
             */
            final class GridRegistry extends \ArrayObject implements \Countable
            {
            }

            final class StandaloneRegistry
            {
            }
            PHP);
        $this->writeProjectFile(
            'src/Grid/BrokenGrid.php',
            "<?php\n\nclass BrokenGrid extends AbstractGrid implements ResourceAwareGridInterface, ColumnTypeInterface, FilterTypeInterface, ActionTypeInterface {\n",
        );
        $report = $this->createTempReport();

        (new GridCustomizationAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }
}
