<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Command\MultiAnalyzeCommand;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use PierreArthur\SyliusUpgradeAnalyzer\Report\JsonReporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires pour la commande d'analyse multi-projets.
 * Utilise les fixtures de test existantes comme « projets Sylius ».
 */
final class MultiAnalyzeCommandTest extends TestCase
{
    /** @var list<string> Fichiers temporaires à nettoyer */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
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

    /**
     * Retourne le chemin d'une fixture de test existante.
     */
    private function fixturePath(string $name): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/' . $name;
    }

    /** Vérifie que la commande échoue sans clé API. */
    #[Test]
    public function testFailsWithoutApiKey(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $jsonReporter = new JsonReporter();

        $command = new MultiAnalyzeCommand([], $apiClient, $jsonReporter);
        $tester = new CommandTester($command);

        $prev = $_ENV['SYLIUS_UPGRADE_API_KEY'] ?? null;
        unset($_ENV['SYLIUS_UPGRADE_API_KEY']);
        putenv('SYLIUS_UPGRADE_API_KEY');

        try {
            $tester->execute([
                'project-paths' => [$this->fixturePath('project-trivial')],
            ]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('API', $tester->getDisplay());
        } finally {
            if ($prev !== null) {
                $_ENV['SYLIUS_UPGRADE_API_KEY'] = $prev;
                putenv('SYLIUS_UPGRADE_API_KEY=' . $prev);
            }
        }
    }

    /** Vérifie que la commande ignore les répertoires introuvables. */
    #[Test]
    public function testSkipsInvalidDirectories(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $jsonReporter = new JsonReporter();

        $command = new MultiAnalyzeCommand([], $apiClient, $jsonReporter);
        $tester = new CommandTester($command);

        $tester->execute([
            'project-paths' => ['/chemin/inexistant/projet1', '/chemin/inexistant/projet2'],
            '--api-key' => 'sua_agy_test',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Aucun projet', $tester->getDisplay());
    }

    /** Vérifie une analyse multi-projets réussie avec upload et téléchargement PDF. */
    #[Test]
    public function testSuccessfulMultiAnalyzeWithPdfDownload(): void
    {
        $pdfUrl = 'https://example.com/multi-report.pdf';
        $pdfContent = '%PDF-1.4 consolidated';
        $outputPath = sys_get_temp_dir() . '/multi-test-' . uniqid() . '.pdf';
        $this->tempFiles[] = $outputPath;

        /* POST /v1/reports/multi retourne l'URL du PDF */
        $uploadResponse = $this->createMockResponse(200, ['pdf_url' => $pdfUrl]);
        /* GET <pdf_url> retourne le contenu PDF */
        $downloadResponse = $this->createMockResponse(200, null, $pdfContent);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function (string $method) use ($uploadResponse, $downloadResponse): ResponseInterface {
                return $method === 'POST' ? $uploadResponse : $downloadResponse;
            });

        $apiClient = new ApiClient($httpClient);
        $jsonReporter = new JsonReporter();

        /* Utilise les fixtures existantes comme projets à analyser */
        $command = new MultiAnalyzeCommand([], $apiClient, $jsonReporter);
        $tester = new CommandTester($command);

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial')],
            '--api-key' => 'sua_agy_test',
            '--output' => $outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($outputPath);
        self::assertSame($pdfContent, file_get_contents($outputPath));
        self::assertStringContainsString('téléchargé', $tester->getDisplay());
    }

    /** Vérifie que la commande gère une erreur d'authentification. */
    #[Test]
    public function testHandlesAuthenticationError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(401));

        $apiClient = new ApiClient($httpClient);
        $jsonReporter = new JsonReporter();

        $command = new MultiAnalyzeCommand([], $apiClient, $jsonReporter);
        $tester = new CommandTester($command);

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial')],
            '--api-key' => 'expired-key',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('authentification', $tester->getDisplay());
    }

    /** Vérifie que la commande gère l'absence de pdf_url dans la réponse. */
    #[Test]
    public function testHandlesMissingPdfUrl(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, ['status' => 'ok']));

        $apiClient = new ApiClient($httpClient);
        $jsonReporter = new JsonReporter();

        $command = new MultiAnalyzeCommand([], $apiClient, $jsonReporter);
        $tester = new CommandTester($command);

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial')],
            '--api-key' => 'sua_agy_test',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('PDF', $tester->getDisplay());
    }

    /** Vérifie que l'analyse de plusieurs projets envoie tous les rapports. */
    #[Test]
    public function testAnalyzesMultipleProjects(): void
    {
        $pdfContent = '%PDF-1.4';
        $outputPath = sys_get_temp_dir() . '/multi-test-multi-' . uniqid() . '.pdf';
        $this->tempFiles[] = $outputPath;

        $uploadResponse = $this->createMockResponse(200, ['pdf_url' => 'https://example.com/multi.pdf']);
        $downloadResponse = $this->createMockResponse(200, null, $pdfContent);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function (string $method) use ($uploadResponse, $downloadResponse): ResponseInterface {
                return $method === 'POST' ? $uploadResponse : $downloadResponse;
            });

        $apiClient = new ApiClient($httpClient);
        $jsonReporter = new JsonReporter();

        $command = new MultiAnalyzeCommand([], $apiClient, $jsonReporter);
        $tester = new CommandTester($command);

        $tester->execute([
            'project-paths' => [
                $this->fixturePath('project-trivial'),
                $this->fixturePath('project-moderate'),
            ],
            '--api-key' => 'sua_agy_test',
            '--output' => $outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('2 rapport(s)', $display);
    }
}
