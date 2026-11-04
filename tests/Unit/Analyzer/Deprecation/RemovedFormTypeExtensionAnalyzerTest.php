<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\RemovedFormTypeExtensionAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des extensions de formulaire supprimees par Sylius 2.0
 * (classes heritees et references aux services retires).
 */
final class RemovedFormTypeExtensionAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('formext_', true);
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
     * Verifie que supports retourne false pour un projet sans src/.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutSrcDir(): void
    {
        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand un fichier PHP reference AbstractTypeExtension.
     */
    #[Test]
    public function testSupportsReturnsTrueWithAbstractTypeExtension(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomFormExtension.php', <<<'PHP'
<?php

namespace App\Form;

use Symfony\Component\Form\AbstractTypeExtension;

class CustomFormExtension extends AbstractTypeExtension
{
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie qu'une classe heritant d'une extension supprimee est bloquante (elle ne se charge
     * plus) tandis que le resume global reste un avertissement.
     */
    #[Test]
    public function testReportsInheritingClassAsBreakingAndSummaryAsWarning(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomFormExtension.php', <<<'PHP'
<?php

namespace App\Form;

use Sylius\Bundle\CoreBundle\Form\Extension\LocaleTypeExtension;

class CustomFormExtension extends LocaleTypeExtension
{
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame(Severity::BREAKING, $issues[0]->getSeverity());
        self::assertSame(Severity::WARNING, $issues[1]->getSeverity());
    }

    /**
     * Verifie qu'une extension heritant d'une extension Sylius supprimee en 2.0 est signalee,
     * meme si elle declare getExtendedTypes() comme toute extension moderne.
     */
    #[Test]
    public function testDetectsExtensionInheritingRemovedSyliusExtension(): void
    {
        $filePath = $this->writeFile('src/Form/Extension/CustomerTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Sylius\Bundle\CoreBundle\Form\Extension\CustomerTypeExtension as BaseCustomerTypeExtension;
use Sylius\Bundle\CustomerBundle\Form\Type\CustomerType;

final class CustomerTypeExtension extends BaseCustomerTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [CustomerType::class];
    }
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame(
            'Extension de formulaire "CustomerTypeExtension" heritant de '
            . 'Sylius\Bundle\CoreBundle\Form\Extension\CustomerTypeExtension, supprimee dans Sylius 2.0',
            $issues[0]->getMessage(),
        );
        self::assertSame(realpath($filePath), $issues[0]->getFile());
        self::assertSame(8, $issues[0]->getLine());
        self::assertSame(60, $issues[1]->getEstimatedMinutes());
    }

    /**
     * Verifie qu'une extension Symfony ordinaire n'est pas signalee : Sylius 2.0 n'impose
     * aucune priorite aux extensions de formulaire du projet.
     */
    #[Test]
    public function testIgnoresPlainSymfonyExtension(): void
    {
        $this->writeFile('src/Form/Extension/ProductTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

final class ProductTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new RemovedFormTypeExtensionAnalyzer();

        self::assertSame('Removed Form Type Extension', $analyzer->getName());
    }

    /**
     * Verifie que supports retourne false quand src/ existe mais qu'aucun fichier
     * ne reference AbstractTypeExtension.
     */
    #[Test]
    public function testSupportsReturnsFalseWhenNoFileReferencesAbstractTypeExtension(): void
    {
        $this->writeFile('src/Form/Type/BrandType.php', <<<'PHP'
<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;

final class BrandType extends AbstractType
{
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();

        self::assertFalse($analyzer->supports($this->createReport()));
    }

    /**
     * Verifie que l'analyse d'un projet sans src/ ni config/ ne produit aucun probleme.
     */
    #[Test]
    public function testProducesNoIssueForEmptyProject(): void
    {
        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que le probleme par classe porte le fichier, la ligne, la categorie
     * et que le resume global estime une heure par extension.
     */
    #[Test]
    public function testReportsClassIssueWithFileLineAndGlobalEstimate(): void
    {
        $filePath = $this->writeFile('src/Form/Extension/CountryTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Sylius\Bundle\CoreBundle\Form\Extension\CountryTypeExtension as BaseCountryTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

final class CountryTypeExtension extends BaseCountryTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);

        $classIssue = $issues[0];
        self::assertSame(
            'Extension de formulaire "CountryTypeExtension" heritant de '
            . 'Sylius\Bundle\CoreBundle\Form\Extension\CountryTypeExtension, supprimee dans Sylius 2.0',
            $classIssue->getMessage(),
        );
        self::assertSame(Category::DEPRECATION, $classIssue->getCategory());
        self::assertSame(realpath($filePath), $classIssue->getFile());
        self::assertSame(8, $classIssue->getLine());
        self::assertStringContainsString('CountryTypeExtension.php', $classIssue->getDetail());
        self::assertSame(0, $classIssue->getEstimatedMinutes());
        self::assertSame('https://docs.sylius.com/migration-2.0', $classIssue->getDocUrl());

        $globalIssue = $issues[1];
        self::assertSame('1 extension(s) de type de formulaire a revoir', $globalIssue->getMessage());
        self::assertSame(60, $globalIssue->getEstimatedMinutes());
    }

    /**
     * Verifie qu'une extension declarant getExtendedTypes() est consideree comme conforme.
     */
    #[Test]
    public function testIgnoresExtensionDeclaringGetExtendedTypes(): void
    {
        $this->writeFile('src/Form/Extension/CustomerProfileTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Sylius\Bundle\CustomerBundle\Form\Type\CustomerProfileType;
use Symfony\Component\Form\AbstractTypeExtension;

final class CustomerProfileTypeExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [CustomerProfileType::class];
    }
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que les classes sans parent ou heritant d'un autre type ne sont pas signalees.
     */
    #[Test]
    public function testIgnoresClassesNotExtendingAbstractTypeExtension(): void
    {
        $this->writeFile('src/Form/Type/BrandType.php', <<<'PHP'
<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;

final class BrandType extends AbstractType
{
}

final class BrandChoiceLoader
{
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'une classe anonyme heritant d'une extension Sylius supprimee est signalee
     * avec un libelle generique.
     */
    #[Test]
    public function testLabelsAnonymousExtensionClass(): void
    {
        $this->writeFile('src/Form/ExtensionFactory.php', <<<'PHP'
<?php

namespace App\Form;

use Symfony\Component\Form\AbstractTypeExtension;

final class ExtensionFactory
{
    public function create(): AbstractTypeExtension
    {
        return new class () extends \Sylius\Bundle\CoreBundle\Form\Extension\LocaleTypeExtension {
        };
    }
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());
        self::assertContains(
            'Extension de formulaire "(anonyme)" heritant de '
            . 'Sylius\Bundle\CoreBundle\Form\Extension\LocaleTypeExtension, supprimee dans Sylius 2.0',
            $messages,
        );
    }

    /**
     * Verifie qu'un fichier PHP syntaxiquement invalide est ignore sans interrompre
     * l'analyse des autres fichiers.
     */
    #[Test]
    public function testSkipsUnparsablePhpFile(): void
    {
        $this->writeFile('src/Form/Broken.php', "<?php\n\nclass Broken extends AbstractTypeExtension {\n");
        $this->writeFile('src/Form/Extension/ChannelTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Sylius\Bundle\CoreBundle\Form\Extension\CountryTypeExtension;

final class ChannelTypeExtension extends CountryTypeExtension
{
}
PHP);

        $analyzer = new RemovedFormTypeExtensionAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $classIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_starts_with($issue->getMessage(), 'Extension de formulaire'),
        );
        self::assertCount(1, $classIssues);
        self::assertStringContainsString('ChannelTypeExtension', array_values($classIssues)[0]->getMessage());
    }

    /**
     * Verifie que supports retourne true quand seule la configuration reference un service supprime.
     */
    #[Test]
    public function testSupportsProjectReferencingRemovedServiceInConfigOnly(): void
    {
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    app.form.extension.type.customer:
        decorates: sylius.form.extension.type.customer
YAML);

        self::assertTrue((new RemovedFormTypeExtensionAnalyzer())->supports($this->createReport()));
    }

    /**
     * Verifie la detection d'une decoration d'un service d'extension supprime.
     */
    #[Test]
    public function testDetectsDecorationOfRemovedExtensionService(): void
    {
        $filePath = $this->writeFile('config/services.yaml', <<<'YAML'
services:
    App\Form\Extension\CustomerTypeExtension:
        decorates: sylius.form.extension.type.customer
        arguments: ['@.inner']
YAML);

        $report = $this->createReport();
        (new RemovedFormTypeExtensionAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame(
            'Reference au service "sylius.form.extension.type.customer", supprime dans Sylius 2.0, dans services.yaml',
            $issues[0]->getMessage(),
        );
        self::assertSame(Severity::BREAKING, $issues[0]->getSeverity());
        self::assertSame(realpath($filePath), $issues[0]->getFile());
        self::assertSame(60, $issues[1]->getEstimatedMinutes());
    }

    /**
     * Verifie la detection d'une injection ('@id' ou '@?id') et d'une redefinition
     * des services supprimes, avec une issue par service et par fichier.
     */
    #[Test]
    public function testDetectsInjectionAndRedefinitionOfRemovedServices(): void
    {
        $this->writeFile('config/services/forms.yml', <<<'YAML'
services:
    sylius.form.extension.type.country:
        class: App\Form\Extension\CountryTypeExtension
    App\Form\LocaleChoiceBuilder:
        arguments:
            - '@?sylius.form.extension.type.locale'
            - '@sylius.form.extension.type.locale'
YAML);

        $report = $this->createReport();
        (new RemovedFormTypeExtensionAnalyzer())->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());
        $relativePath = 'services' . \DIRECTORY_SEPARATOR . 'forms.yml';
        self::assertContains('Reference au service "sylius.form.extension.type.country", supprime dans Sylius 2.0, dans ' . $relativePath, $messages);
        self::assertContains('Reference au service "sylius.form.extension.type.locale", supprime dans Sylius 2.0, dans ' . $relativePath, $messages);
        self::assertContains('2 extension(s) de type de formulaire a revoir', $messages);
        self::assertCount(3, $messages);
    }

    /**
     * Verifie qu'un tag form.type_extension sans priorite n'est plus signale : Sylius 2.0
     * n'impose aucune priorite aux extensions de formulaire du projet.
     */
    #[Test]
    public function testIgnoresFormTypeExtensionTagWithoutPriority(): void
    {
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    app.form.extension.type.product_variant:
        class: App\Form\Extension\ProductVariantTypeExtension
        tags:
            - form.type_extension
            - { name: form.type_extension, extended_type: Sylius\Bundle\ProductBundle\Form\Type\ProductType }
YAML);

        $report = $this->createReport();
        (new RemovedFormTypeExtensionAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que les services Sylius encore presents en 2.0 et les fichiers YAML invalides
     * ou de structure inattendue sont ignores.
     */
    #[Test]
    public function testIgnoresRemainingSyliusServicesAndInvalidYaml(): void
    {
        $this->writeFile('config/broken.yaml', "services:\n  app.foo: [unclosed\n");
        $this->writeFile('config/scalar.yaml', 'just a string');
        $this->writeFile('config/services_as_string.yaml', 'services: none');
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    App\Form\Extension\CatalogPromotionTypeExtension:
        decorates: sylius.form.extension.type.catalog_promotion
    App\Promotion\RuleRegistry:
        arguments: [!tagged_iterator app.promotion_rule]
    app.description: 'sylius.form.extension.type.country est mentionne dans un texte'
YAML);

        $report = $this->createReport();
        (new RemovedFormTypeExtensionAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que le resume global cumule classes heritees et references de services.
     * Une classe + une reference = 2 elements, soit 120 minutes.
     */
    #[Test]
    public function testGlobalIssueCumulatesClassesAndServiceReferences(): void
    {
        $this->writeFile('src/Form/Extension/TaxonTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Sylius\Bundle\AdminBundle\Form\Extension\CatalogPromotionScopeTypeExtension;

final class TaxonTypeExtension extends CatalogPromotionScopeTypeExtension
{
}
PHP);
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    app.form.extension.type.locale:
        decorates: sylius.form.extension.type.locale
YAML);

        $report = $this->createReport();
        (new RemovedFormTypeExtensionAnalyzer())->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(3, $issues);
        self::assertSame('2 extension(s) de type de formulaire a revoir', $issues[2]->getMessage());
        self::assertSame(120, $issues[2]->getEstimatedMinutes());
    }

    /**
     * Ecrit un fichier dans le projet temporaire en creant les repertoires parents.
     * Retourne le chemin complet du fichier cree.
     */
    private function writeFile(string $relativePath, string $content): string
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $directory = \dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }
}
