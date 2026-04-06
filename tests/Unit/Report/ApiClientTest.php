<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\LicenseExpiredException;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\ServiceUnavailableException;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires pour le client HTTP centralisé vers le service distant.
 * Chaque méthode publique est testée avec des scénarios de succès et d'erreur.
 */
final class ApiClientTest extends TestCase
{
    private const API_KEY = 'sua_agy_test_key_123';

    /**
     * Crée un mock de ResponseInterface avec le code HTTP et les données spécifiés.
     *
     * @param array<string, mixed>|null $responseData
     */
    private function createMockResponse(int $statusCode, ?array $responseData = null, ?string $content = null): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);

        if ($responseData !== null) {
            $response->method('toArray')->willReturn($responseData);
        }

        if ($content !== null) {
            $response->method('getContent')->willReturn($content);
        }

        return $response;
    }

    /** Vérifie que uploadReport envoie un POST vers /v1/reports. */
    #[Test]
    public function testUploadReportSuccess(): void
    {
        $expectedResponse = ['pdf_url' => 'https://example.com/report.pdf', 'report_id' => 'abc123'];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                self::stringContains('/v1/reports'),
                self::callback(function (array $options): bool {
                    return ($options['headers']['X-Api-Key'] ?? '') === self::API_KEY;
                }),
            )
            ->willReturn($this->createMockResponse(200, $expectedResponse));

        $client = new ApiClient($httpClient);
        $result = $client->uploadReport(['meta' => ['version' => '1.12.0']], self::API_KEY);

        self::assertSame('abc123', $result['report_id']);
        self::assertSame('https://example.com/report.pdf', $result['pdf_url']);
    }

    /** Vérifie que uploadMultiReport envoie un POST vers /v1/reports/multi. */
    #[Test]
    public function testUploadMultiReportSuccess(): void
    {
        $expectedResponse = ['pdf_url' => 'https://example.com/multi.pdf'];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', self::stringContains('/v1/reports/multi'), self::anything())
            ->willReturn($this->createMockResponse(200, $expectedResponse));

        $client = new ApiClient($httpClient);
        $result = $client->uploadMultiReport(['reports' => []], self::API_KEY);

        self::assertSame('https://example.com/multi.pdf', $result['pdf_url']);
    }

    /** Vérifie que compareReports envoie les IDs avant/après. */
    #[Test]
    public function testCompareReportsSuccess(): void
    {
        $expectedResponse = ['resolved' => [], 'new' => [], 'progress_percent' => 50.0];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', self::stringContains('/v1/reports/compare'), self::callback(function (array $options): bool {
                $body = json_decode($options['body'] ?? '', true);

                return ($body['before_id'] ?? '') === 'id-before' && ($body['after_id'] ?? '') === 'id-after';
            }))
            ->willReturn($this->createMockResponse(200, $expectedResponse));

        $client = new ApiClient($httpClient);
        $result = $client->compareReports('id-before', 'id-after', self::API_KEY);

        self::assertSame(50.0, $result['progress_percent']);
    }

    /** Vérifie que fetchHistory envoie un GET avec les paramètres de pagination. */
    #[Test]
    public function testFetchHistorySuccess(): void
    {
        $expectedResponse = ['reports' => [], 'total' => 0];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('GET', self::stringContains('/v1/reports/history?limit=10&offset=5'), self::anything())
            ->willReturn($this->createMockResponse(200, $expectedResponse));

        $client = new ApiClient($httpClient);
        $result = $client->fetchHistory(self::API_KEY, 10, 5);

        self::assertSame(0, $result['total']);
    }

    /** Vérifie que getWebhook envoie un GET vers /v1/settings/webhook. */
    #[Test]
    public function testGetWebhookSuccess(): void
    {
        $expectedResponse = ['url' => 'https://hooks.example.com', 'events' => ['report.created']];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('GET', self::stringContains('/v1/settings/webhook'), self::anything())
            ->willReturn($this->createMockResponse(200, $expectedResponse));

        $client = new ApiClient($httpClient);
        $result = $client->getWebhook(self::API_KEY);

        self::assertSame('https://hooks.example.com', $result['url']);
    }

    /** Vérifie que setWebhook envoie un PUT vers /v1/settings/webhook. */
    #[Test]
    public function testSetWebhookSuccess(): void
    {
        $config = ['url' => 'https://hooks.example.com', 'secret' => 's3cret'];
        $expectedResponse = ['status' => 'ok'];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('PUT', self::stringContains('/v1/settings/webhook'), self::anything())
            ->willReturn($this->createMockResponse(200, $expectedResponse));

        $client = new ApiClient($httpClient);
        $result = $client->setWebhook($config, self::API_KEY);

        self::assertSame('ok', $result['status']);
    }

    /** Vérifie que deleteWebhook envoie un DELETE vers /v1/settings/webhook. */
    #[Test]
    public function testDeleteWebhookSuccess(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('DELETE', self::stringContains('/v1/settings/webhook'), self::anything())
            ->willReturn($this->createMockResponse(200, ['status' => 'deleted']));

        $client = new ApiClient($httpClient);
        $result = $client->deleteWebhook(self::API_KEY);

        self::assertSame('deleted', $result['status']);
    }

    /** Vérifie qu'une réponse 401 déclenche LicenseExpiredException. */
    #[Test]
    public function testThrowsLicenseExpiredOn401(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(401));

        $client = new ApiClient($httpClient);

        $this->expectException(LicenseExpiredException::class);
        $client->uploadReport(['data' => true], 'invalid-key');
    }

    /** Vérifie qu'une réponse 403 déclenche LicenseExpiredException. */
    #[Test]
    public function testThrowsLicenseExpiredOn403(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(403));

        $client = new ApiClient($httpClient);

        $this->expectException(LicenseExpiredException::class);
        $client->fetchHistory('expired-key');
    }

    /** Vérifie qu'une réponse 500 déclenche ServiceUnavailableException. */
    #[Test]
    public function testThrowsServiceUnavailableOn500(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(500));

        $client = new ApiClient($httpClient);

        $this->expectException(ServiceUnavailableException::class);
        $client->uploadReport(['data' => true], self::API_KEY);
    }

    /** Vérifie qu'une erreur réseau déclenche ServiceUnavailableException. */
    #[Test]
    public function testThrowsServiceUnavailableOnNetworkError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $client = new ApiClient($httpClient);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Connection refused/');
        $client->uploadReport(['data' => true], self::API_KEY);
    }

    /** Vérifie que downloadFile télécharge et écrit le fichier sur le disque. */
    #[Test]
    public function testDownloadFileSuccess(): void
    {
        $pdfContent = '%PDF-1.4 fake content';
        $outputPath = sys_get_temp_dir() . '/api-client-test-' . uniqid() . '.pdf';

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, null, $pdfContent));

        $client = new ApiClient($httpClient);

        try {
            $client->downloadFile('https://example.com/file.pdf', $outputPath);

            self::assertFileExists($outputPath);
            self::assertSame($pdfContent, file_get_contents($outputPath));
        } finally {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    /** Vérifie que downloadFile échoue proprement quand le serveur retourne 404. */
    #[Test]
    public function testDownloadFileFailsOnHttpError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(404));

        $client = new ApiClient($httpClient);

        $this->expectException(ServiceUnavailableException::class);
        $client->downloadFile('https://example.com/missing.pdf', '/tmp/out.pdf');
    }
}
