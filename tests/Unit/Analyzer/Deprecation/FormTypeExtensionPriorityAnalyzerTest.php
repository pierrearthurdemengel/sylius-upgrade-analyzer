<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\FormTypeExtensionPriorityAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des extensions de types de formulaire.
 * Verifie la detection des extensions sans priorite explicite.
 */
final class FormTypeExtensionPriorityAnalyzerTest extends TestCase
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
        $analyzer = new FormTypeExtensionPriorityAnalyzer();
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

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection d'une extension sans priorite explicite.
     */
    #[Test]
    public function testDetectsExtensionWithoutPriority(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/CustomFormExtension.php', <<<'PHP'
<?php

namespace App\Form;

use Symfony\Component\Form\AbstractTypeExtension;

class CustomFormExtension extends AbstractTypeExtension
{
    public function buildForm($builder, array $options): void {}
}
PHP);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $extensionIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'CustomFormExtension'),
        );
        self::assertNotEmpty($extensionIssues);
    }

    /**
     * Verifie que les problemes sont de severite WARNING.
     */
    #[Test]
    public function testCreatesWarningIssues(): void
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

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
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
        $analyzer = new FormTypeExtensionPriorityAnalyzer();

        self::assertSame('Form Type Extension Priority', $analyzer->getName());
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

        $analyzer = new FormTypeExtensionPriorityAnalyzer();

        self::assertFalse($analyzer->supports($this->createReport()));
    }

    /**
     * Verifie que l'analyse d'un projet sans src/ ni config/ ne produit aucun probleme.
     */
    #[Test]
    public function testProducesNoIssueForEmptyProject(): void
    {
        $analyzer = new FormTypeExtensionPriorityAnalyzer();
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
        $filePath = $this->writeFile('src/Form/Extension/ProductTypeExtension.php', <<<'PHP'
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

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);

        $classIssue = $issues[0];
        self::assertSame('Extension de formulaire "ProductTypeExtension" sans priorite explicite', $classIssue->getMessage());
        self::assertSame(Category::DEPRECATION, $classIssue->getCategory());
        self::assertSame(realpath($filePath), $classIssue->getFile());
        self::assertSame(8, $classIssue->getLine());
        self::assertStringContainsString('ProductTypeExtension.php', $classIssue->getDetail());
        self::assertSame(0, $classIssue->getEstimatedMinutes());
        self::assertSame('https://docs.sylius.com/migration-2.0', $classIssue->getDocUrl());

        $globalIssue = $issues[1];
        self::assertSame(
            '1 extension(s) de type de formulaire detectee(s) sans priorite explicite',
            $globalIssue->getMessage(),
        );
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

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'une extension declarant getPriority() est consideree comme conforme.
     */
    #[Test]
    public function testIgnoresExtensionDeclaringGetPriority(): void
    {
        $this->writeFile('src/Form/Extension/AddressTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;

final class AddressTypeExtension extends AbstractTypeExtension
{
    public static function getPriority(): int
    {
        return 10;
    }
}
PHP);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
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

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'une classe anonyme heritant d'AbstractTypeExtension est signalee
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
        return new class () extends AbstractTypeExtension {
        };
    }
}
PHP);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $messages = array_map(static fn ($issue): string => $issue->getMessage(), $report->getIssues());
        self::assertContains('Extension de formulaire "(anonyme)" sans priorite explicite', $messages);
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

use Symfony\Component\Form\AbstractTypeExtension;

final class ChannelTypeExtension extends AbstractTypeExtension
{
}
PHP);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
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
     * Verifie la detection d'un tag form.type_extension declare sous forme de chaine.
     */
    #[Test]
    public function testDetectsStringFormTypeExtensionTag(): void
    {
        $filePath = $this->writeFile('config/services.yaml', <<<'YAML'
services:
    app.form.extension.type.product_variant:
        class: App\Form\Extension\ProductVariantTypeExtension
        tags:
            - form.type_extension
YAML);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertSame(
            'Tag form.type_extension sans priorite pour le service "app.form.extension.type.product_variant"',
            $issues[0]->getMessage(),
        );
        self::assertStringContainsString('services.yaml', $issues[0]->getDetail());
        self::assertSame(realpath($filePath), $issues[0]->getFile());
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
    }

    /**
     * Verifie la detection d'un tag form.type_extension structure sans attribut priority.
     */
    #[Test]
    public function testDetectsArrayFormTypeExtensionTagWithoutPriority(): void
    {
        $this->writeFile('config/services/forms.yml', <<<'YAML'
services:
    app.form.extension.type.customer_registration:
        class: App\Form\Extension\CustomerRegistrationTypeExtension
        tags:
            - { name: form.type_extension, extended_type: Sylius\Bundle\CoreBundle\Form\Type\Customer\CustomerRegistrationType }
YAML);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(2, $issues);
        self::assertStringContainsString('app.form.extension.type.customer_registration', $issues[0]->getMessage());
        self::assertStringContainsString('sans attribut priority', $issues[0]->getDetail());
        self::assertStringContainsString('priority: <valeur>', $issues[0]->getSuggestion());
    }

    /**
     * Verifie qu'un tag form.type_extension avec priorite explicite n'est pas signale.
     */
    #[Test]
    public function testIgnoresFormTypeExtensionTagWithPriority(): void
    {
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    app.form.extension.type.product:
        class: App\Form\Extension\ProductTypeExtension
        tags:
            - { name: form.type_extension, extended_type: Sylius\Bundle\ProductBundle\Form\Type\ProductType, priority: 100 }
YAML);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que les autres tags (chaine ou structure) ne sont pas signales.
     */
    #[Test]
    public function testIgnoresUnrelatedServiceTags(): void
    {
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    app.form.type.brand:
        class: App\Form\Type\BrandType
        tags:
            - form.type
            - { name: kernel.event_listener, event: sylius.product.pre_create }
YAML);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que les fichiers YAML invalides ou de structure inattendue sont ignores.
     */
    #[Test]
    public function testSkipsInvalidOrUnexpectedYamlStructures(): void
    {
        $this->writeFile('config/broken.yaml', "services:\n  app.foo: [unclosed\n");
        $this->writeFile('config/scalar.yaml', 'just a string');
        $this->writeFile('config/services_as_string.yaml', 'services: none');
        $this->writeFile('config/routes.yaml', <<<'YAML'
sylius_shop:
    resource: "@SyliusShopBundle/Resources/config/routing.yml"
YAML);
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    _defaults:
        autowire: true
    App\Form\Extension\NullService: ~
    app.form.extension.untagged:
        class: App\Form\Extension\UntaggedTypeExtension
    app.form.extension.tags_as_string:
        class: App\Form\Extension\ScalarTagsTypeExtension
        tags: form.type_extension
YAML);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que le resume global cumule les classes et les tags detectes.
     * Une classe + deux tags = 3 extensions, soit 180 minutes.
     */
    #[Test]
    public function testGlobalIssueCumulatesClassesAndTags(): void
    {
        $this->writeFile('src/Form/Extension/TaxonTypeExtension.php', <<<'PHP'
<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;

final class TaxonTypeExtension extends AbstractTypeExtension
{
}
PHP);
        $this->writeFile('config/services.yaml', <<<'YAML'
services:
    app.form.extension.type.taxon:
        class: App\Form\Extension\TaxonTypeExtension
        tags:
            - form.type_extension
    app.form.extension.type.shipping_method:
        class: App\Form\Extension\ShippingMethodTypeExtension
        tags:
            - { name: form.type_extension }
YAML);

        $analyzer = new FormTypeExtensionPriorityAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $issues = $report->getIssues();
        self::assertCount(4, $issues);

        $globalIssue = $issues[3];
        self::assertSame(
            '3 extension(s) de type de formulaire detectee(s) sans priorite explicite',
            $globalIssue->getMessage(),
        );
        self::assertSame(180, $globalIssue->getEstimatedMinutes());
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
