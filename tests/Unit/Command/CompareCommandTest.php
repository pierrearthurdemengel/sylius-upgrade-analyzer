<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Command\CompareCommand;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ReportLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests unitaires pour la commande de comparaison de rapports.
 * Couvre la comparaison de fichiers JSON locaux.
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

    /** Vérifie la comparaison locale de deux rapports identiques. */
    #[Test]
    public function testCompareIdenticalReports(): void
    {
        $issue = ['severity' => 'warning', 'category' => 'frontend', 'analyzer' => 'Semantic UI', 'message' => 'Classes CSS détectées'];
        $beforePath = $this->createTempReport([$issue]);
        $afterPath = $this->createTempReport([$issue]);

        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader);
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

        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader);
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

        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader);
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
        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /** Vérifie la gestion d'un fichier introuvable en mode local. */
    #[Test]
    public function testCompareLocalFailsOnMissingFile(): void
    {
        $validPath = $this->createTempReport([]);

        $reportLoader = new ReportLoader();

        $command = new CompareCommand($reportLoader);
        $tester = new CommandTester($command);

        $tester->execute([
            'before' => '/chemin/inexistant.json',
            'after' => $validPath,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('introuvable', $tester->getDisplay());
    }
}
