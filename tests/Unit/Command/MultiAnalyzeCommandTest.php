<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\SwiftMailerAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig\TwigTemplateOverrideAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Command\MultiAnalyzeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
     * Retourne le chemin d'une fixture de test existante.
     */
    private function fixturePath(string $name): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/' . $name;
    }

    private function createTester(): CommandTester
    {
        return new CommandTester(new MultiAnalyzeCommand([
            new TwigTemplateOverrideAnalyzer(),
            new SwiftMailerAnalyzer(),
        ]));
    }

    /** Vérifie l'affichage du tableau consolidé pour plusieurs projets. */
    #[Test]
    public function testAnalyzesMultipleProjects(): void
    {
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial'), $this->fixturePath('project-moderate')],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Total', $display);
        self::assertStringContainsString('Complexité', $display);
    }

    /** Vérifie que deux projets au même nom Composer restent distinguables. */
    #[Test]
    public function testDisambiguatesProjectsSharingTheSameName(): void
    {
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial'), $this->fixturePath('project-moderate')],
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('(project-trivial)', $display);
        self::assertStringContainsString('(project-moderate)', $display);
    }

    /** Vérifie que les répertoires introuvables sont ignorés sans bloquer l'analyse. */
    #[Test]
    public function testSkipsInvalidDirectories(): void
    {
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => ['/chemin/inexistant', $this->fixturePath('project-trivial')],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('introuvable', $tester->getDisplay());
    }

    /** Vérifie l'échec quand aucun projet n'a pu être analysé. */
    #[Test]
    public function testFailsWhenNoProjectCanBeAnalyzed(): void
    {
        $tester = $this->createTester();

        $tester->execute(['project-paths' => ['/chemin/inexistant']]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Aucun projet', $tester->getDisplay());
    }

    /** Vérifie l'ajout du budget avec l'option --tjm. */
    #[Test]
    public function testShowsBudgetWithDailyRate(): void
    {
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-moderate')],
            '--tjm' => '600',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Budget', $tester->getDisplay());
    }

    /** Vérifie le refus d'un TJM invalide avant toute analyse. */
    #[Test]
    public function testRejectsInvalidDailyRate(): void
    {
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial')],
            '--tjm' => 'six-cents',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--tjm', $tester->getDisplay());
    }

    /** Vérifie le refus d'un format non supporté. */
    #[Test]
    public function testRejectsUnsupportedFormat(): void
    {
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial')],
            '--format' => 'sarif',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Format non supporté', $tester->getDisplay());
    }

    /** Vérifie l'écriture du rapport JSON consolidé dans un fichier. */
    #[Test]
    public function testWritesJsonReportToFile(): void
    {
        $outputFile = sys_get_temp_dir() . '/multi-analyze-' . uniqid() . '.json';
        $this->tempFiles[] = $outputFile;
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial'), $this->fixturePath('project-moderate')],
            '--format' => 'json',
            '--output' => $outputFile,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $data = json_decode((string) file_get_contents($outputFile), true);
        self::assertIsArray($data);
        self::assertSame(2, $data['meta']['projects_count']);
    }

    /** Vérifie la génération du PDF consolidé. */
    #[Test]
    public function testWritesPdfReportToFile(): void
    {
        $outputFile = sys_get_temp_dir() . '/multi-analyze-' . uniqid() . '.pdf';
        $this->tempFiles[] = $outputFile;
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-trivial'), $this->fixturePath('project-moderate')],
            '--format' => 'pdf',
            '--output' => $outputFile,
            '--tjm' => '550',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($outputFile));
    }

    /** Verifie que --no-promo retire la mention d'accompagnement du rapport consolide. */
    #[Test]
    public function testNoPromoOptionHidesServiceOffer(): void
    {
        $tester = $this->createTester();

        $tester->execute([
            'project-paths' => [$this->fixturePath('project-moderate')],
            '--no-promo' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('creation-site-pro.fr', $tester->getDisplay());
    }
}
