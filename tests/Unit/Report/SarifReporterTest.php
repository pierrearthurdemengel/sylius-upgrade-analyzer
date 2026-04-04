<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\SarifReporter;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests unitaires pour le générateur de rapport SARIF.
 */
final class SarifReporterTest extends TestCase
{
    private SarifReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new SarifReporter();
    }

    /**
     * Crée un rapport de migration vide pour les tests.
     */
    private function createEmptyReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12.0',
            targetVersion: '2.0.0',
            projectPath: sys_get_temp_dir(),
        );
    }

    /**
     * Crée un problème de migration avec les paramètres donnés.
     */
    private function createIssue(
        Severity $severity = Severity::BREAKING,
        Category $category = Category::DEPRECATION,
        string $analyzer = 'TestAnalyzer',
        string $message = 'Un problème de test',
        string $file = 'src/Entity/Product.php',
        int $line = 42,
        int $estimatedMinutes = 30,
    ): MigrationIssue {
        return new MigrationIssue(
            severity: $severity,
            category: $category,
            analyzer: $analyzer,
            message: $message,
            detail: 'Détail technique du problème',
            suggestion: 'Suggestion de correction',
            file: $file,
            line: $line,
            codeSnippet: '// extrait de code',
            docUrl: 'https://docs.sylius.com/migration',
            estimatedMinutes: $estimatedMinutes,
        );
    }

    /**
     * Décode la sortie JSON du rapport SARIF.
     *
     * @return array<string, mixed>
     */
    private function generateAndDecode(MigrationReport $report): array
    {
        $output = new BufferedOutput();
        $this->reporter->generate($report, $output);

        $json = $output->fetch();
        $data = json_decode($json, true);
        self::assertIsArray($data);

        return $data;
    }

    /** Vérifie que le format retourné est bien « sarif ». */
    #[Test]
    public function testGetFormatReturnsSarif(): void
    {
        self::assertSame('sarif', $this->reporter->getFormat());
    }

    /** Vérifie que la sortie générée est un JSON valide. */
    #[Test]
    public function testGenerateOutputsValidJson(): void
    {
        $report = $this->createEmptyReport();
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output);

        $json = $output->fetch();
        $decoded = json_decode($json, true);

        self::assertNotNull($decoded, 'La sortie doit être un JSON valide');
        self::assertIsArray($decoded);
    }

    /** Vérifie que le document contient la clé $schema SARIF. */
    #[Test]
    public function testGenerateIncludesSarifSchema(): void
    {
        $report = $this->createEmptyReport();
        $data = $this->generateAndDecode($report);

        self::assertArrayHasKey('$schema', $data);
        self::assertStringContainsString('sarif', $data['$schema']);
    }

    /** Vérifie que le document contient le driver d'outil avec son nom. */
    #[Test]
    public function testGenerateIncludesToolDriver(): void
    {
        $report = $this->createEmptyReport();
        $data = $this->generateAndDecode($report);

        self::assertArrayHasKey('runs', $data);
        self::assertNotEmpty($data['runs']);

        $run = $data['runs'][0];
        self::assertArrayHasKey('tool', $run);
        self::assertArrayHasKey('driver', $run['tool']);
        self::assertArrayHasKey('name', $run['tool']['driver']);
        self::assertSame('sylius-upgrade-analyzer', $run['tool']['driver']['name']);
    }

    /** Vérifie que les résultats sont présents quand des problèmes existent. */
    #[Test]
    public function testGenerateIncludesResults(): void
    {
        $report = $this->createEmptyReport();
        $report->addIssue($this->createIssue());

        $data = $this->generateAndDecode($report);

        $results = $data['runs'][0]['results'];
        self::assertIsArray($results);
        self::assertNotEmpty($results, 'Les résultats ne doivent pas être vides quand un problème existe');
        self::assertSame('Un problème de test', $results[0]['message']['text']);
    }

    /** Vérifie que BREAKING est converti en niveau « error » dans SARIF. */
    #[Test]
    public function testBreakingMapsToError(): void
    {
        $report = $this->createEmptyReport();
        $report->addIssue($this->createIssue(severity: Severity::BREAKING));

        $data = $this->generateAndDecode($report);

        $result = $data['runs'][0]['results'][0];
        self::assertSame('error', $result['level']);
    }
}
