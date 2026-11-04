<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf;

use PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimate;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimator;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\MigrationRoadmapGenerator;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskLevel;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskScore;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskScorer;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\PdfGenerationException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Complexity;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\MigrationServiceOffer;

/**
 * Construit le HTML des rapports PDF à partir des templates PHP de templates/pdf/.
 *
 * Les templates sont de simples fichiers PHP inclus dans le scope de cette classe :
 * ils accèdent aux variables passées par render() et aux helpers de formatage
 * ci-dessous via $this. Cela évite d'imposer Twig comme dépendance.
 */
final class HtmlReportBuilder
{
    private readonly string $templateDirectory;

    public function __construct(
        private readonly RiskScorer $riskScorer = new RiskScorer(),
        private readonly MigrationRoadmapGenerator $roadmapGenerator = new MigrationRoadmapGenerator(),
        private readonly CostEstimator $costEstimator = new CostEstimator(),
        ?string $templateDirectory = null,
    ) {
        $this->templateDirectory = $templateDirectory ?? dirname(__DIR__, 3) . '/templates/pdf';
    }

    /**
     * Construit le rapport d'un projet : synthèse, risques, feuille de route et détail des problèmes.
     *
     * @param int|null $dailyRate        TJM en euros ; ajoute une estimation budgétaire si fourni
     * @param bool     $withServiceOffer Ajoute l'encart d'accompagnement en fin de document
     */
    public function buildProjectReport(MigrationReport $report, ?int $dailyRate = null, bool $withServiceOffer = true): string
    {
        return $this->render('project-report.php', [
            'report' => $report,
            'risk' => $this->riskScorer->score($report),
            'cost' => $this->estimateCost($report, $dailyRate),
            'roadmap' => $this->roadmapGenerator->generate($report),
            'generatedAt' => new \DateTimeImmutable(),
            'offer' => $withServiceOffer ? new MigrationServiceOffer() : null,
        ]);
    }

    /**
     * Construit le rapport consolidé de plusieurs projets : tableau comparatif,
     * totaux, puis une synthèse par projet.
     *
     * @param list<MigrationReport> $reports
     * @param int|null              $dailyRate        TJM en euros ; ajoute une estimation budgétaire si fourni
     * @param bool                  $withServiceOffer Ajoute l'encart d'accompagnement en fin de document
     */
    public function buildMultiProjectReport(array $reports, ?int $dailyRate = null, bool $withServiceOffer = true): string
    {
        $projects = [];
        $totalHours = 0.0;
        $totalDays = 0.0;

        foreach ($reports as $report) {
            $cost = $this->estimateCost($report, $dailyRate);
            $projects[] = [
                'report' => $report,
                'risk' => $this->riskScorer->score($report),
                'cost' => $cost,
            ];
            $totalHours += $report->getTotalEstimatedHours();
            $totalDays += $cost->days ?? 0.0;
        }

        // Le total est la somme des budgets par projet : chaque projet est arrondi
        // à la demi-journée séparément, comme il serait chiffré dans un devis.
        $totalCost = $dailyRate !== null
            ? new CostEstimate($totalHours, $totalDays, $dailyRate, $totalDays * $dailyRate)
            : null;

        return $this->render('multi-project-report.php', [
            'projects' => $projects,
            'totalHours' => $totalHours,
            'totalCost' => $totalCost,
            'generatedAt' => new \DateTimeImmutable(),
            'offer' => $withServiceOffer ? new MigrationServiceOffer() : null,
        ]);
    }

    /**
     * Échappe une valeur pour une insertion sûre dans le HTML.
     */
    public function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Formate un volume d'heures (« 12,5 h »).
     */
    public function hours(float $hours): string
    {
        return str_replace('.', ',', sprintf('%.1f h', $hours));
    }

    /**
     * Formate un montant en euros avec séparateur de milliers (« 12 600 € »).
     */
    public function euros(float $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' €';
    }

    /**
     * Libellé français d'une catégorie d'issue.
     */
    public function categoryLabel(Category $category): string
    {
        return match ($category) {
            Category::TWIG => 'Templates Twig',
            Category::DEPRECATION => 'Dépréciations',
            Category::PLUGIN => 'Plugins',
            Category::GRID => 'Grilles',
            Category::RESOURCE => 'Ressources',
            Category::FRONTEND => 'Frontend',
            Category::API => 'API',
            Category::SECURITY => 'Sécurité',
        };
    }

    /**
     * Libellé français d'une sévérité.
     */
    public function severityLabel(Severity $severity): string
    {
        return match ($severity) {
            Severity::BREAKING => 'Bloquant',
            Severity::WARNING => 'Avertissement',
            Severity::SUGGESTION => 'Suggestion',
        };
    }

    /**
     * Libellé français d'un niveau de complexité.
     */
    public function complexityLabel(Complexity $complexity): string
    {
        return match ($complexity) {
            Complexity::TRIVIAL => 'Triviale',
            Complexity::MODERATE => 'Modérée',
            Complexity::COMPLEX => 'Complexe',
            Complexity::MAJOR => 'Majeure',
        };
    }

    /**
     * Libellé français d'un niveau de risque.
     */
    public function riskLabel(RiskLevel $level): string
    {
        return match ($level) {
            RiskLevel::FAIBLE => 'Faible',
            RiskLevel::MODERE => 'Modéré',
            RiskLevel::ELEVE => 'Élevé',
            RiskLevel::CRITIQUE => 'Critique',
        };
    }

    /**
     * Classe CSS de couleur associée à un niveau de complexité.
     */
    public function complexityTone(Complexity $complexity): string
    {
        return match ($complexity) {
            Complexity::TRIVIAL => 'tone-ok',
            Complexity::MODERATE => 'tone-info',
            Complexity::COMPLEX => 'tone-warn',
            Complexity::MAJOR => 'tone-bad',
        };
    }

    /**
     * Classe CSS de couleur associée à un niveau de risque.
     */
    public function riskTone(RiskScore $risk): string
    {
        return match ($risk->level) {
            RiskLevel::FAIBLE => 'tone-ok',
            RiskLevel::MODERE => 'tone-info',
            RiskLevel::ELEVE => 'tone-warn',
            RiskLevel::CRITIQUE => 'tone-bad',
        };
    }

    /**
     * Nom affiché d'un projet : nom explicite, sinon nom du répertoire analysé.
     */
    public function projectName(MigrationReport $report): string
    {
        return $report->getProjectName() ?? basename($report->getProjectPath());
    }

    /**
     * Inclut un template partiel et retourne son rendu.
     *
     * @param array<string, mixed> $variables
     */
    public function partial(string $template, array $variables): string
    {
        return $this->render($template, $variables);
    }

    private function estimateCost(MigrationReport $report, ?int $dailyRate): ?CostEstimate
    {
        if ($dailyRate === null) {
            return null;
        }

        return $this->costEstimator->estimate($report->getTotalEstimatedHours(), $dailyRate);
    }

    /**
     * Exécute un template avec les variables fournies et capture sa sortie.
     *
     * @param array<string, mixed> $variables
     *
     * @throws PdfGenerationException Si le template n'existe pas
     */
    private function render(string $template, array $variables): string
    {
        $path = $this->templateDirectory . '/' . $template;
        if (!is_file($path)) {
            throw new PdfGenerationException(sprintf('Template PDF introuvable : %s', $path));
        }

        extract($variables, \EXTR_SKIP);

        ob_start();
        try {
            include $path;
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }
}
