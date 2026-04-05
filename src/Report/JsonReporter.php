<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generateur de rapport au format JSON.
 * Produit un document JSON structure avec metadonnees, resume et liste detaillee des problemes.
 * Le JSON est formate de maniere lisible (pretty-print).
 */
final class JsonReporter implements ReporterInterface
{
    public function getFormat(): string
    {
        return 'json';
    }

    public function generate(MigrationReport $report, OutputInterface $output, array $context = []): void
    {
        $data = $this->buildReportData($report);

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $output->writeln('<error>Erreur lors de la generation du JSON.</error>');

            return;
        }

        /* Ecriture dans un fichier si un chemin de sortie est fourni dans le contexte */
        if (isset($context['output_file']) && is_string($context['output_file'])) {
            $outputFile = $context['output_file'];
            $directory = dirname($outputFile);

            /* Creation du repertoire de sortie si necessaire */
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($outputFile, $json . "\n");
            $output->writeln(sprintf(
                '<info>Rapport JSON genere dans %s</info>',
                $outputFile,
            ));

            return;
        }

        /* Sortie directe sur la console */
        $output->writeln($json);
    }

    /**
     * Construit la structure de donnees complete du rapport.
     * Publique pour permettre la réutilisation par les commandes multi-projets.
     *
     * @return array<string, mixed>
     */
    public function buildReportData(MigrationReport $report): array
    {
        return [
            'meta' => $this->buildMeta($report),
            'summary' => $this->buildSummary($report),
            'estimated_hours_by_category' => $report->getEstimatedHoursByCategory(),
            'issues' => $this->buildIssuesGroupedByCategory($report),
        ];
    }

    /**
     * Construit les metadonnees du rapport.
     *
     * @return array<string, mixed>
     */
    private function buildMeta(MigrationReport $report): array
    {
        /* Calcul de la duree d'analyse en secondes */
        $durationSeconds = null;
        if ($report->getCompletedAt() !== null) {
            $durationSeconds = $report->getCompletedAt()->getTimestamp()
                - $report->getStartedAt()->getTimestamp();
        }

        return [
            'project_name' => $report->getProjectName(),
            'version' => $report->getDetectedSyliusVersion(),
            'target_version' => $report->getTargetVersion(),
            'analysis_duration_seconds' => $durationSeconds,
            'analyzed_at' => $report->getStartedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Construit le resume du rapport.
     *
     * @return array<string, mixed>
     */
    private function buildSummary(MigrationReport $report): array
    {
        return [
            'complexity' => $report->getComplexity()->value,
            'total_hours' => $report->getTotalEstimatedHours(),
            'issues_count' => count($report->getIssues()),
            'breaking_count' => count($report->getIssuesBySeverity(Severity::BREAKING)),
            'warning_count' => count($report->getIssuesBySeverity(Severity::WARNING)),
            'suggestion_count' => count($report->getIssuesBySeverity(Severity::SUGGESTION)),
        ];
    }

    /**
     * Construit la liste des problemes regroupes par categorie.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildIssuesGroupedByCategory(MigrationReport $report): array
    {
        $grouped = [];

        /* Initialisation de chaque categorie avec un tableau vide */
        foreach (Category::cases() as $category) {
            $categoryIssues = $report->getIssuesByCategory($category);
            if (count($categoryIssues) === 0) {
                continue;
            }

            $grouped[$category->value] = array_map(
                fn (MigrationIssue $issue): array => $this->serializeIssue($issue),
                $categoryIssues,
            );
        }

        return $grouped;
    }

    /**
     * Serialise un probleme en tableau associatif pour l'export JSON.
     *
     * @return array<string, mixed>
     */
    private function serializeIssue(MigrationIssue $issue): array
    {
        return [
            'severity' => $issue->getSeverity()->value,
            'category' => $issue->getCategory()->value,
            'analyzer' => $issue->getAnalyzer(),
            'message' => $issue->getMessage(),
            'detail' => $issue->getDetail(),
            'suggestion' => $issue->getSuggestion(),
            'file' => $issue->getFile(),
            'line' => $issue->getLine(),
            'code_snippet' => $issue->getCodeSnippet(),
            'doc_url' => $issue->getDocUrl(),
            'estimated_minutes' => $issue->getEstimatedMinutes(),
        ];
    }
}
