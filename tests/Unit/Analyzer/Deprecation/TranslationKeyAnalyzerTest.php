<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\TranslationKeyAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour l'analyseur des cles de traduction.
 * Verifie la detection des cles de traduction Sylius renommees dans Sylius 2.0.
 */
final class TranslationKeyAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('transkey_', true);
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
     * Verifie que supports retourne false sans le repertoire translations/.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutTranslationsDir(): void
    {
        $analyzer = new TranslationKeyAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand translations/ existe.
     */
    #[Test]
    public function testSupportsReturnsTrueWithTranslationsDir(): void
    {
        mkdir($this->tempDir . '/translations', 0755, true);

        $analyzer = new TranslationKeyAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection des cles de traduction avec prefixe renomme.
     */
    #[Test]
    public function testDetectsChangedTranslationKeyPrefixes(): void
    {
        $transDir = $this->tempDir . '/translations';
        mkdir($transDir, 0755, true);
        file_put_contents($transDir . '/messages.fr.yaml', <<<'YAML'
sylius:
    ui:
        admin:
            dashboard: Tableau de bord
            orders: Commandes
YAML);

        $analyzer = new TranslationKeyAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $transIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'sylius.ui.admin'),
        );
        self::assertNotEmpty($transIssues);
    }

    /**
     * Verifie que les problemes sont de severite SUGGESTION.
     */
    #[Test]
    public function testCreatesSuggestionIssues(): void
    {
        $transDir = $this->tempDir . '/translations';
        mkdir($transDir, 0755, true);
        file_put_contents($transDir . '/messages.fr.yaml', <<<'YAML'
sylius:
    ui:
        admin:
            dashboard: Tableau de bord
YAML);

        $analyzer = new TranslationKeyAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        foreach ($report->getIssues() as $issue) {
            self::assertSame(Severity::SUGGESTION, $issue->getSeverity());
        }
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new TranslationKeyAnalyzer();

        self::assertSame('Translation Key', $analyzer->getName());
    }

    /**
     * Verifie qu'aucune issue n'est produite pour des traductions applicatives hors prefixes Sylius.
     */
    #[Test]
    public function testReturnsNoIssuesForApplicationOnlyTranslations(): void
    {
        $this->writeFile('translations/messages.fr.yaml', "app:\n    ui:\n        loyalty_points: Points de fidelite\n");

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un prefixe impacte ne produit qu'une issue par fichier, quel que soit le nombre de cles.
     */
    #[Test]
    public function testGroupsKeysByPrefixWithinYamlFile(): void
    {
        $file = $this->writeFile('translations/messages.en.yaml', <<<'YAML'
sylius:
    ui:
        shop:
            checkout: Checkout
            my_account: My account
        admin:
            dashboard: Dashboard
    email:
        order_confirmation:
            subject: Your order has been placed
YAML);

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        $shop = $this->issuesWithMessage($report, 'prefixe "sylius.ui.shop" dans messages.en.yaml');
        $admin = $this->issuesWithMessage($report, 'prefixe "sylius.ui.admin" dans messages.en.yaml');
        $email = $this->issuesWithMessage($report, 'prefixe "sylius.email" dans messages.en.yaml');

        self::assertCount(1, $shop);
        self::assertCount(1, $admin);
        self::assertCount(1, $email);
        self::assertStringContainsString('"sylius.shop"', $shop[0]->getDetail());
        self::assertStringContainsString('"sylius.notification"', $email[0]->getSuggestion());
        self::assertSame(realpath($file), realpath((string) $admin[0]->getFile()));
        self::assertSame(Category::DEPRECATION, $admin[0]->getCategory());
    }

    /**
     * Verifie la detection de cles ecrites a plat (notation pointee) dans un fichier .yml.
     */
    #[Test]
    public function testDetectsFlatDottedKeysInYmlFile(): void
    {
        $this->writeFile('translations/validators.fr.yml', <<<'YAML'
sylius.form.channel.hostname: Nom d'hote
sylius.form.product.slug: Slug
YAML);

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        $channel = $this->issuesWithMessage($report, 'prefixe "sylius.form.channel"');
        $product = $this->issuesWithMessage($report, 'prefixe "sylius.form.product"');

        self::assertCount(1, $channel);
        self::assertCount(1, $product);
        self::assertStringContainsString('"sylius.admin.channel"', $channel[0]->getDetail());
        self::assertStringContainsString('"sylius.admin.product"', $product[0]->getDetail());
    }

    /**
     * Verifie qu'un fichier YAML de traduction invalide est ignore.
     */
    #[Test]
    public function testIgnoresInvalidYamlTranslationFile(): void
    {
        $this->writeFile('translations/messages.fr.yaml', "sylius:\n    ui: [admin\n  - {\n");

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie qu'un fichier YAML scalaire est ignore.
     */
    #[Test]
    public function testIgnoresScalarYamlTranslationFile(): void
    {
        $this->writeFile('translations/messages.fr.yaml', "sylius.ui.admin\n");

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection des prefixes impactes dans un fichier XLIFF (.xlf).
     */
    #[Test]
    public function testDetectsChangedPrefixesInXlfFile(): void
    {
        $file = $this->writeFile('translations/messages.de.xlf', <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="de" datatype="plaintext" original="file.ext">
        <body>
            <trans-unit id="sylius.ui.shop.checkout">
                <source>sylius.ui.shop.checkout</source>
                <target>Kasse</target>
            </trans-unit>
            <trans-unit id="sylius.ui.shop.cart">
                <source>sylius.ui.shop.cart</source>
                <target>Warenkorb</target>
            </trans-unit>
        </body>
    </file>
</xliff>
XML);

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'prefixe "sylius.ui.shop" dans messages.de.xlf');

        self::assertCount(1, $issues);
        self::assertSame(realpath($file), realpath((string) $issues[0]->getFile()));
        self::assertStringContainsString('"sylius.shop"', $issues[0]->getSuggestion());
        self::assertCount(2, $report->getIssues());
    }

    /**
     * Verifie que l'extension .xliff est egalement analysee.
     */
    #[Test]
    public function testDetectsChangedPrefixesInXliffFile(): void
    {
        $this->writeFile('translations/messages.it.xliff', <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="it" datatype="plaintext" original="file.ext">
        <body>
            <trans-unit id="sylius.email.password_reset.subject">
                <source>sylius.email.password_reset.subject</source>
                <target>Reimposta la password</target>
            </trans-unit>
        </body>
    </file>
</xliff>
XML);

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'prefixe "sylius.email" dans messages.it.xliff'));
    }

    /**
     * Verifie qu'un fichier XLIFF sans cle Sylius impactee ne produit aucune issue.
     */
    #[Test]
    public function testIgnoresXlfFileWithoutChangedPrefixes(): void
    {
        $this->writeFile('translations/messages.fr.xlf', <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="fr" datatype="plaintext" original="file.ext">
        <body>
            <trans-unit id="app.loyalty.points">
                <source>app.loyalty.points</source>
                <target>Points de fidelite</target>
            </trans-unit>
        </body>
    </file>
</xliff>
XML);

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie la detection d'une cle impactee utilisee via le filtre trans dans un template Twig.
     */
    #[Test]
    public function testDetectsChangedPrefixInTwigTemplate(): void
    {
        $template = $this->writeFile('templates/bundles/SyliusAdminBundle/Dashboard/index.html.twig', <<<'TWIG'
{% extends '@SyliusAdmin/layout.html.twig' %}

{% block title %}{{ 'sylius.ui.admin.dashboard'|trans }}{% endblock %}

{% block content %}
    <h1>{{ 'sylius.ui.admin.dashboard'|trans }}</h1>
    <p>{{ 'sylius.ui.admin.welcome'|trans }}</p>
{% endblock %}
TWIG);

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Cle de traduction "sylius.ui.admin" utilisee dans le template');

        self::assertCount(1, $issues);
        self::assertStringContainsString('index.html.twig', $issues[0]->getMessage());
        self::assertSame(realpath($template), realpath((string) $issues[0]->getFile()));
        self::assertStringContainsString('"sylius.admin"', $issues[0]->getSuggestion());
        self::assertSame(Severity::SUGGESTION, $issues[0]->getSeverity());
    }

    /**
     * Verifie qu'un template utilisant plusieurs prefixes impactes produit une issue par prefixe.
     */
    #[Test]
    public function testReportsEachPrefixUsedInSameTemplate(): void
    {
        $this->writeFile('templates/email/order_confirmation.html.twig', <<<'TWIG'
{% block subject %}{{ 'sylius.email.order_confirmation.subject'|trans }}{% endblock %}
{% block body %}
    <a href="{{ url('sylius_shop_homepage') }}">{{ 'sylius.ui.shop.continue_shopping'|trans }}</a>
{% endblock %}
TWIG);

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        self::assertCount(1, $this->issuesWithMessage($report, 'Cle de traduction "sylius.email" utilisee'));
        self::assertCount(1, $this->issuesWithMessage($report, 'Cle de traduction "sylius.ui.shop" utilisee'));
    }

    /**
     * Verifie que les templates texte (.txt.twig) sont egalement analyses.
     */
    #[Test]
    public function testDetectsChangedPrefixInTextTwigTemplate(): void
    {
        $this->writeFile('templates/email/shipment_confirmation.txt.twig', "{{ 'sylius.email.shipment_confirmation.subject'|trans }}\n");

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        $issues = $this->issuesWithMessage($report, 'Cle de traduction "sylius.email" utilisee dans le template');

        self::assertCount(1, $issues);
        self::assertStringContainsString('shipment_confirmation.txt.twig', $issues[0]->getMessage());
    }

    /**
     * Verifie qu'un template sans cle Sylius impactee ne produit aucune issue.
     */
    #[Test]
    public function testIgnoresTemplateWithoutChangedPrefixes(): void
    {
        $this->writeFile('templates/shop/loyalty.html.twig', "<h2>{{ 'app.loyalty.title'|trans }}</h2>\n");

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        self::assertSame([], $report->getIssues());
    }

    /**
     * Verifie que la synthese cumule les groupes des traductions et des templates, a 30 minutes par groupe.
     */
    #[Test]
    public function testSummaryAggregatesTranslationAndTemplateGroups(): void
    {
        $this->writeFile('translations/messages.fr.yaml', "sylius:\n    ui:\n        admin:\n            dashboard: Tableau de bord\n");
        $this->writeFile('templates/admin/dashboard.html.twig', "{{ 'sylius.ui.admin.dashboard'|trans }}\n{{ 'sylius.form.product.name'|trans }}\n");

        $report = $this->createReport();
        (new TranslationKeyAnalyzer())->analyze($report);

        $summary = $this->issuesWithMessage($report, '3 groupe(s) de cles de traduction Sylius detecte(s)');

        self::assertCount(1, $summary);
        self::assertSame(90, $summary[0]->getEstimatedMinutes());
        self::assertNull($summary[0]->getFile());
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
