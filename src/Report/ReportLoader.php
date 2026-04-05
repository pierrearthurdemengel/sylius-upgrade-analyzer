<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Utilitaire de chargement et reconstruction de rapports depuis des fichiers JSON
 * ou des données brutes. Centralise la logique de désérialisation pour éviter
 * la duplication entre UploadCommand, CompareCommand et MultiAnalyzeCommand.
 */
final class ReportLoader
{
    /**
     * Charge un rapport depuis un fichier JSON.
     *
     * @throws \RuntimeException Si le fichier est introuvable ou invalide
     */
    public function loadFromFile(string $path): MigrationReport
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Fichier introuvable ou illisible : %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Impossible de lire le fichier : %s', $path));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Le fichier JSON est invalide : %s', $path));
        }

        return $this->rebuildFromArray($data);
    }

    /**
     * Reconstruit un objet MigrationReport à partir des données JSON décodées.
     *
     * @param array<string, mixed> $data Données du rapport
     */
    public function rebuildFromArray(array $data): MigrationReport
    {
        $meta = $data['meta'] ?? [];
        $analyzedAt = isset($meta['analyzed_at'])
            ? new \DateTimeImmutable($meta['analyzed_at'])
            : new \DateTimeImmutable();

        $report = new MigrationReport(
            startedAt: $analyzedAt,
            detectedSyliusVersion: $meta['version'] ?? null,
            targetVersion: $meta['target_version'] ?? '2.0',
            projectPath: '.',
        );

        /* Nom du projet si présent */
        if (isset($meta['project_name']) && is_string($meta['project_name'])) {
            $report->setProjectName($meta['project_name']);
        }

        /* Reconstruction des issues : format groupé par catégorie ou plat */
        $issues = $data['issues'] ?? [];
        foreach ($issues as $key => $value) {
            if (is_int($key) && is_array($value)) {
                /* Format plat : chaque élément est une issue */
                $this->addIssueFromArray($report, $value);
            } elseif (is_string($key) && is_array($value)) {
                /* Format groupé par catégorie */
                foreach ($value as $issueData) {
                    if (is_array($issueData)) {
                        $this->addIssueFromArray($report, $issueData);
                    }
                }
            }
        }

        $report->complete();

        return $report;
    }

    /**
     * Ajoute une issue au rapport depuis un tableau associatif.
     *
     * @param array<string, mixed> $issueData Données de l'issue
     */
    private function addIssueFromArray(MigrationReport $report, array $issueData): void
    {
        $severity = Severity::tryFrom($issueData['severity'] ?? '');
        $category = Category::tryFrom($issueData['category'] ?? '');

        if ($severity === null || $category === null) {
            return;
        }

        $report->addIssue(new MigrationIssue(
            severity: $severity,
            category: $category,
            analyzer: $issueData['analyzer'] ?? '',
            message: $issueData['message'] ?? '',
            detail: $issueData['detail'] ?? '',
            suggestion: $issueData['suggestion'] ?? '',
            file: $issueData['file'] ?? null,
            line: isset($issueData['line']) ? (int) $issueData['line'] : null,
            codeSnippet: $issueData['code_snippet'] ?? null,
            docUrl: $issueData['doc_url'] ?? null,
            estimatedMinutes: (int) ($issueData['estimated_minutes'] ?? 0),
        ));
    }
}
