<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Gère la sauvegarde et le chargement de snapshots de rapports de migration (baseline).
 * Permet de comparer l'état actuel de la migration avec un état précédent
 * pour suivre la progression.
 */
final class BaselineStorage
{
    /**
     * Sauvegarde un snapshot du rapport de migration au format JSON.
     *
     * @param MigrationReport $report Rapport à sauvegarder
     * @param string          $path   Chemin du fichier de baseline
     */
    public function save(MigrationReport $report, string $path): void
    {
        $snapshot = $this->buildSnapshot($report);

        $json = json_encode($snapshot, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Impossible de sérialiser le snapshot en JSON.');
        }

        /* Création du répertoire parent si nécessaire */
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $json . "\n");
    }

    /**
     * Charge un snapshot précédemment sauvegardé.
     *
     * @param string $path Chemin du fichier de baseline
     *
     * @return array<string, mixed> Données du snapshot
     *
     * @throws \RuntimeException Si le fichier est introuvable ou invalide
     */
    public function load(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('Fichier de baseline introuvable : %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Impossible de lire le fichier de baseline : %s', $path));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Le fichier de baseline contient des données invalides : %s', $path));
        }

        return $data;
    }

    /**
     * Compare le rapport actuel avec une baseline et retourne les différences.
     *
     * @param MigrationReport $current      Rapport actuel
     * @param string          $baselinePath Chemin vers la baseline de référence
     *
     * @return array{resolved: list<array<string, mixed>>, new: list<array<string, mixed>>, progress_percent: float}
     */
    public function diff(MigrationReport $current, string $baselinePath): array
    {
        $baseline = $this->load($baselinePath);
        $baselineIssues = $baseline['issues'] ?? [];

        /* Construction d'un index des problèmes de la baseline par clé unique */
        $baselineIndex = [];
        foreach ($baselineIssues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $key = $this->buildIssueKey($issue);
            $baselineIndex[$key] = $issue;
        }

        /* Construction d'un index des problèmes actuels */
        $currentIssues = $this->flattenIssues($current);
        $currentIndex = [];
        foreach ($currentIssues as $issue) {
            $key = $this->buildIssueKey($issue);
            $currentIndex[$key] = $issue;
        }

        /* Problèmes résolus : présents dans la baseline mais absents du rapport actuel */
        $resolved = [];
        foreach ($baselineIndex as $key => $issue) {
            if (!isset($currentIndex[$key])) {
                $resolved[] = $issue;
            }
        }

        /* Nouveaux problèmes : absents de la baseline mais présents dans le rapport actuel */
        $new = [];
        foreach ($currentIndex as $key => $issue) {
            if (!isset($baselineIndex[$key])) {
                $new[] = $issue;
            }
        }

        /* Calcul du pourcentage de progression */
        $totalBaseline = count($baselineIndex);
        $resolvedCount = count($resolved);

        $progressPercent = $totalBaseline > 0
            ? round(($resolvedCount / $totalBaseline) * 100, 1)
            : 0.0;

        return [
            'resolved' => $resolved,
            'new' => $new,
            'progress_percent' => $progressPercent,
        ];
    }

    /**
     * Construit le snapshot complet du rapport pour la sauvegarde.
     *
     * @return array<string, mixed>
     */
    private function buildSnapshot(MigrationReport $report): array
    {
        return [
            'saved_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'meta' => [
                'version' => $report->getDetectedSyliusVersion(),
                'target_version' => $report->getTargetVersion(),
            ],
            'summary' => [
                'complexity' => $report->getComplexity()->value,
                'total_hours' => $report->getTotalEstimatedHours(),
                'issues_count' => count($report->getIssues()),
                'breaking_count' => count($report->getIssuesBySeverity(Severity::BREAKING)),
                'warning_count' => count($report->getIssuesBySeverity(Severity::WARNING)),
                'suggestion_count' => count($report->getIssuesBySeverity(Severity::SUGGESTION)),
            ],
            'issues' => $this->flattenIssues($report),
        ];
    }

    /**
     * Aplatit les problèmes du rapport en une liste de tableaux associatifs.
     *
     * @return list<array<string, mixed>>
     */
    private function flattenIssues(MigrationReport $report): array
    {
        $issues = [];

        foreach ($report->getIssues() as $issue) {
            $issues[] = [
                'severity' => $issue->getSeverity()->value,
                'category' => $issue->getCategory()->value,
                'analyzer' => $issue->getAnalyzer(),
                'message' => $issue->getMessage(),
                'file' => $issue->getFile(),
                'line' => $issue->getLine(),
            ];
        }

        return $issues;
    }

    /**
     * Génère une clé unique pour identifier un problème.
     * Utilise la combinaison analyseur + message + fichier + ligne.
     *
     * @param array<string, mixed> $issue Données du problème
     */
    private function buildIssueKey(array $issue): string
    {
        return sprintf(
            '%s|%s|%s|%s',
            $issue['analyzer'] ?? '',
            $issue['message'] ?? '',
            $issue['file'] ?? '',
            $issue['line'] ?? '',
        );
    }
}
