<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Model;

/**
 * Rapport complet de migration regroupant tous les problèmes détectés.
 * Fournit des méthodes d'agrégation et de filtrage des résultats.
 */
final class MigrationReport
{
    /** @var list<MigrationIssue> Liste des problèmes détectés */
    private array $issues = [];

    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @param \DateTimeImmutable $startedAt             Horodatage du début de l'analyse
     * @param ?string           $detectedSyliusVersion  Version de Sylius détectée dans le projet
     * @param string            $targetVersion          Version cible de Sylius pour la migration
     * @param string            $projectPath            Chemin racine du projet analysé
     */
    public function __construct(
        private readonly \DateTimeImmutable $startedAt,
        private ?string $detectedSyliusVersion,
        private string $targetVersion,
        private readonly string $projectPath,
    ) {
    }

    /**
     * Ajoute un problème au rapport.
     */
    public function addIssue(MigrationIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    /**
     * Calcule la complexité globale de la migration.
     * Seuls les problèmes BREAKING et WARNING sont pris en compte.
     * Seuils : TRIVIAL < 20h, MODERATE < 80h, COMPLEX < 200h, MAJOR >= 200h
     */
    public function getComplexity(): Complexity
    {
        $totalHours = $this->getTotalEstimatedHours();

        return match (true) {
            $totalHours < 20.0 => Complexity::TRIVIAL,
            $totalHours < 80.0 => Complexity::MODERATE,
            $totalHours < 200.0 => Complexity::COMPLEX,
            default => Complexity::MAJOR,
        };
    }

    /**
     * Calcule le nombre total d'heures estimées, arrondi au demi-heure supérieur.
     * Seuls les problèmes BREAKING et WARNING sont comptabilisés.
     */
    public function getTotalEstimatedHours(): float
    {
        $totalMinutes = 0;

        foreach ($this->issues as $issue) {
            if ($issue->getSeverity() === Severity::BREAKING || $issue->getSeverity() === Severity::WARNING) {
                $totalMinutes += $issue->getEstimatedMinutes();
            }
        }

        /* Arrondi au demi-heure supérieur : on divise par 30, on arrondit au supérieur, puis on multiplie par 0.5 */
        return ceil($totalMinutes / 30.0) * 0.5;
    }

    /**
     * Retourne les heures estimées ventilées par catégorie.
     *
     * @return array<string, float> Tableau associatif catégorie => heures
     */
    public function getEstimatedHoursByCategory(): array
    {
        $minutesByCategory = [];

        foreach ($this->issues as $issue) {
            if ($issue->getSeverity() === Severity::BREAKING || $issue->getSeverity() === Severity::WARNING) {
                $categoryValue = $issue->getCategory()->value;

                if (!isset($minutesByCategory[$categoryValue])) {
                    $minutesByCategory[$categoryValue] = 0;
                }

                $minutesByCategory[$categoryValue] += $issue->getEstimatedMinutes();
            }
        }

        /* Conversion en heures, arrondi au demi-heure supérieur pour chaque catégorie */
        $hoursByCategory = [];
        foreach ($minutesByCategory as $category => $minutes) {
            $hoursByCategory[$category] = ceil($minutes / 30.0) * 0.5;
        }

        /* Tri décroissant par nombre d'heures */
        arsort($hoursByCategory);

        return $hoursByCategory;
    }

    /**
     * Retourne la liste complète des problèmes détectés.
     *
     * @return list<MigrationIssue>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Filtre les problèmes par niveau de sévérité.
     *
     * @return list<MigrationIssue>
     */
    public function getIssuesBySeverity(Severity $severity): array
    {
        return array_values(
            array_filter($this->issues, static fn (MigrationIssue $issue): bool => $issue->getSeverity() === $severity)
        );
    }

    /**
     * Filtre les problèmes par catégorie.
     *
     * @return list<MigrationIssue>
     */
    public function getIssuesByCategory(Category $category): array
    {
        return array_values(
            array_filter($this->issues, static fn (MigrationIssue $issue): bool => $issue->getCategory() === $category)
        );
    }

    /**
     * Marque le rapport comme terminé avec l'horodatage actuel.
     */
    public function complete(): void
    {
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getDetectedSyliusVersion(): ?string
    {
        return $this->detectedSyliusVersion;
    }

    public function setDetectedSyliusVersion(?string $detectedSyliusVersion): void
    {
        $this->detectedSyliusVersion = $detectedSyliusVersion;
    }

    public function getTargetVersion(): string
    {
        return $this->targetVersion;
    }

    public function setTargetVersion(string $targetVersion): void
    {
        $this->targetVersion = $targetVersion;
    }

    public function getProjectPath(): string
    {
        return $this->projectPath;
    }
}
