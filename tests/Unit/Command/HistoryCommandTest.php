<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Command\HistoryCommand;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires pour la commande d'historique des rapports.
 */
final class HistoryCommandTest extends TestCase
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

    /** Vérifie l'affichage d'un historique contenant des rapports. */
    #[Test]
    public function testDisplaysHistoryTable(): void
    {
        $historyData = [
            'reports' => [
                [
                    'created_at' => '2026-04-05',
                    'project_name' => 'acme/store',
                    'sylius_version' => '1.12.0',
                    'target_version' => '2.0',
                    'issues_count' => 15,
                    'total_hours' => 25.5,
                    'complexity' => 'complex',
                    'report_id' => 'rpt-001',
                ],
                [
                    'created_at' => '2026-04-04',
                    'project_name' => 'acme/boutique',
                    'sylius_version' => '1.11.0',
                    'target_version' => '2.0',
                    'issues_count' => 8,
                    'total_hours' => 10.0,
                    'complexity' => 'moderate',
                    'report_id' => 'rpt-002',
                ],
            ],
            'total' => 2,
        ];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, $historyData));

        $apiClient = new ApiClient($httpClient);
        $command = new HistoryCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['--api-key' => 'sua_agy_test']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('acme/store', $display);
        self::assertStringContainsString('acme/boutique', $display);
        self::assertStringContainsString('rpt-001', $display);
        self::assertStringContainsString('2 rapport(s)', $display);
    }

    /** Vérifie l'affichage quand l'historique est vide. */
    #[Test]
    public function testDisplaysEmptyHistory(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, ['reports' => [], 'total' => 0]));

        $apiClient = new ApiClient($httpClient);
        $command = new HistoryCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['--api-key' => 'sua_agy_test']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Aucun rapport', $tester->getDisplay());
    }

    /** Vérifie que la commande échoue sans clé API. */
    #[Test]
    public function testFailsWithoutApiKey(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $command = new HistoryCommand($apiClient);
        $tester = new CommandTester($command);

        /* Nettoyage des variables d'environnement */
        $prev = $_ENV['SYLIUS_UPGRADE_API_KEY'] ?? null;
        unset($_ENV['SYLIUS_UPGRADE_API_KEY']);
        putenv('SYLIUS_UPGRADE_API_KEY');

        try {
            $tester->execute([]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('API', $tester->getDisplay());
        } finally {
            if ($prev !== null) {
                $_ENV['SYLIUS_UPGRADE_API_KEY'] = $prev;
                putenv('SYLIUS_UPGRADE_API_KEY=' . $prev);
            }
        }
    }

    /** Vérifie que la commande gère une erreur d'authentification. */
    #[Test]
    public function testHandlesAuthenticationError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(401));

        $apiClient = new ApiClient($httpClient);
        $command = new HistoryCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['--api-key' => 'expired-key']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('authentification', $tester->getDisplay());
    }

    /** Vérifie que la commande gère une indisponibilité du service. */
    #[Test]
    public function testHandlesServiceUnavailable(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(500));

        $apiClient = new ApiClient($httpClient);
        $command = new HistoryCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['--api-key' => 'sua_agy_test']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('indisponible', $tester->getDisplay());
    }

    /** Vérifie que l'option --limit est transmise à l'API. */
    #[Test]
    public function testPassesLimitOption(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('GET', self::stringContains('limit=5'), self::anything())
            ->willReturn($this->createMockResponse(200, ['reports' => [], 'total' => 0]));

        $apiClient = new ApiClient($httpClient);
        $command = new HistoryCommand($apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['--api-key' => 'sua_agy_test', '--limit' => '5']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
