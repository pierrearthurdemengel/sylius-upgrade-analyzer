<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimator;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskScorer;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\PdfGenerationException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder;
use PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\PdfRenderer;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Produit le rapport consolidé de plusieurs projets Sylius.
 *
 * Pensé pour les agences qui maintiennent un parc de boutiques : un tableau
 * comparatif (complexité, risque, charge, budget) permet de prioriser les
 * migrations et de les chiffrer d'un seul coup.
 */
final class MultiProjectReporter
{
    /** Formats supportés par le rapport consolidé */
    public const FORMATS = ['console', 'json', 'markdown', 'pdf'];

    /** Fichier produit en PDF quand aucun --output n'est fourni */
    public const DEFAULT_PDF_FILE = 'sylius-migration-multi-report.pdf';

    public function __construct(
        private readonly JsonReporter $jsonReporter = new JsonReporter(),
        private readonly RiskScorer $riskScorer = new RiskScorer(),
        private readonly CostEstimator $costEstimator = new CostEstimator(),
        private readonly HtmlReportBuilder $htmlBuilder = new HtmlReportBuilder(),
        private readonly PdfRenderer $pdfRenderer = new PdfRenderer(),
    ) {
    }

    /**
     * Génère le rapport consolidé dans le format demandé.
     *
     * Sans fichier de sortie, les formats texte sont écrits sur la console ;
     * le PDF est alors écrit dans DEFAULT_PDF_FILE.
     *
     * La mention d'accompagnement n'est ajoutee qu'aux formats lus par des humains
     * (console, Markdown, PDF), jamais au JSON.
     *
     * @param list<MigrationReport> $reports
     * @param int|null              $dailyRate        TJM en euros ; ajoute une estimation budgétaire si fourni
     * @param bool                  $withServiceOffer Ajoute la mention d'accompagnement (false avec --no-promo)
     *
     * @throws \InvalidArgumentException Si le format n'est pas supporté
     * @throws PdfGenerationException    Si la génération PDF échoue
     */
    public function generate(
        array $reports,
        string $format,
        OutputInterface $output,
        ?string $outputFile = null,
        ?int $dailyRate = null,
        bool $withServiceOffer = true,
    ): void {
        match ($format) {
            'console' => $this->renderConsole($reports, $output, $dailyRate, $withServiceOffer),
            'json' => $this->writeText($this->buildJson($reports, $dailyRate), 'JSON', $output, $outputFile),
            'markdown' => $this->writeText($this->buildMarkdown($reports, $dailyRate, $withServiceOffer), 'Markdown', $output, $outputFile),
            'pdf' => $this->writePdf($reports, $output, $outputFile, $dailyRate, $withServiceOffer),
            default => throw new \InvalidArgumentException(sprintf(
                'Format non supporté pour le rapport consolidé : %s (formats disponibles : %s)',
                $format,
                implode(', ', self::FORMATS),
            )),
        };
    }

    /**
     * Construit la structure JSON consolidée : totaux, puis le rapport complet de chaque projet.
     *
     * @param list<MigrationReport> $reports
     */
    public function buildJson(array $reports, ?int $dailyRate = null): string
    {
        $projects = [];
        foreach ($this->buildRows($reports, $dailyRate) as $index => $row) {
            $projects[] = [
                'name' => $row['name'],
                'risk_level' => $row['risk'],
                'estimated_cost' => $row['cost'],
                'report' => $this->jsonReporter->buildReportData($reports[$index]),
            ];
        }

        $totals = $this->buildTotals($reports, $dailyRate);

        $data = [
            'meta' => [
                'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'projects_count' => count($reports),
                'daily_rate' => $dailyRate,
            ],
            'totals' => [
                'issues' => $totals['issues'],
                'breaking' => $totals['breaking'],
                'estimated_hours' => $totals['hours'],
                'estimated_days' => $totals['days'],
                'estimated_cost' => $totals['cost'],
            ],
            'projects' => $projects,
        ];

        return (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Construit le rapport consolidé en Markdown, adapté à un wiki ou un ticket.
     *
     * @param list<MigrationReport> $reports
     */
    public function buildMarkdown(array $reports, ?int $dailyRate = null, bool $withServiceOffer = true): string
    {
        $withCost = $dailyRate !== null;
        $totals = $this->buildTotals($reports, $dailyRate);

        $lines = [];
        $lines[] = '# Rapport de migration Sylius consolidé';
        $lines[] = '';
        $lines[] = sprintf(
            '> %d projet(s) · %d problème(s) dont %d bloquant(s) · %.1f h estimées%s',
            count($reports),
            $totals['issues'],
            $totals['breaking'],
            $totals['hours'],
            $withCost ? sprintf(' · %s (TJM %d €)', $this->formatEuros((float) $totals['cost']), $dailyRate) : '',
        );
        $lines[] = '';
        $lines[] = '| Projet | Version | Complexité | Risque | Bloquants | Problèmes | Heures' . ($withCost ? ' | Budget' : '') . ' |';
        $lines[] = '|--------|---------|------------|--------|-----------|-----------|-------' . ($withCost ? '|-------' : '') . '|';

        foreach ($this->buildRows($reports, $dailyRate) as $row) {
            $lines[] = sprintf(
                '| %s | %s → %s | %s | %s | %d | %d | %.1f%s |',
                str_replace('|', '\\|', $row['name']),
                $row['version'] ?? '?',
                $row['target'],
                $row['complexity'],
                $row['risk'],
                $row['breaking'],
                $row['issues'],
                $row['hours'],
                $withCost ? ' | ' . $this->formatEuros((float) $row['cost']) : '',
            );
        }

        $lines[] = sprintf(
            '| **Total** | | | | **%d** | **%d** | **%.1f**%s |',
            $totals['breaking'],
            $totals['issues'],
            $totals['hours'],
            $withCost ? ' | **' . $this->formatEuros((float) $totals['cost']) . '**' : '',
        );
        $lines[] = '';

        if ($withCost) {
            $lines[] = sprintf('*Budget calculé sur une base de %d h par jour, chaque projet arrondi à la demi-journée supérieure.*', CostEstimator::HOURS_PER_DAY);
            $lines[] = '';
        }

        if ($withServiceOffer) {
            $lines[] = (new MigrationServiceOffer())->markdownLine();
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Affiche le tableau comparatif dans le terminal.
     *
     * @param list<MigrationReport> $reports
     */
    private function renderConsole(array $reports, OutputInterface $output, ?int $dailyRate, bool $withServiceOffer): void
    {
        $withCost = $dailyRate !== null;
        $headers = ['Projet', 'Version', 'Complexité', 'Risque', 'Bloquants', 'Problèmes', 'Heures'];
        if ($withCost) {
            $headers[] = 'Budget';
        }

        $table = new Table($output);
        $table->setHeaders($headers);

        foreach ($this->buildRows($reports, $dailyRate) as $row) {
            $line = [
                $row['name'],
                sprintf('%s → %s', $row['version'] ?? '?', $row['target']),
                $row['complexity'],
                $row['risk'],
                $row['breaking'] > 0 ? sprintf('<fg=red>%d</>', $row['breaking']) : '0',
                $row['issues'],
                sprintf('%.1f h', $row['hours']),
            ];
            if ($withCost) {
                $line[] = $this->formatEuros((float) $row['cost']);
            }
            $table->addRow($line);
        }

        $totals = $this->buildTotals($reports, $dailyRate);
        $totalLine = ['<options=bold>Total</>', '', '', '', $totals['breaking'], $totals['issues'], sprintf('%.1f h', $totals['hours'])];
        if ($withCost) {
            $totalLine[] = $this->formatEuros((float) $totals['cost']);
        }
        $table->addRow(new TableSeparator());
        $table->addRow($totalLine);

        $table->render();

        if ($withCost) {
            $output->writeln(sprintf(
                '<comment>Budget calculé sur une base de %d h par jour (TJM %d €), chaque projet arrondi à la demi-journée supérieure.</comment>',
                CostEstimator::HOURS_PER_DAY,
                $dailyRate,
            ));
        }

        $offerLine = $withServiceOffer ? (new MigrationServiceOffer())->consoleLine($totals['hours']) : null;
        if ($offerLine !== null) {
            $output->writeln('');
            $output->writeln(sprintf('<comment>%s</comment>', $offerLine));
        }
    }

    /**
     * @param list<MigrationReport> $reports
     *
     * @throws PdfGenerationException
     */
    private function writePdf(array $reports, OutputInterface $output, ?string $outputFile, ?int $dailyRate, bool $withServiceOffer): void
    {
        $outputFile ??= self::DEFAULT_PDF_FILE;
        $pdf = $this->pdfRenderer->render($this->htmlBuilder->buildMultiProjectReport($reports, $dailyRate, $withServiceOffer));

        (new PdfReporter($this->htmlBuilder, $this->pdfRenderer))->write($outputFile, $pdf);

        $output->writeln(sprintf('<info>Rapport PDF consolidé généré dans %s</info>', $outputFile));
    }

    private function writeText(string $content, string $label, OutputInterface $output, ?string $outputFile): void
    {
        if ($outputFile === null) {
            $output->writeln($content);

            return;
        }

        $directory = dirname($outputFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($outputFile, $content);
        $output->writeln(sprintf('<info>Rapport %s consolidé généré dans %s</info>', $label, $outputFile));
    }

    /**
     * Calcule une ligne de synthèse par projet, partagée par tous les formats.
     *
     * @param list<MigrationReport> $reports
     *
     * @return list<array{name: string, version: string|null, target: string, complexity: string, risk: string, breaking: int, issues: int, hours: float, days: float|null, cost: float|null}>
     */
    private function buildRows(array $reports, ?int $dailyRate): array
    {
        $rows = [];

        foreach ($reports as $report) {
            $cost = $dailyRate !== null ? $this->costEstimator->estimate($report->getTotalEstimatedHours(), $dailyRate) : null;

            $rows[] = [
                'name' => $this->htmlBuilder->projectName($report),
                'version' => $report->getDetectedSyliusVersion(),
                'target' => $report->getTargetVersion(),
                'complexity' => $this->htmlBuilder->complexityLabel($report->getComplexity()),
                'risk' => $this->htmlBuilder->riskLabel($this->riskScorer->score($report)->level),
                'breaking' => count($report->getIssuesBySeverity(Severity::BREAKING)),
                'issues' => count($report->getIssues()),
                'hours' => $report->getTotalEstimatedHours(),
                'days' => $cost?->days,
                'cost' => $cost?->amount,
            ];
        }

        return $rows;
    }

    /**
     * Totaux consolidés. Le budget total est la somme des budgets par projet,
     * pour rester cohérent avec les montants affichés ligne par ligne.
     *
     * @param list<MigrationReport> $reports
     *
     * @return array{issues: int, breaking: int, hours: float, days: float|null, cost: float|null}
     */
    private function buildTotals(array $reports, ?int $dailyRate): array
    {
        $totals = ['issues' => 0, 'breaking' => 0, 'hours' => 0.0, 'days' => null, 'cost' => null];

        foreach ($this->buildRows($reports, $dailyRate) as $row) {
            $totals['issues'] += $row['issues'];
            $totals['breaking'] += $row['breaking'];
            $totals['hours'] += $row['hours'];

            if ($row['days'] !== null && $row['cost'] !== null) {
                $totals['days'] = ($totals['days'] ?? 0.0) + $row['days'];
                $totals['cost'] = ($totals['cost'] ?? 0.0) + $row['cost'];
            }
        }

        return $totals;
    }

    private function formatEuros(float $amount): string
    {
        return $this->htmlBuilder->euros($amount);
    }
}
