<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Estimation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskLevel;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskScorer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le calculateur de score de risque.
 */
final class RiskScorerTest extends TestCase
{
    private RiskScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new RiskScorer();
    }

    /**
     * Crée un rapport de migration vide.
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
        int $estimatedMinutes = 30,
    ): MigrationIssue {
        return new MigrationIssue(
            severity: $severity,
            category: $category,
            analyzer: $analyzer,
            message: $message,
            detail: 'Détail',
            suggestion: 'Suggestion',
            file: 'src/Test.php',
            line: 1,
            estimatedMinutes: $estimatedMinutes,
        );
    }

    /** Vérifie qu'un rapport vide produit un risque FAIBLE. */
    #[Test]
    public function testScoreEmptyReportReturnsFaible(): void
    {
        $report = $this->createEmptyReport();
        $score = $this->scorer->score($report);

        self::assertSame(RiskLevel::FAIBLE, $score->level);
    }

    /** Vérifie que des problèmes bloquants augmentent le niveau de risque. */
    #[Test]
    public function testScoreWithBreakingIssuesReturnsHigher(): void
    {
        $report = $this->createEmptyReport();

        /* Ajout de problèmes liés aux machines à états (poids élevé) */
        for ($i = 0; $i < 3; $i++) {
            $report->addIssue($this->createIssue(
                severity: Severity::BREAKING,
                analyzer: 'StateMachineAnalyzer',
                message: 'Problème de state machine Winzou',
                estimatedMinutes: 60,
            ));
        }

        $score = $this->scorer->score($report);

        /* 3 problèmes de machines à états = poids 75, donc au moins MODERE */
        self::assertNotSame(RiskLevel::FAIBLE, $score->level);
    }

    /** Vérifie que de nombreux problèmes BREAKING dans des domaines critiques produisent un risque CRITIQUE. */
    #[Test]
    public function testScoreWithManyBreakingReturnsCritique(): void
    {
        $report = $this->createEmptyReport();

        /* Ajout de 4 problèmes de machines à états (poids max 100) */
        for ($i = 0; $i < 4; $i++) {
            $report->addIssue($this->createIssue(
                severity: Severity::BREAKING,
                analyzer: 'StateMachineAnalyzer',
                message: 'Migration state machine Winzou vers Workflow',
                estimatedMinutes: 120,
            ));
        }

        /* Ajout de 4 problèmes de passerelles de paiement (poids max 80) */
        for ($i = 0; $i < 4; $i++) {
            $report->addIssue($this->createIssue(
                severity: Severity::BREAKING,
                analyzer: 'GatewayAnalyzer',
                message: 'Passerelle de paiement Payum incompatible',
                estimatedMinutes: 90,
            ));
        }

        /* Ajout de 7 problèmes de plugins (poids max 90) */
        for ($i = 0; $i < 7; $i++) {
            $report->addIssue($this->createIssue(
                severity: Severity::BREAKING,
                category: Category::PLUGIN,
                analyzer: 'PluginAnalyzer',
                message: sprintf('Plugin incompatible %d', $i),
                estimatedMinutes: 60,
            ));
        }

        $score = $this->scorer->score($report);

        /* Poids total : machines à états (100) + paiement (80) + plugins (90) = 270 >= 200 */
        self::assertSame(RiskLevel::CRITIQUE, $score->level);
    }

    /** Vérifie que les recommandations ne sont jamais vides quand des facteurs sont détectés. */
    #[Test]
    public function testScoreIncludesRecommendations(): void
    {
        $report = $this->createEmptyReport();

        /* Ajout d'un problème de machine à états pour déclencher au moins un facteur */
        $report->addIssue($this->createIssue(
            severity: Severity::BREAKING,
            analyzer: 'StateMachineAnalyzer',
            message: 'Problème lié aux state machine',
            estimatedMinutes: 30,
        ));

        $score = $this->scorer->score($report);

        self::assertNotEmpty($score->recommendations, 'Les recommandations ne doivent pas être vides');
    }

    /** Vérifie que les facteurs de risque sont présents dans le score. */
    #[Test]
    public function testScoreIncludesFactors(): void
    {
        $report = $this->createEmptyReport();

        /* Ajout d'un problème de passerelle de paiement */
        $report->addIssue($this->createIssue(
            severity: Severity::BREAKING,
            analyzer: 'GatewayAnalyzer',
            message: 'Problème de gateway de paiement',
            estimatedMinutes: 60,
        ));

        $score = $this->scorer->score($report);

        self::assertNotEmpty($score->factors, 'Les facteurs de risque ne doivent pas être vides');
        self::assertSame('Passerelles de paiement', $score->factors[0]->name);
    }

    /** Vérifie qu'une complexité modérée est correctement détectée. */
    #[Test]
    public function testScoreModerateComplexity(): void
    {
        $report = $this->createEmptyReport();

        /* Ajout de 3 problèmes de machines à états pour atteindre un poids de 75 (>= 50 = MODERE) */
        for ($i = 0; $i < 3; $i++) {
            $report->addIssue($this->createIssue(
                severity: Severity::BREAKING,
                analyzer: 'StateMachineAnalyzer',
                message: 'Problème Winzou workflow',
                estimatedMinutes: 30,
            ));
        }

        $score = $this->scorer->score($report);

        /* Poids de 75 : 50 <= 75 < 120, donc MODERE */
        self::assertSame(RiskLevel::MODERE, $score->level);
    }
}
