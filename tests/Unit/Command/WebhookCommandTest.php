<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Command\WebhookCommand;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires pour la commande de gestion des webhooks.
 * Couvre les trois actions : get, set, delete et les erreurs.
 */
final class WebhookCommandTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $responseData
     */
    private function createMockResponse(int $statusCode, ?array $responseData = null): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);

        if ($responseData !== null) {
            $response->method('toArray')->willReturn($responseData);
        }

        return $response;
    }

    /** Vérifie que l'action get affiche la configuration webhook existante. */
    #[Test]
    public function testGetDisplaysWebhookConfig(): void
    {
        $webhookData = [
            'url' => 'https://hooks.acme.com/sylius',
            'secret' => 'whsec_abc123xyz',
            'events' => ['report.created', 'report.failed'],
            'status' => 'actif',
        ];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, $webhookData));

        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['action' => 'get', '--api-key' => 'sua_agy_test']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('hooks.acme.com', $display);
        self::assertStringContainsString('report.created', $display);
    }

    /** Vérifie que l'action get affiche un message quand aucun webhook n'est configuré. */
    #[Test]
    public function testGetDisplaysNoWebhookMessage(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, ['url' => null]));

        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['action' => 'get', '--api-key' => 'sua_agy_test']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Aucun webhook', $tester->getDisplay());
    }

    /** Vérifie que l'action set envoie la configuration au service. */
    #[Test]
    public function testSetConfiguresWebhook(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, ['status' => 'ok']));

        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute([
            'action' => 'set',
            '--api-key' => 'sua_agy_test',
            '--url' => 'https://hooks.acme.com/new',
            '--secret' => 'my-secret',
            '--events' => 'report.created,report.failed',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('configuré', $tester->getDisplay());
    }

    /** Vérifie que l'action set échoue sans --url. */
    #[Test]
    public function testSetFailsWithoutUrl(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute([
            'action' => 'set',
            '--api-key' => 'sua_agy_test',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--url', $tester->getDisplay());
    }

    /** Vérifie que l'action delete supprime le webhook. */
    #[Test]
    public function testDeleteRemovesWebhook(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, ['status' => 'deleted']));

        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['action' => 'delete', '--api-key' => 'sua_agy_test']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('supprimé', $tester->getDisplay());
    }

    /** Vérifie qu'une action inconnue retourne FAILURE. */
    #[Test]
    public function testUnknownActionFails(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['action' => 'purge', '--api-key' => 'sua_agy_test']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('inconnue', $tester->getDisplay());
    }

    /** Vérifie que la commande échoue sans clé API. */
    #[Test]
    public function testFailsWithoutApiKey(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $prev = $_ENV['SYLIUS_UPGRADE_API_KEY'] ?? null;
        unset($_ENV['SYLIUS_UPGRADE_API_KEY']);
        putenv('SYLIUS_UPGRADE_API_KEY');

        try {
            $tester->execute(['action' => 'get']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('API', $tester->getDisplay());
        } finally {
            if ($prev !== null) {
                $_ENV['SYLIUS_UPGRADE_API_KEY'] = $prev;
                putenv('SYLIUS_UPGRADE_API_KEY=' . $prev);
            }
        }
    }

    /** Vérifie la gestion d'une erreur d'authentification. */
    #[Test]
    public function testHandlesAuthenticationError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(401));

        $apiClient = new ApiClient($httpClient);
        $command = new WebhookCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['action' => 'get', '--api-key' => 'expired']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('authentification', $tester->getDisplay());
    }
}
