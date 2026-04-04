<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\BaselineStorage;

/**
 * Tests unitaires pour le stockage de baseline (snapshot de rapport).
 */
final class BaselineStorageTest extends TestCase
{
    private BaselineStorage $storage;

    /** Fichiers temporaires à nettoyer après chaque test */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->storage = new BaselineStorage();
    }

    protected function tearDown(): void
    {
        /* Nettoyage des fichiers temporaires créés pendant les tests */
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Crée un chemin temporaire unique et l'enregistre pour nettoyage.
     */
    private function createTempPath(): string
    {
        $path = sys_get_temp_dir() . '/sylius_baseline_test_' . uniqid() . '.json';
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Crée un rapport de migration avec des problèmes pour les tests.
     */
    private function createReportWithIssues(int $breakingCount = 1, int $warningCount = 0): MigrationReport
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12.0',
            targetVersion: '2.0.0',
            projectPath: sys_get_temp_dir(),
        );

        for ($i = 0; $i < $breakingCount; $i++) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: 'DeprecationAnalyzer',
                message: sprintf('Problème bloquant %d', $i + 1),
                detail: 'Détail technique',
                suggestion: 'Suggestion de correction',
                file: sprintf('src/File%d.php', $i),
                line: 10 + $i,
                estimatedMinutes: 30,
            ));
        }

        for ($i = 0; $i < $warningCount; $i++) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::TWIG,
                analyzer: 'TwigAnalyzer',
                message: sprintf('Avertissement %d', $i + 1),
                detail: 'Détail',
                suggestion: 'Suggestion',
                file: sprintf('templates/file%d.html.twig', $i),
                line: 5 + $i,
                estimatedMinutes: 15,
            ));
        }

        return $report;
    }

    /** Vérifie que la sauvegarde crée bien un fichier sur le disque. */
    #[Test]
    public function testSaveCreatesFile(): void
    {
        $path = $this->createTempPath();
        $report = $this->createReportWithIssues();

        $this->storage->save($report, $path);

        self::assertFileExists($path);
    }

    /** Vérifie que les données chargées correspondent à ce qui a été sauvegardé. */
    #[Test]
    public function testLoadReturnsData(): void
    {
        $path = $this->createTempPath();
        $report = $this->createReportWithIssues(breakingCount: 2);

        $this->storage->save($report, $path);
        $data = $this->storage->load($path);

        self::assertIsArray($data);
        self::assertArrayHasKey('issues', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertCount(2, $data['issues']);
    }

    /** Vérifie que le diff détecte les problèmes résolus (présents dans la baseline, absents du rapport actuel). */
    #[Test]
    public function testDiffDetectsResolvedIssues(): void
    {
        /* Baseline avec 3 problèmes bloquants */
        $path = $this->createTempPath();
        $baselineReport = $this->createReportWithIssues(breakingCount: 3);
        $this->storage->save($baselineReport, $path);

        /* Rapport actuel avec seulement 1 problème bloquant (le même premier) */
        $currentReport = $this->createReportWithIssues(breakingCount: 1);

        $diff = $this->storage->diff($currentReport, $path);

        self::assertArrayHasKey('resolved', $diff);
        /* Les problèmes 2 et 3 de la baseline ne sont plus dans le rapport actuel */
        self::assertCount(2, $diff['resolved']);
    }

    /** Vérifie que le diff détecte les nouveaux problèmes (absents de la baseline, présents dans le rapport actuel). */
    #[Test]
    public function testDiffDetectsNewIssues(): void
    {
        /* Baseline avec 1 problème bloquant */
        $path = $this->createTempPath();
        $baselineReport = $this->createReportWithIssues(breakingCount: 1);
        $this->storage->save($baselineReport, $path);

        /* Rapport actuel avec 1 bloquant (le même) + 2 avertissements (nouveaux) */
        $currentReport = $this->createReportWithIssues(breakingCount: 1, warningCount: 2);

        $diff = $this->storage->diff($currentReport, $path);

        self::assertArrayHasKey('new', $diff);
        self::assertCount(2, $diff['new']);
    }

    /** Vérifie que le pourcentage de progression est correctement calculé. */
    #[Test]
    public function testDiffCalculatesProgressPercent(): void
    {
        /* Baseline avec 4 problèmes bloquants */
        $path = $this->createTempPath();
        $baselineReport = $this->createReportWithIssues(breakingCount: 4);
        $this->storage->save($baselineReport, $path);

        /* Rapport actuel avec seulement 2 problèmes bloquants (les deux premiers) */
        $currentReport = $this->createReportWithIssues(breakingCount: 2);

        $diff = $this->storage->diff($currentReport, $path);

        self::assertArrayHasKey('progress_percent', $diff);
        /* 2 résolus sur 4 = 50% */
        self::assertSame(50.0, $diff['progress_percent']);
    }

    /** Vérifie que le chargement d'un fichier inexistant lève une exception. */
    #[Test]
    public function testLoadNonexistentFileReturnsEmptyArray(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->storage->load('/chemin/inexistant/baseline.json');
    }

    /** Vérifie la cohérence aller-retour sauvegarde/chargement. */
    #[Test]
    public function testSaveAndLoadRoundtrip(): void
    {
        $path = $this->createTempPath();
        $report = $this->createReportWithIssues(breakingCount: 2, warningCount: 3);

        $this->storage->save($report, $path);
        $data = $this->storage->load($path);

        /* Vérification de la structure complète */
        self::assertArrayHasKey('saved_at', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('issues', $data);

        /* Vérification des métadonnées */
        self::assertSame('1.12.0', $data['meta']['version']);
        self::assertSame('2.0.0', $data['meta']['target_version']);

        /* Vérification du résumé */
        self::assertSame(5, $data['summary']['issues_count']);
        self::assertSame(2, $data['summary']['breaking_count']);
        self::assertSame(3, $data['summary']['warning_count']);

        /* Vérification des problèmes individuels */
        self::assertCount(5, $data['issues']);

        $firstIssue = $data['issues'][0];
        self::assertSame('breaking', $firstIssue['severity']);
        self::assertSame('deprecation', $firstIssue['category']);
        self::assertSame('DeprecationAnalyzer', $firstIssue['analyzer']);
    }
}
