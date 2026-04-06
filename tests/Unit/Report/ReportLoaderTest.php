<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ReportLoader;

/**
 * Tests unitaires pour l'utilitaire de chargement de rapports depuis JSON.
 * Couvre les formats groupé (par catégorie) et plat, ainsi que les erreurs.
 */
final class ReportLoaderTest extends TestCase
{
    private ReportLoader $loader;

    /** @var list<string> Fichiers temporaires à nettoyer */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->loader = new ReportLoader();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Crée un fichier JSON temporaire avec le contenu donné.
     */
    /**
     * @param array<string, mixed> $data
     */
    private function writeTempJson(array $data): string
    {
        $path = sys_get_temp_dir() . '/report-loader-test-' . uniqid() . '.json';
        file_put_contents($path, json_encode($data, \JSON_PRETTY_PRINT));
        $this->tempFiles[] = $path;

        return $path;
    }

    /** Vérifie le chargement d'un rapport avec issues groupées par catégorie. */
    #[Test]
    public function testLoadFromFileWithGroupedIssues(): void
    {
        $path = $this->writeTempJson([
            'meta' => [
                'version' => '1.12.0',
                'target_version' => '2.0',
                'analyzed_at' => '2026-04-05T10:00:00+00:00',
                'project_name' => 'mon-projet',
            ],
            'issues' => [
                'frontend' => [
                    [
                        'severity' => 'warning',
                        'category' => 'frontend',
                        'analyzer' => 'Semantic UI',
                        'message' => 'Classes CSS détectées',
                        'detail' => 'Détail',
                        'suggestion' => 'Migrer',
                        'estimated_minutes' => 60,
                    ],
                ],
                'deprecation' => [
                    [
                        'severity' => 'breaking',
                        'category' => 'deprecation',
                        'analyzer' => 'WinzouStateMachine',
                        'message' => 'Winzou détecté',
                        'detail' => 'Détail',
                        'suggestion' => 'Migrer vers Symfony Workflow',
                        'estimated_minutes' => 120,
                    ],
                ],
            ],
        ]);

        $report = $this->loader->loadFromFile($path);

        self::assertSame('mon-projet', $report->getProjectName());
        self::assertCount(2, $report->getIssues());
        self::assertSame('1.12.0', $report->getDetectedSyliusVersion());
    }

    /** Vérifie le chargement d'un rapport avec issues au format plat (tableau indexé). */
    #[Test]
    public function testLoadFromFileWithFlatIssues(): void
    {
        $path = $this->writeTempJson([
            'meta' => [
                'version' => '1.11.0',
                'target_version' => '2.0',
            ],
            'issues' => [
                [
                    'severity' => 'warning',
                    'category' => 'grid',
                    'analyzer' => 'Grid',
                    'message' => 'Grille YAML détectée',
                    'detail' => 'Détail',
                    'suggestion' => 'Migrer',
                    'estimated_minutes' => 30,
                ],
                [
                    'severity' => 'suggestion',
                    'category' => 'frontend',
                    'analyzer' => 'jQuery',
                    'message' => 'jQuery détecté',
                    'detail' => 'Détail',
                    'suggestion' => 'Remplacer',
                    'estimated_minutes' => 45,
                ],
            ],
        ]);

        $report = $this->loader->loadFromFile($path);

        self::assertCount(2, $report->getIssues());
    }

    /** Vérifie que les issues avec sévérité/catégorie invalide sont ignorées. */
    #[Test]
    public function testSkipsIssuesWithInvalidSeverityOrCategory(): void
    {
        $path = $this->writeTempJson([
            'meta' => ['version' => '1.12.0'],
            'issues' => [
                [
                    'severity' => 'nonexistent',
                    'category' => 'frontend',
                    'analyzer' => 'Test',
                    'message' => 'Ignoré',
                    'detail' => '',
                    'suggestion' => '',
                    'estimated_minutes' => 10,
                ],
                [
                    'severity' => 'warning',
                    'category' => 'invalid_cat',
                    'analyzer' => 'Test',
                    'message' => 'Aussi ignoré',
                    'detail' => '',
                    'suggestion' => '',
                    'estimated_minutes' => 10,
                ],
            ],
        ]);

        $report = $this->loader->loadFromFile($path);

        self::assertCount(0, $report->getIssues());
    }

    /** Vérifie qu'un fichier introuvable déclenche une RuntimeException. */
    #[Test]
    public function testLoadFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/introuvable/');
        $this->loader->loadFromFile('/chemin/inexistant/rapport.json');
    }

    /** Vérifie qu'un fichier JSON invalide déclenche une RuntimeException. */
    #[Test]
    public function testLoadFromFileThrowsOnInvalidJson(): void
    {
        $path = sys_get_temp_dir() . '/report-loader-invalid-' . uniqid() . '.json';
        file_put_contents($path, 'not valid json {{{');
        $this->tempFiles[] = $path;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalide/');
        $this->loader->loadFromFile($path);
    }

    /** Vérifie que rebuildFromArray fonctionne avec des données minimales. */
    #[Test]
    public function testRebuildFromArrayWithMinimalData(): void
    {
        $report = $this->loader->rebuildFromArray([
            'meta' => [],
            'issues' => [],
        ]);

        self::assertCount(0, $report->getIssues());
    }

    /** Vérifie que le project_name est restauré correctement. */
    #[Test]
    public function testRebuildFromArrayRestoresProjectName(): void
    {
        $report = $this->loader->rebuildFromArray([
            'meta' => ['project_name' => 'acme/store'],
            'issues' => [],
        ]);

        self::assertSame('acme/store', $report->getProjectName());
    }

    /** Vérifie que les champs optionnels file/line/doc_url sont correctement restaurés. */
    #[Test]
    public function testRebuildFromArrayRestoresOptionalFields(): void
    {
        $report = $this->loader->rebuildFromArray([
            'meta' => ['version' => '1.12.0'],
            'issues' => [
                [
                    'severity' => 'breaking',
                    'category' => 'deprecation',
                    'analyzer' => 'TestAnalyzer',
                    'message' => 'Test',
                    'detail' => 'Detail',
                    'suggestion' => 'Fix',
                    'file' => 'src/Entity/Product.php',
                    'line' => 42,
                    'code_snippet' => 'use Winzou;',
                    'doc_url' => 'https://docs.sylius.com',
                    'estimated_minutes' => 60,
                ],
            ],
        ]);

        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame('src/Entity/Product.php', $issues[0]->getFile());
        self::assertSame(42, $issues[0]->getLine());
        self::assertSame('https://docs.sylius.com', $issues[0]->getDocUrl());
    }
}
