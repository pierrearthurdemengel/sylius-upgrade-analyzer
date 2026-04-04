<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\ApiPlatform\ApiPlatformMigrationAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur de migration API Platform.
 * Verifie la detection des annotations, attributs et interfaces obsoletes.
 * Utilise des fichiers temporaires pour simuler le code API Platform.
 */
final class ApiPlatformMigrationAnalyzerTest extends TestCase
{
    /** Chemin vers le repertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /** Repertoire temporaire pour les fixtures de test */
    private string $tempDir;

    /**
     * Initialise le repertoire temporaire avant chaque test.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sylius-api-test-' . uniqid();
        mkdir($this->tempDir . '/src', 0777, true);
    }

    /**
     * Nettoie le repertoire temporaire apres chaque test.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
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
     * Cree un rapport de migration pointant vers le repertoire temporaire.
     */
    private function createReportForTempDir(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: $this->tempDir,
        );
    }

    /**
     * Cree un fichier PHP temporaire dans src/ avec le contenu specifie.
     */
    private function createTempPhpFile(string $filename, string $content): void
    {
        $filePath = $this->tempDir . '/src/' . $filename;
        $directory = dirname($filePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($filePath, $content);
    }

    /**
     * Supprime recursivement un repertoire et son contenu.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        /** @var \DirectoryIterator $item */
        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * Verifie que supports() retourne false pour un projet sans API Platform.
     * Le projet trivial ne contient aucun fichier avec @ApiResource ou #[ApiResource].
     */
    #[Test]
    public function testSupportsReturnsFalseForProjectWithoutApiPlatform(): void
    {
        $analyzer = new ApiPlatformMigrationAnalyzer();
        $report = $this->createReportForFixture('project-trivial');

        /* Le projet trivial ne contient aucun element API Platform */
        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que l'analyseur detecte l'annotation @ApiResource dans les docblocks PHP.
     * Cree un fichier temporaire avec l'annotation Doctrine @ApiResource.
     */
    #[Test]
    public function testDetectsApiResourceAnnotation(): void
    {
        $analyzer = new ApiPlatformMigrationAnalyzer();

        /* Creation d'un fichier PHP contenant l'annotation @ApiResource */
        $this->createTempPhpFile('Entity/Product.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;

/**
 * @ApiResource(
 *     collectionOperations={"get"},
 *     itemOperations={"get"}
 * )
 */
class Product
{
    private int $id;
    private string $name;
}
PHP);

        $report = $this->createReportForTempDir();

        /* Verification que supports() detecte l'annotation */
        self::assertTrue($analyzer->supports($report));

        $analyzer->analyze($report);

        /* Recherche des problemes mentionnant l'annotation @ApiResource */
        $annotationIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Annotation @ApiResource'),
        );

        self::assertNotEmpty($annotationIssues, 'L\'annotation @ApiResource aurait du etre detectee.');
    }

    /**
     * Verifie que l'analyseur detecte l'attribut PHP 8 #[ApiResource].
     * Cree un fichier temporaire avec l'attribut natif #[ApiResource].
     */
    #[Test]
    public function testDetectsApiResourceAttribute(): void
    {
        $analyzer = new ApiPlatformMigrationAnalyzer();

        /* Creation d'un fichier PHP contenant l'attribut #[ApiResource] */
        $this->createTempPhpFile('Entity/Order.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;

#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ]
)]
class Order
{
    private int $id;
    private string $number;
}
PHP);

        $report = $this->createReportForTempDir();

        /* Verification que supports() detecte l'attribut */
        self::assertTrue($analyzer->supports($report));

        $analyzer->analyze($report);

        /* Recherche des problemes mentionnant l'attribut #[ApiResource] */
        $attributeIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Attribut #[ApiResource]'),
        );

        self::assertNotEmpty($attributeIssues, 'L\'attribut #[ApiResource] aurait du etre detecte.');
    }

    /**
     * Verifie que l'analyseur detecte l'implementation de DataProviderInterface.
     * Cree un fichier temporaire avec une classe implementant DataProviderInterface.
     */
    #[Test]
    public function testDetectsDataProviderInterface(): void
    {
        $analyzer = new ApiPlatformMigrationAnalyzer();

        /* Creation d'un fichier PHP implementant DataProviderInterface */
        $this->createTempPhpFile('DataProvider/ProductCollectionDataProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;

class ProductCollectionDataProvider implements CollectionDataProviderInterface
{
    public function getCollection(string $resourceClass, ?string $operationName = null, array $context = []): iterable
    {
        return [];
    }

    public function supports(string $resourceClass, ?string $operationName = null, array $context = []): bool
    {
        return true;
    }
}
PHP);

        $report = $this->createReportForTempDir();
        $analyzer->analyze($report);

        /* Recherche des problemes mentionnant l'interface obsolete */
        $interfaceIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Interface obsolete')
                && str_contains($issue->getMessage(), 'CollectionDataProviderInterface'),
        );

        self::assertNotEmpty($interfaceIssues, 'L\'interface CollectionDataProviderInterface aurait du etre detectee.');
    }

    /**
     * Verifie que les problemes sont de severite WARNING dans la categorie API.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
    {
        $analyzer = new ApiPlatformMigrationAnalyzer();

        /* Creation d'un fichier avec annotation @ApiResource */
        $this->createTempPhpFile('Entity/Item.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * @ApiResource()
 */
class Item
{
    private int $id;
}
PHP);

        $report = $this->createReportForTempDir();
        $analyzer->analyze($report);

        /* Tous les problemes doivent etre des WARNING dans la categorie API */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::WARNING, $issue->getSeverity());
            self::assertSame(Category::API, $issue->getCategory());
        }
    }

    /**
     * Verifie l'estimation de 2 heures (120 minutes) par ressource dans le resume.
     * Le resume global multiplie le nombre de ressources par MINUTES_PER_RESOURCE (120).
     */
    #[Test]
    public function testEstimatesTwoHoursPerResource(): void
    {
        $analyzer = new ApiPlatformMigrationAnalyzer();

        /* Creation de deux fichiers avec des annotations @ApiResource */
        $this->createTempPhpFile('Entity/Product.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * @ApiResource()
 */
class Product
{
    private int $id;
}
PHP);

        $this->createTempPhpFile('Entity/Order.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * @ApiResource()
 */
class Order
{
    private int $id;
}
PHP);

        $report = $this->createReportForTempDir();
        $analyzer->analyze($report);

        /* Recherche du probleme de synthese */
        $summaryIssues = array_values(array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'ressource(s) API Platform necessitant une migration'),
        ));

        self::assertNotEmpty($summaryIssues, 'Le resume de migration API Platform aurait du etre cree.');

        /* Verification que l'estimation est un multiple de 120 minutes */
        $estimatedMinutes = $summaryIssues[0]->getEstimatedMinutes();
        self::assertSame(0, $estimatedMinutes % 120, 'L\'estimation doit etre un multiple de 120 minutes.');
        self::assertGreaterThanOrEqual(240, $estimatedMinutes, 'Au moins 2 ressources detectees, soit minimum 240 minutes.');
    }

    /**
     * Verifie que getName() retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new ApiPlatformMigrationAnalyzer();

        self::assertSame('API Platform Migration', $analyzer->getName());
    }
}
