<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Exception\LicenseExpiredException;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\ServiceUnavailableException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoie un rapport de migration vers le service distant pour génération de PDF.
 */
final class ReportUploader
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $serviceUrl = 'https://api.sylius-upgrade-analyzer.dev',
    ) {
    }

    /**
     * Envoie le rapport au service distant et retourne l'URL du PDF généré.
     *
     * @param MigrationReport $report Rapport de migration à envoyer
     * @param string          $apiKey Clé d'API pour l'authentification
     *
     * @return string URL du PDF généré
     *
     * @throws LicenseExpiredException    Si la clé API est invalide ou expirée (401/403)
     * @throws ServiceUnavailableException Si le service est indisponible (5xx ou erreur réseau)
     */
    public function upload(MigrationReport $report, string $apiKey): string
    {
        $data = $this->serializeReport($report);

        $json = json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ServiceUnavailableException('Impossible de sérialiser le rapport en JSON.');
        }

        try {
            $response = $this->httpClient->request('POST', $this->serviceUrl . '/v1/reports', [
                'headers' => [
                    'X-Api-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => $json,
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (\Throwable $exception) {
            throw new ServiceUnavailableException(
                sprintf('Erreur réseau lors de l\'envoi du rapport : %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        /* Gestion des erreurs d'authentification */
        if ($statusCode === 401 || $statusCode === 403) {
            throw new LicenseExpiredException(
                'Clé API invalide ou licence expirée. Vérifiez votre clé API.',
            );
        }

        /* Gestion des erreurs serveur */
        if ($statusCode >= 500) {
            throw new ServiceUnavailableException(
                sprintf('Le service a retourné une erreur %d. Veuillez réessayer ultérieurement.', $statusCode),
            );
        }

        /* Extraction de l'URL du PDF depuis la réponse */
        try {
            $responseData = $response->toArray();
        } catch (\Throwable $exception) {
            throw new ServiceUnavailableException(
                sprintf('Réponse invalide du service : %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!isset($responseData['pdf_url']) || !is_string($responseData['pdf_url'])) {
            throw new ServiceUnavailableException(
                'La réponse du service ne contient pas l\'URL du PDF.',
            );
        }

        return $responseData['pdf_url'];
    }

    /**
     * Télécharge le PDF depuis l'URL fournie et le sauvegarde sur le disque.
     *
     * @param string $pdfUrl     URL du PDF à télécharger
     * @param string $outputPath Chemin de destination du fichier PDF
     *
     * @throws ServiceUnavailableException Si le téléchargement échoue
     */
    public function downloadPdf(string $pdfUrl, string $outputPath): void
    {
        try {
            $response = $this->httpClient->request('GET', $pdfUrl, [
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                throw new ServiceUnavailableException(
                    sprintf('Échec du téléchargement du PDF (HTTP %d).', $statusCode),
                );
            }

            $content = $response->getContent();
        } catch (ServiceUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ServiceUnavailableException(
                sprintf('Erreur lors du téléchargement du PDF : %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        /* Création du répertoire de destination si nécessaire */
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $result = file_put_contents($outputPath, $content);
        if ($result === false) {
            throw new ServiceUnavailableException(
                sprintf('Impossible d\'écrire le fichier PDF dans %s.', $outputPath),
            );
        }
    }

    /**
     * Sérialise le rapport de migration en tableau associatif,
     * identique au format produit par JsonReporter.
     *
     * @return array<string, mixed>
     */
    private function serializeReport(MigrationReport $report): array
    {
        /* Calcul de la durée d'analyse en secondes */
        $durationSeconds = null;
        if ($report->getCompletedAt() !== null) {
            $durationSeconds = $report->getCompletedAt()->getTimestamp()
                - $report->getStartedAt()->getTimestamp();
        }

        return [
            'meta' => [
                'project_name' => $report->getProjectName(),
                'version' => $report->getDetectedSyliusVersion(),
                'target_version' => $report->getTargetVersion(),
                'analysis_duration_seconds' => $durationSeconds,
                'analyzed_at' => $report->getStartedAt()->format(\DateTimeInterface::ATOM),
            ],
            'summary' => [
                'complexity' => $report->getComplexity()->value,
                'total_hours' => $report->getTotalEstimatedHours(),
                'issues_count' => count($report->getIssues()),
                'breaking_count' => count($report->getIssuesBySeverity(Severity::BREAKING)),
                'warning_count' => count($report->getIssuesBySeverity(Severity::WARNING)),
                'suggestion_count' => count($report->getIssuesBySeverity(Severity::SUGGESTION)),
            ],
            'estimated_hours_by_category' => $report->getEstimatedHoursByCategory(),
            'issues' => $this->serializeIssuesGroupedByCategory($report),
        ];
    }

    /**
     * Sérialise les problèmes regroupés par catégorie.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function serializeIssuesGroupedByCategory(MigrationReport $report): array
    {
        $grouped = [];

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
     * Sérialise un problème individuel en tableau associatif.
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
