<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Command\CompareCommand;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ReportLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires pour la commande de comparaison de rapports.
 * Couvre les modes local (fichiers JSON) et API (IDs de rapports).
 */
final class CompareCommandTest extends TestCase
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
     * Crée un fichier JSON temporaire simulant un rapport de migration.
     *
     * @param array<array{severity: string, category: string, analyzer: string, message: string}> $issues
     */
    private function createTempReport(array $issues = [], string $version = '1.12.0'): string
    {
        $issueData = [];
        foreach ($issues as $issue) {
            $issueData[] = array_merge([
                'detail' => 'Détail',
                'suggestion' => 'Suggestion',
                'file' => null,
                'line' => null,
                'code_snippet' => null,
                'doc_url' => null,
                'estimated_minutes' => 60,
            ], $issue);
        }

        $data = [
            'meta' => [
                'version' => $version,
                'target_version' => '2.0',
                'analyzed_at' => '2026-04-05T10:00:00+00:00',
            ],
            'issues' => $issueData,
        ];

        $path = sys_get_temp_dir() . '/compare-test-' . uniqid() . '.json';
        file_put_contents($path, json_encode($data, \JSON_PRETTY_PRINT));
        $this->tempFiles[] = $path;

        return $path;
    }

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

    /** Vérifie la comparaison locale de deux rapports identiques. */
    #[Test]
    public function testCompareIdenticalReports(): void
    {
        $issue = ['severity' => 'warning', 'category' => 'frontend', 'analyzer' => 'Semantic UI', 'message' => 'Classes CSS détectées'];
        $beforePath = $this->createTempReport([$issue]);
        $afterPath = $this->createTempReport([$issue]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader, $apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['before' => $beforePath, 'after' => $afterPath]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('identiques', $tester->getDisplay());
    }

    /** Vérifie la détection d'issues résolues entre deux rapports. */
    #[Test]
    public function testDetectsResolvedIssues(): void
    {
        $issue1 = ['severity' => 'warning', 'category' => 'frontend', 'analyzer' => 'Semantic UI', 'message' => 'Classes CSS détectées'];
        $issue2 = ['severity' => 'breaking', 'category' => 'deprecation', 'analyzer' => 'Winzou', 'message' => 'State machine détectée'];

        $beforePath = $this->createTempReport([$issue1, $issue2]);
        $afterPath = $this->createTempReport([$issue2]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader, $apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['before' => $beforePath, 'after' => $afterPath]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('résolue', $display);
    }

    /** Vérifie la détection de nouvelles issues. */
    #[Test]
    public function testDetectsNewIssues(): void
    {
        $issue1 = ['severity' => 'warning', 'category' => 'frontend', 'analyzer' => 'Semantic UI', 'message' => 'Classes CSS'];
        $issue2 = ['severity' => 'warning', 'category' => 'grid', 'analyzer' => 'Grid', 'message' => 'Grille YAML'];

        $beforePath = $this->createTempReport([$issue1]);
        $afterPath = $this->createTempReport([$issue1, $issue2]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader, $apiClient);
        $tester = new CommandTester($command);

        $tester->execute(['before' => $beforePath, 'after' => $afterPath]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('ouvelle', $display);
    }

    /** Vérifie que la commande échoue sans arguments ni options. */
    #[Test]
    public function testFailsWithoutArguments(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader, $apiClient);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /** Vérifie la comparaison via API avec --before-id et --after-id. */
    #[Test]
    public function testCompareViaApiSuccess(): void
    {
        $apiResponse = [
            'resolved' => [
                ['category' => 'frontend', 'message' => 'CSS corrigé'],
            ],
            'new' => [],
            'progress_percent' => 75.0,
        ];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(200, $apiResponse));

        $apiClient = new ApiClient($httpClient);
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader, $apiClient);
        $tester = new CommandTester($command);

        $tester->execute([
            '--before-id' => 'rpt-001',
            '--after-id' => 'rpt-002',
            '--api-key' => 'sua_agy_test',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('75.0%', $display);
        self::assertStringContainsString('résolue', $display);
    }

    /** Vérifie que la comparaison API échoue sans clé API. */
    #[Test]
    public function testCompareViaApiFailsWithoutApiKey(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader, $apiClient);
        $tester = new CommandTester($command);

        $prev = $_ENV['SYLIUS_UPGRADE_API_KEY'] ?? null;
        unset($_ENV['SYLIUS_UPGRADE_API_KEY']);
        putenv('SYLIUS_UPGRADE_API_KEY');

        try {
            $tester->execute([
                '--before-id' => 'rpt-001',
                '--after-id' => 'rpt-002',
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

    /** Vérifie la gestion d'un fichier introuvable en mode local. */
    #[Test]
    public function testCompareLocalFailsOnMissingFile(): void
    {
        $validPath = $this->createTempReport([]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $apiClient = new ApiClient($httpClient);
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader, $apiClient);
        $tester = new CommandTester($command);

        $tester->execute([
            'before' => '/chemin/inexistant.json',
            'after' => $validPath,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('introuvable', $tester->getDisplay());
    }
}
