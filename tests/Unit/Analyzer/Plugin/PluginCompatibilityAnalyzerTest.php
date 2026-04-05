<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Plugin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Plugin\PluginCompatibilityAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Marketplace\AddonsMarketplaceClient;
use PierreArthur\SyliusUpgradeAnalyzer\Marketplace\PackagistClient;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires pour l'analyseur de compatibilité des plugins Sylius.
 * Vérifie la détection des plugins, la vérification de compatibilité via les clients HTTP
 * et la création des problèmes correspondants.
 */
final class PluginCompatibilityAnalyzerTest extends TestCase
{
    /** Chemin vers le répertoire des fixtures */
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures';

    /**
     * Crée un rapport de migration pointant vers le projet de fixture spécifié.
     */
    private function createReportForFixture(string $projectName): MigrationReport
    {
        $path = realpath(self::FIXTURES_PATH . '/' . $projectName);
        self::assertNotFalse($path, sprintf('Le répertoire de fixture "%s" est introuvable.', $projectName));

        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: $path,
        );
    }

    /**
     * Crée un mock du client HTTP qui retourne une réponse prédéfinie.
     *
     * @param array<string, mixed> $responseBody Corps de la réponse JSON
     */
    private function createHttpClientMock(int $statusCode = 200, array $responseBody = []): HttpClientInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->willReturn($responseBody);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return $httpClient;
    }

    /**
     * Crée un analyseur avec des clients mockés qui retournent COMPATIBLE pour tous les plugins.
     */
    private function createAnalyzerWithCompatibleResponse(): PluginCompatibilityAnalyzer
    {
        /* Réponse Addons indiquant la compatibilité avec Sylius 2.x */
        $addonsResponse = [
            'items' => [
                [
                    'composerName' => 'sylius/invoicing-plugin',
                    'syliusVersions' => ['1.14', '2.0'],
                    'latestVersion' => '1.0.0',
                ],
            ],
        ];

        $httpClient = $this->createHttpClientMock(200, $addonsResponse);

        return new PluginCompatibilityAnalyzer(
            addonsMarketplaceClient: new AddonsMarketplaceClient($httpClient),
            packagistClient: new PackagistClient($httpClient),
        );
    }

    /**
     * Crée un analyseur avec des clients mockés qui retournent INCOMPATIBLE pour tous les plugins.
     */
    private function createAnalyzerWithIncompatibleResponse(): PluginCompatibilityAnalyzer
    {
        /* Réponse Addons indiquant l'incompatibilité (ne supporte que Sylius 1.x) */
        $addonsResponse = [
            'items' => [
                [
                    'composerName' => 'sylius/invoicing-plugin',
                    'syliusVersions' => ['1.12', '1.14'],
                    'latestVersion' => '0.22.0',
                ],
            ],
        ];

        $httpClient = $this->createHttpClientMock(200, $addonsResponse);

        return new PluginCompatibilityAnalyzer(
            addonsMarketplaceClient: new AddonsMarketplaceClient($httpClient),
            packagistClient: new PackagistClient($httpClient),
        );
    }

    /**
     * Crée un analyseur avec des clients mockés qui échouent (UNKNOWN).
     */
    private function createAnalyzerWithFailingResponse(): PluginCompatibilityAnalyzer
    {
        /* Réponse HTTP en erreur */
        $httpClient = $this->createHttpClientMock(500, []);

        return new PluginCompatibilityAnalyzer(
            addonsMarketplaceClient: new AddonsMarketplaceClient($httpClient),
            packagistClient: new PackagistClient($httpClient),
        );
    }

    /**
     * Vérifie que supports retourne true pour un projet avec des plugins Sylius.
     * Le projet modéré déclare sylius/invoicing-plugin et bitbag/sylius-cms-plugin.
     */
    #[Test]
    public function testSupportsReturnsTrueForProjectWithPlugins(): void
    {
        $analyzer = $this->createAnalyzerWithCompatibleResponse();
        $report = $this->createReportForFixture('project-moderate');

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Vérifie que supports retourne false pour un projet sans plugins Sylius.
     * Un projet minimal sans dépendances Sylius ne doit pas être supporté.
     */
    #[Test]
    public function testSupportsReturnsFalseForProjectWithNoPlugins(): void
    {
        $analyzer = $this->createAnalyzerWithCompatibleResponse();

        /* Création d'un répertoire temporaire avec un composer.json minimal */
        $tempDir = sys_get_temp_dir() . '/sylius-test-no-plugins-' . uniqid();
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/composer.json', json_encode([
            'name' => 'test/no-plugins',
            'require' => [
                'php' => '>=8.1',
                'symfony/framework-bundle' => '^6.0',
            ],
        ]));

        try {
            $report = new MigrationReport(
                startedAt: new \DateTimeImmutable(),
                detectedSyliusVersion: null,
                targetVersion: '2.2',
                projectPath: $tempDir,
            );

            self::assertFalse($analyzer->supports($report));
        } finally {
            /* Nettoyage */
            unlink($tempDir . '/composer.json');
            rmdir($tempDir);
        }
    }

    /**
     * Vérifie que l'analyseur détecte les plugins Sylius depuis composer.json.
     * Le projet modéré contient plusieurs plugins identifiés par "sylius" dans le nom.
     */
    #[Test]
    public function testDetectsPluginsFromComposerJson(): void
    {
        $analyzer = $this->createAnalyzerWithFailingResponse();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Le projet modéré déclare des plugins Sylius dans require */
        self::assertNotEmpty($report->getIssues(), 'Des plugins Sylius auraient dû être détectés.');
    }

    /**
     * Vérifie qu'un plugin compatible génère un problème de sévérité SUGGESTION.
     * Lorsqu'un plugin est détecté comme compatible, l'impact est minimal.
     */
    #[Test]
    public function testCompatiblePluginCreatesSuggestionIssue(): void
    {
        /* Réponse Addons indiquant la compatibilité pour tous les plugins */
        $addonsHttpClient = $this->createMock(HttpClientInterface::class);
        $addonsResponse = $this->createMock(ResponseInterface::class);
        $addonsResponse->method('getStatusCode')->willReturn(200);

        /* La réponse contient un plugin compatible avec Sylius 2.x */
        $addonsResponse->method('toArray')->willReturn([
            'items' => [
                [
                    'composerName' => 'sylius/invoicing-plugin',
                    'syliusVersions' => ['1.14', '2.0'],
                    'latestVersion' => '1.0.0',
                ],
                [
                    'composerName' => 'bitbag/sylius-cms-plugin',
                    'syliusVersions' => ['1.14', '2.0'],
                    'latestVersion' => '5.0.0',
                ],
                [
                    'composerName' => 'bitbag/sylius-wishlist-plugin',
                    'syliusVersions' => ['1.14', '2.0'],
                    'latestVersion' => '3.0.0',
                ],
                [
                    'composerName' => 'setono/sylius-analytics-plugin',
                    'syliusVersions' => ['1.14', '2.0'],
                    'latestVersion' => '2.0.0',
                ],
                [
                    'composerName' => 'some-vendor/unknown-sylius-plugin',
                    'syliusVersions' => ['1.14', '2.0'],
                    'latestVersion' => '2.0.0',
                ],
            ],
        ]);
        $addonsHttpClient->method('request')->willReturn($addonsResponse);

        $packagistHttpClient = $this->createMock(HttpClientInterface::class);

        $analyzer = new PluginCompatibilityAnalyzer(
            addonsMarketplaceClient: new AddonsMarketplaceClient($addonsHttpClient),
            packagistClient: new PackagistClient($packagistHttpClient),
        );

        $report = $this->createReportForFixture('project-moderate');
        $analyzer->analyze($report);

        /* Recherche des problèmes SUGGESTION pour les plugins compatibles */
        $suggestionIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => $issue->getSeverity() === Severity::SUGGESTION,
        );

        self::assertNotEmpty($suggestionIssues, 'Les plugins compatibles devraient générer des SUGGESTION.');
    }

    /**
     * Vérifie qu'un plugin incompatible génère un problème de sévérité BREAKING.
     * Lorsqu'un plugin est détecté comme incompatible, c'est un changement cassant.
     */
    #[Test]
    public function testIncompatiblePluginCreatesBreakingIssue(): void
    {
        $analyzer = $this->createAnalyzerWithIncompatibleResponse();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Recherche des problèmes BREAKING pour les plugins incompatibles */
        $breakingIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => $issue->getSeverity() === Severity::BREAKING,
        );

        self::assertNotEmpty($breakingIssues, 'Les plugins incompatibles devraient générer des BREAKING.');
    }

    /**
     * Vérifie qu'un plugin dont la compatibilité est inconnue génère un WARNING.
     * Lorsque les clients HTTP échouent, le statut est UNKNOWN.
     */
    #[Test]
    public function testUnknownPluginCreatesWarningIssue(): void
    {
        $analyzer = $this->createAnalyzerWithFailingResponse();
        $report = $this->createReportForFixture('project-moderate');

        $analyzer->analyze($report);

        /* Recherche des problèmes WARNING pour les plugins au statut inconnu */
        $warningIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => $issue->getSeverity() === Severity::WARNING,
        );

        self::assertNotEmpty($warningIssues, 'Les plugins au statut inconnu devraient générer des WARNING.');
    }

    /**
     * Vérifie le mode hors-ligne (noMarketplace=true).
     * Tous les plugins doivent recevoir le statut UNKNOWN sans requête HTTP.
     */
    #[Test]
    public function testNoMarketplaceReturnsAllUnknown(): void
    {
        /* Le client HTTP ne doit jamais être appelé en mode hors-ligne */
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $analyzer = new PluginCompatibilityAnalyzer(
            addonsMarketplaceClient: new AddonsMarketplaceClient($httpClient),
            packagistClient: new PackagistClient($httpClient),
            noMarketplace: true,
        );

        $report = $this->createReportForFixture('project-moderate');
        $analyzer->analyze($report);

        /* Tous les problèmes doivent être des WARNING (statut UNKNOWN) */
        foreach ($report->getIssues() as $issue) {
            self::assertSame(
                Severity::WARNING,
                $issue->getSeverity(),
                sprintf('Le problème "%s" devrait être un WARNING en mode hors-ligne.', $issue->getMessage()),
            );
        }

        /* Vérification qu'il y a bien des problèmes détectés */
        self::assertNotEmpty($report->getIssues(), 'Des plugins auraient dû être détectés même en mode hors-ligne.');
    }

    /**
     * Vérifie le mécanisme de repli vers Packagist quand Addons est indisponible.
     * Si Addons retourne UNKNOWN, l'analyseur doit interroger Packagist.
     */
    #[Test]
    public function testFallbackToPackagistWhenAddonsUnavailable(): void
    {
        /* Client Addons qui échoue systématiquement */
        $addonsHttpClient = $this->createMock(HttpClientInterface::class);
        $addonsResponse = $this->createMock(ResponseInterface::class);
        $addonsResponse->method('getStatusCode')->willReturn(500);
        $addonsResponse->method('toArray')->willReturn([]);
        $addonsHttpClient->method('request')->willReturn($addonsResponse);

        /* Client Packagist qui retourne une réponse valide avec compatibilité Sylius 2.x */
        $packagistHttpClient = $this->createMock(HttpClientInterface::class);
        $packagistResponse = $this->createMock(ResponseInterface::class);
        $packagistResponse->method('getStatusCode')->willReturn(200);
        $packagistResponse->method('toArray')->willReturn([
            'package' => [
                'abandoned' => false,
                'versions' => [
                    '1.0.0' => [
                        'require' => [
                            'sylius/sylius' => '^2.0',
                        ],
                    ],
                ],
            ],
        ]);
        $packagistHttpClient->method('request')->willReturn($packagistResponse);

        $analyzer = new PluginCompatibilityAnalyzer(
            addonsMarketplaceClient: new AddonsMarketplaceClient($addonsHttpClient),
            packagistClient: new PackagistClient($packagistHttpClient),
        );

        $report = $this->createReportForFixture('project-moderate');
        $analyzer->analyze($report);

        /* Le repli vers Packagist doit fonctionner et détecter la compatibilité */
        $suggestionIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => $issue->getSeverity() === Severity::SUGGESTION,
        );

        self::assertNotEmpty(
            $suggestionIssues,
            'Le repli vers Packagist aurait dû détecter des plugins compatibles.',
        );
    }

    /**
     * Vérifie que getName retourne le nom attendu de l'analyseur.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = $this->createAnalyzerWithCompatibleResponse();

        self::assertSame('Plugin Compatibility', $analyzer->getName());
    }
}
