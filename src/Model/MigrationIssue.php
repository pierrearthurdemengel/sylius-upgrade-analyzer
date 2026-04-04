<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Model;

/**
 * Représente un problème individuel détecté lors de l'analyse de migration.
 * Objet immuable contenant toutes les informations relatives à un problème.
 */
final readonly class MigrationIssue
{
    /**
     * @param Severity $severity      Niveau de sévérité du problème
     * @param Category $category      Catégorie du problème
     * @param string   $analyzer      Nom de l'analyseur ayant détecté le problème
     * @param string   $message       Message principal décrivant le problème
     * @param string   $detail        Détail technique du problème
     * @param string   $suggestion    Suggestion de correction
     * @param ?string  $file          Chemin du fichier concerné
     * @param ?int     $line          Numéro de ligne dans le fichier
     * @param ?string  $codeSnippet   Extrait de code concerné
     * @param ?string  $docUrl        URL vers la documentation pertinente
     * @param int      $estimatedMinutes Estimation du temps de correction en minutes
     */
    public function __construct(
        private Severity $severity,
        private Category $category,
        private string $analyzer,
        private string $message,
        private string $detail,
        private string $suggestion,
        private ?string $file = null,
        private ?int $line = null,
        private ?string $codeSnippet = null,
        private ?string $docUrl = null,
        private int $estimatedMinutes = 0,
    ) {
    }

    public function getSeverity(): Severity
    {
        return $this->severity;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getAnalyzer(): string
    {
        return $this->analyzer;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getSuggestion(): string
    {
        return $this->suggestion;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    public function getCodeSnippet(): ?string
    {
        return $this->codeSnippet;
    }

    public function getDocUrl(): ?string
    {
        return $this->docUrl;
    }

    public function getEstimatedMinutes(): int
    {
        return $this->estimatedMinutes;
    }
}
