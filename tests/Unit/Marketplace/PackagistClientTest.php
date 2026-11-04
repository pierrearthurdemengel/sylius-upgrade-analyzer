<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Marketplace\PackagistClient;
use PierreArthur\SyliusUpgradeAnalyzer\Marketplace\PluginCompatibilityStatus;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires du client Packagist.
 * Le client HTTP est systématiquement mocké : aucun appel réseau réel n'est effectué.
 */
final class PackagistClientTest extends TestCase
{
    /**
     * Construit un client Packagist dont le client HTTP renvoie la réponse donnée.
     *
     * @param array<mixed> $body
     */
    private function createClient(array $body, int $statusCode = 200): PackagistClient
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->willReturn($body);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new PackagistClient($httpClient);
    }

    /**
     * Construit une réponse Packagist minimale avec les versions fournies.
     *
     * @param array<mixed> $versions
     *
     * @return array<string, mixed>
     */
    private function packageWithVersions(array $versions): array
    {
        return ['package' => ['name' => 'bitbag/wishlist-plugin', 'versions' => $versions]];
    }

    /**
     * Vérifie que le client interroge l'URL Packagist du paquet avec un délai d'attente.
     */
    #[Test]
    public function testRequestsPackageMetadataWithTimeout(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($this->packageWithVersions([]));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('GET', 'https://packagist.org/packages/sylius/invoicing-plugin.json', ['timeout' => 5])
            ->willReturn($response);

        $result = (new PackagistClient($httpClient))->checkCompatibility('sylius/invoicing-plugin', '2.0');

        self::assertSame('sylius/invoicing-plugin', $result->packageName);
    }

    /**
     * Vérifie qu'une version stable exigeant sylius/sylius ^2.0 est déclarée compatible.
     */
    #[Test]
    public function testReturnsCompatibleWhenStableVersionRequiresTargetMajor(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'v2.0.0' => ['require' => ['php' => '^8.2', 'sylius/sylius' => '^2.0']],
            'v1.13.0' => ['require' => ['php' => '^8.0', 'sylius/sylius' => '^1.12']],
        ]));

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::COMPATIBLE, $result->status);
        self::assertSame('v2.0.0', $result->compatibleVersion);
        self::assertSame('', $result->currentVersion);
        self::assertSame('La version v2.0.0 est compatible avec Sylius 2.0 selon Packagist', $result->notes);
    }

    /**
     * Vérifie que chaque paquet Sylius principal est reconnu comme dépendance Sylius.
     */
    #[Test]
    #[DataProvider('syliusDependencyProvider')]
    public function testRecognizesSyliusCorePackagesAsDependency(string $dependency): void
    {
        $client = $this->createClient($this->packageWithVersions([
            '2.1.0' => ['require' => [$dependency => '^2.0']],
        ]));

        $result = $client->checkCompatibility('setono/sylius-analytics-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::COMPATIBLE, $result->status);
        self::assertSame('2.1.0', $result->compatibleVersion);
    }

    /**
     * Paquets considérés comme dépendances Sylius principales.
     *
     * @return iterable<string, array{string}>
     */
    public static function syliusDependencyProvider(): iterable
    {
        yield 'sylius/sylius' => ['sylius/sylius'];
        yield 'sylius/core-bundle' => ['sylius/core-bundle'];
        yield 'sylius/resource-bundle' => ['sylius/resource-bundle'];
        yield 'sylius/grid-bundle' => ['sylius/grid-bundle'];
    }

    /**
     * Vérifie qu'une dépendance non Sylius de même majeure ne rend pas le plugin compatible.
     */
    #[Test]
    public function testIgnoresNonSyliusDependencies(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            '1.4.0' => ['require' => [
                'doctrine/orm' => '^2.14',
                'sylius/sylius' => '^1.12',
            ]],
        ]));

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::INCOMPATIBLE, $result->status);
        self::assertNull($result->compatibleVersion);
        self::assertSame('Aucune version compatible avec Sylius 2.0 trouvee sur Packagist', $result->notes);
    }

    /**
     * Vérifie qu'une contrainte avec alternatives (|| ou |) est compatible si l'une d'elles cible la majeure.
     */
    #[Test]
    #[DataProvider('alternativeConstraintProvider')]
    public function testMatchesTargetMajorInConstraintAlternatives(string $constraint): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'v1.6.0' => ['require' => ['sylius/sylius' => $constraint]],
        ]));

        $result = $client->checkCompatibility('sylius/invoicing-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::COMPATIBLE, $result->status);
        self::assertSame('v1.6.0', $result->compatibleVersion);
    }

    /**
     * Contraintes Composer à alternatives multiples incluant la majeure 2.
     *
     * @return iterable<string, array{string}>
     */
    public static function alternativeConstraintProvider(): iterable
    {
        yield 'double barre' => ['^1.12 || ^2.0'];
        yield 'barre simple' => ['~1.13.0|~2.0.0'];
        yield 'plage combinee' => ['>=2.0 <2.2'];
    }

    /**
     * Vérifie qu'une contrainte n'est jugée compatible que si l'ensemble des versions
     * qu'elle autorise intersecte l'intervalle [2.0.0, 3.0.0[ de la majeure cible.
     */
    #[Test]
    #[DataProvider('constraintIntersectionProvider')]
    public function testEvaluatesConstraintAgainstTargetMajorInterval(string $constraint, PluginCompatibilityStatus $expected): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'v1.6.0' => ['require' => ['sylius/sylius' => $constraint]],
        ]));

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame($expected, $result->status);
    }

    /**
     * Contraintes Composer et statut attendu pour une cible Sylius 2.0.
     *
     * @return iterable<string, array{string, PluginCompatibilityStatus}>
     */
    public static function constraintIntersectionProvider(): iterable
    {
        $compatible = PluginCompatibilityStatus::COMPATIBLE;
        $incompatible = PluginCompatibilityStatus::INCOMPATIBLE;

        yield '^2.0' => ['^2.0', $compatible];
        yield '~2.1' => ['~2.1', $compatible];
        yield '2.*' => ['2.*', $compatible];
        yield 'joker seul' => ['*', $compatible];
        yield '^1.12|^2.0' => ['^1.12|^2.0', $compatible];
        yield '>=2.0 <2.1' => ['>=2.0 <2.1', $compatible];
        yield '>=1.0 <3.0 englobe la majeure' => ['>=1.0 <3.0', $compatible];
        yield '>=1.12 ouvert' => ['>=1.12', $compatible];
        yield '!=2.0 seul autorise 2.1' => ['!=2.0', $compatible];
        yield 'operateur separe par un espace' => ['>= 2.0', $compatible];
        yield 'conjonction par virgule' => ['>=1.12,<2.1', $compatible];
        yield 'plage avec tiret' => ['1.0 - 2.0', $compatible];
        yield 'prefixe v' => ['v2.0.0', $compatible];
        yield 'drapeau de stabilite' => ['^2.0@beta', $compatible];
        yield '^1.12 <2.0' => ['^1.12 <2.0', $incompatible];
        yield '<2.0' => ['<2.0', $incompatible];
        yield '^1.12 !=2.0' => ['^1.12 !=2.0', $incompatible];
        yield 'point exclu' => ['2.0.0 !=2.0.0', $incompatible];
        yield '1.12.*' => ['1.12.*', $incompatible];
        yield '^3.0' => ['^3.0', $incompatible];
        yield '>=3.0' => ['>=3.0', $incompatible];
        yield 'plage avec tiret sous la majeure' => ['1.0 - 1.14', $incompatible];
        yield 'conjonction vide' => ['^2.0 <2.0', $incompatible];
        yield 'borne exclusive' => ['>3.0 <=3.0', $incompatible];
        yield 'contrainte illisible' => ['self.version', $incompatible];
    }

    /**
     * Vérifie que la version majeure cible est extraite malgré un préfixe (v, ^, ~).
     */
    #[Test]
    public function testExtractsMajorFromPrefixedTargetVersion(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            '2.0.0' => ['require' => ['sylius/sylius' => '~2.1.0']],
        ]));

        $result = $client->checkCompatibility('setono/sylius-feed-plugin', 'v2.1.3');

        self::assertSame(PluginCompatibilityStatus::COMPATIBLE, $result->status);
        self::assertSame('La version 2.0.0 est compatible avec Sylius v2.1.3 selon Packagist', $result->notes);
    }

    /**
     * Vérifie qu'un plugin dont seule une branche de développement cible Sylius 2
     * est partiellement compatible.
     */
    #[Test]
    public function testReturnsPartiallyCompatibleWhenOnlyDevBranchMatches(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'dev-master' => ['require' => ['sylius/sylius' => '^2.0']],
            'v1.9.0' => ['require' => ['sylius/sylius' => '^1.11']],
        ]));

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::PARTIALLY_COMPATIBLE, $result->status);
        self::assertSame('dev-master', $result->compatibleVersion);
        self::assertSame(
            'Seule la branche de developpement dev-master est compatible avec Sylius 2.0',
            $result->notes,
        );
    }

    /**
     * Vérifie qu'une version stable compatible prime sur une branche de développement compatible.
     */
    #[Test]
    public function testPrefersStableVersionOverDevBranch(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'dev-main' => ['require' => ['sylius/sylius' => '^2.0']],
            'v2.0.1' => ['require' => ['sylius/core-bundle' => '^2.0']],
        ]));

        $result = $client->checkCompatibility('sylius/invoicing-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::COMPATIBLE, $result->status);
        self::assertSame('v2.0.1', $result->compatibleVersion);
    }

    /**
     * Vérifie que les branches de développement non compatibles mènent au statut INCOMPATIBLE.
     */
    #[Test]
    public function testReturnsIncompatibleWhenDevBranchesTargetPreviousMajor(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'dev-1.x' => ['require' => ['sylius/sylius' => '^1.12', 'php' => '^8.1']],
            'v1.10.0' => ['require' => ['sylius/sylius' => '^1.12']],
        ]));

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::INCOMPATIBLE, $result->status);
    }

    /**
     * Vérifie que les entrées de version malformées sont ignorées sans interrompre l'analyse.
     */
    #[Test]
    public function testSkipsMalformedVersionEntries(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'v0.1.0' => 'not-an-array',
            0 => ['require' => ['sylius/sylius' => '^2.0']],
            'v0.2.0' => ['require' => 'sylius/sylius'],
            'v0.3.0' => ['require' => ['sylius/sylius' => ['^2.0']]],
            'dev-legacy' => 'not-an-array',
            'dev-broken' => ['require' => 'sylius/sylius'],
            'dev-feature' => ['require' => ['sylius/sylius' => null, 0 => '^2.0']],
            'v1.0.0' => [],
        ]));

        $result = $client->checkCompatibility('setono/sylius-analytics-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::INCOMPATIBLE, $result->status);
        self::assertNull($result->compatibleVersion);
    }

    /**
     * Vérifie qu'une contrainte vide ou réduite à des séparateurs ne rend pas le plugin compatible.
     */
    #[Test]
    public function testIgnoresEmptyConstraintSegments(): void
    {
        $client = $this->createClient($this->packageWithVersions([
            'v1.0.0' => ['require' => ['sylius/sylius' => ' || ']],
        ]));

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::INCOMPATIBLE, $result->status);
    }

    /**
     * Vérifie qu'un paquet abandonné avec remplacement suggéré est signalé comme tel.
     */
    #[Test]
    public function testReturnsAbandonedWithSuggestedReplacement(): void
    {
        $client = $this->createClient(['package' => [
            'name' => 'sylius/sylius-rbac-plugin',
            'abandoned' => 'sylius/admin-rbac-plugin',
            'versions' => ['v2.0.0' => ['require' => ['sylius/sylius' => '^2.0']]],
        ]]);

        $result = $client->checkCompatibility('sylius/sylius-rbac-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::ABANDONED, $result->status);
        self::assertNull($result->compatibleVersion);
        self::assertSame('Le paquet est abandonne. Remplacement suggere : sylius/admin-rbac-plugin', $result->notes);
    }

    /**
     * Vérifie qu'un paquet abandonné sans remplacement est signalé avec la note adéquate.
     */
    #[Test]
    public function testReturnsAbandonedWithoutReplacement(): void
    {
        $client = $this->createClient(['package' => [
            'name' => 'bitbag/wishlist-plugin',
            'abandoned' => true,
        ]]);

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::ABANDONED, $result->status);
        self::assertSame('Le paquet est abandonne et n\'a pas de remplacement suggere', $result->notes);
    }

    /**
     * Vérifie qu'une réponse sans clé package produit un statut UNKNOWN.
     */
    #[Test]
    public function testReturnsUnknownForUnexpectedResponseFormat(): void
    {
        $client = $this->createClient(['status' => 'error', 'message' => 'Package not found']);

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::UNKNOWN, $result->status);
        self::assertSame('Format de reponse inattendu de Packagist', $result->notes);
    }

    /**
     * Vérifie qu'une liste de versions non exploitable produit un statut UNKNOWN.
     */
    #[Test]
    public function testReturnsUnknownWhenVersionsAreNotAnArray(): void
    {
        $client = $this->createClient(['package' => [
            'name' => 'bitbag/wishlist-plugin',
            'versions' => 'unavailable',
        ]]);

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::UNKNOWN, $result->status);
        self::assertSame('Aucune information de version disponible sur Packagist', $result->notes);
    }

    /**
     * Vérifie qu'un paquet sans clé versions est considéré comme incompatible.
     */
    #[Test]
    public function testReturnsIncompatibleWhenVersionsAreMissing(): void
    {
        $client = $this->createClient(['package' => ['name' => 'bitbag/wishlist-plugin']]);

        $result = $client->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::INCOMPATIBLE, $result->status);
    }

    /**
     * Vérifie qu'un code HTTP différent de 200 produit un statut UNKNOWN sans lire le corps.
     */
    #[Test]
    public function testReturnsUnknownForNonOkStatusCode(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->expects(self::never())->method('toArray');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $result = (new PackagistClient($httpClient))->checkCompatibility('acme/unknown-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::UNKNOWN, $result->status);
        self::assertSame('Packagist a retourne le code HTTP 404 pour acme/unknown-plugin', $result->notes);
    }

    /**
     * Vérifie qu'une erreur de transport (timeout, DNS) est absorbée en statut UNKNOWN.
     */
    #[Test]
    public function testReturnsUnknownOnTransportError(): void
    {
        $exception = new class ('Idle timeout reached for "https://packagist.org/packages/bitbag/wishlist-plugin.json".') extends \RuntimeException implements TransportExceptionInterface {
        };

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willThrowException($exception);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $result = (new PackagistClient($httpClient))->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::UNKNOWN, $result->status);
        self::assertSame('bitbag/wishlist-plugin', $result->packageName);
        self::assertStringStartsWith('Erreur lors de la requete vers Packagist : Idle timeout reached', (string) $result->notes);
    }

    /**
     * Vérifie qu'un corps JSON invalide est absorbé en statut UNKNOWN.
     */
    #[Test]
    public function testReturnsUnknownOnInvalidJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willThrowException(new \JsonException('Syntax error'));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $result = (new PackagistClient($httpClient))->checkCompatibility('bitbag/wishlist-plugin', '2.0');

        self::assertSame(PluginCompatibilityStatus::UNKNOWN, $result->status);
        self::assertSame('Erreur lors de la requete vers Packagist : Syntax error', $result->notes);
    }
}
